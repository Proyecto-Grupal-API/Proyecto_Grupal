<?php

namespace App\Domains\Financial\Integrations\Team4;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Local operational tools; no financial mutation, HTTP delivery, or web authorization. */
final class Team4OutboxAdministration
{
    private const STATES = ['PENDING', 'RETRY', 'SENDING', 'REVIEW', 'EXHAUSTED', 'DELIVERED'];

    public function listing(?string $state = null, ?string $payment = null, int $limit = 20, ?int $beforeId = null): array
    {
        if ($state !== null && !in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException('Estado de envio no valido.');
        }
        if ($limit < 1 || $limit > 100 || ($beforeId !== null && $beforeId < 1)) {
            throw new InvalidArgumentException('Limite 1..100; cursor positivo.');
        }
        if ($payment !== null) $this->text($payment, 1, 100);
        $query = $this->query();
        if ($state !== null) $query->where('o.state', $state);
        if ($payment !== null) $query->where('s.payment_intent_id', $payment);
        if ($beforeId !== null) $query->where('o.id', '<', $beforeId);
        $rows = $query->orderByDesc('o.id')->limit($limit + 1)->get($this->columns())->all();
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $mapped = array_map(fn ($row) => $this->summary($row), $rows);
        return ['events' => $mapped, 'next_before_id' => $hasMore ? end($mapped)['id'] : null];
    }

    public function show(string $eventId): array
    {
        $eventId = $this->uuid($eventId);
        $row = $this->query()->where('o.event_id', $eventId)->first([...$this->columns(), 'o.body']);
        if (!$row) throw new NotFoundHttpException('Evento no encontrado.');
        $result = $this->summary($row);
        // Retain exact serialized bytes; parsing money into floats is unnecessary here.
        $result['stored_body'] = $row->body;
        $result['integrity_ok'] = hash_equals($row->body_hash, hash('sha256', $row->body));
        $result['actions'] = DB::connection('sqlsrv')->table('financial_team4_operator_actions')
            ->where('event_id', $eventId)->orderBy('id')->get([
                'action_id', 'action', 'operator_label', 'reason', 'from_state', 'to_state',
                'attempts', 'previous_code', 'body_hash', 'application_environment', 'created_at',
            ])->all();
        return $result;
    }

    public function quarantine(string $eventId, string $bodyHash, string $actionId, string $operator, string $reason): array
    {
        $eventId = $this->uuid($eventId);
        $actionId = $this->uuid($actionId);
        $operator = trim($operator);
        $reason = trim($reason);
        $this->text($operator, 3, 100);
        $this->text($reason, 10, 1000);
        if (!preg_match('/^[a-f0-9]{64}$/D', $bodyHash)) {
            throw new InvalidArgumentException('body-hash debe ser el SHA256 mostrado por show.');
        }
        // Initial scope: development/test evidence only. No new institutional roles are invented.
        if (!app()->environment(['local', 'testing'])) {
            throw new ConflictHttpException('La cuarentena de esta entrega solo se permite en local/testing.');
        }
        $fingerprint = hash('sha256', json_encode([$eventId, $bodyHash, $operator, $reason, 'QUARANTINE'], JSON_THROW_ON_ERROR));
        $db = DB::connection('sqlsrv');
        try {
            return $db->transaction(function () use ($db, $eventId, $bodyHash, $actionId, $operator, $reason, $fingerprint) {
                $locator = $db->table('financial_team4_outbox')->where('event_id', $eventId)->first(['stream_id']);
                if (!$locator) throw new NotFoundHttpException('Evento no encontrado.');
                // Same order as the sender: stream first, then event. No skipping or ACK fabrication.
                $stream = $db->table('financial_team4_streams')->where('id', $locator->stream_id)->lockForUpdate()->first();
                if (!$stream) throw new ConflictHttpException('El evento no tiene un flujo valido.');
                $row = $db->table('financial_team4_outbox')->where('event_id', $eventId)->lockForUpdate()->first();
                if (!$row || (string) $row->stream_id !== (string) $stream->id) {
                    throw new ConflictHttpException('El evento cambio; vuelve a consultarlo.');
                }
                $previous = $db->table('financial_team4_operator_actions')->where('action_id', $actionId)->first();
                if ($previous) return $this->replay($previous, $fingerprint);
                if ($row->state !== 'PENDING' || (int) $row->attempts !== 0 || $row->delivered_at !== null
                    || $row->lease_token !== null || $row->lease_until !== null
                    || $db->table('financial_team4_attempts')->where('event_id', $eventId)->exists()) {
                    throw new ConflictHttpException('Solo se apartan eventos PENDING sin intentos, entrega ni lease.');
                }
                if (!hash_equals($row->body_hash, $bodyHash) || !hash_equals($bodyHash, hash('sha256', $row->body))) {
                    throw new ConflictHttpException('El contenido no coincide con el revisado. No se modifico.');
                }
                $db->table('financial_team4_operator_actions')->insert([
                    'action_id' => $actionId, 'event_id' => $eventId, 'action' => 'QUARANTINE',
                    'request_hash' => $fingerprint, 'operator_label' => $operator, 'reason' => $reason,
                    'body_hash' => $row->body_hash, 'from_state' => $row->state, 'to_state' => 'REVIEW',
                    'attempts' => (int) $row->attempts, 'previous_code' => $row->last_code,
                    'application_environment' => app()->environment(), 'created_at' => now(),
                ]);
                // REVIEW already blocks this event and every later event of this payment in the existing sender.
                $changed = $db->table('financial_team4_outbox')->where('id', $row->id)->where('state', 'PENDING')
                    ->where('attempts', 0)->update(['state' => 'REVIEW', 'last_code' => 'OPERATOR_QUARANTINE', 'updated_at' => now()]);
                if ($changed !== 1) throw new ConflictHttpException('El evento cambio; vuelve a consultarlo.');
                return ['action_id' => $actionId, 'event_id' => $eventId, 'state' => 'REVIEW', 'idempotent' => false];
            });
        } catch (UniqueConstraintViolationException $e) {
            // Competing identical action IDs on different streams: no partial update survives.
            $previous = $db->table('financial_team4_operator_actions')->where('action_id', $actionId)->first();
            if ($previous) return $this->replay($previous, $fingerprint);
            throw $e;
        }
    }

    private function replay(object $previous, string $fingerprint): array
    {
        if (!hash_equals($previous->request_hash, $fingerprint)) {
            throw new ConflictHttpException('action-id ya pertenece a otra accion o contenido.');
        }
        return ['action_id' => strtolower($previous->action_id), 'event_id' => strtolower($previous->event_id),
            'state' => $previous->to_state, 'idempotent' => true];
    }

    private function uuid(string $value): string
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $value)) {
            throw new InvalidArgumentException('Se requiere un UUID de evento/accion valido.');
        }
        return strtolower($value);
    }

    private function text(string $value, int $min, int $max): void
    {
        if (mb_strlen(trim($value)) < $min || mb_strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Texto vacio, demasiado largo o con caracteres de control.');
        }
    }

    private function query(): \Illuminate\Database\Query\Builder
    {
        return DB::connection('sqlsrv')->table('financial_team4_outbox as o')
            ->join('financial_team4_streams as s', 's.id', '=', 'o.stream_id');
    }

    private function columns(): array
    {
        return ['o.id', 'o.event_id', 'o.event_version', 'o.state', 'o.attempts', 'o.body_hash',
            'o.last_code', 'o.available_at', 'o.delivered_at', 'o.lease_until', 'o.updated_at',
            's.payment_intent_id', 's.order_id', 's.business_id'];
    }

    private function summary(object $row): array
    {
        $data = (array) $row;
        unset($data['body']);
        $data['event_id'] = strtolower($row->event_id);
        $data['event_version'] = (int) $row->event_version;
        $data['attempts'] = (int) $row->attempts;
        $data['id'] = (int) $row->id;
        return $data;
    }
}
