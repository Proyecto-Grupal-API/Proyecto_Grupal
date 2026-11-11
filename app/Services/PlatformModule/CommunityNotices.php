<?php

namespace App\Services\PlatformModule;

use App\Models\PlatformModule\CommunityNotice;
use App\Models\PlatformModule\PointsLedger;
use App\Models\PlatformModule\Redemption;
use App\Models\PlatformModule\RedemptionEvent;
use App\Services\ClienteModuloHttp;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use MongoDB\BSON\UTCDateTime;
use Throwable;

/** Reads E7 facts only. No business writes or transaction spans OAuth/GET/POST. */
final class CommunityNotices
{
    private const PATH = '/api/v1/comunidad/notificaciones';
    private const CANJES = ['confirmed' => 'canje_confirmado', 'delivered' => 'canje_entregado', 'cancelled' => 'canje_cancelado'];
    private const ARRAY_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    /** At most $limit new notices and max(100, $limit * 10) reads per source, no HTTP. */
    public function scan(int $limit = 30): array
    {
        $counts = ['scanned' => 0, 'queued' => 0, 'invalid' => 0];
        $since = $this->preflight($limit);
        if ($since === null) {
            return $counts;
        }
        $notices = DB::connection('platform')->getCollection(CommunityNotice::COLLECTION);
        $scans = DB::connection('platform')->getCollection(CommunityNotice::SCANS);
        foreach (['points', 'redemptions'] as $source) {
            $stamp = $since->format('Y-m-d\TH:i:s.u\Z');
            $state = $scans->findOne(['_id' => $source], ['typeMap' => self::ARRAY_MAP]);
            if (! $state || $state['since'] !== $stamp || $state['after_id'] >= $state['through_id']) {
                $through = $this->candidates($source, $since)->orderByDesc('_id')->first()?->getKey();
                if ($through === null) {
                    continue;
                }
                $state = ['since' => $stamp, 'after_id' => '', 'through_id' => (string) $through];
            }
            $read = $queued = 0;
            $cap = max(100, $limit * 10);
            // ponytail: finite ID rounds revisit repaired/lower-ID facts; use CDC if course-scale scans grow expensive.
            while ($read < $cap && $queued < $limit) {
                $rows = $this->candidates($source, $since)->where('_id', '<=', $state['through_id'])
                    ->when($state['after_id'] !== '', fn ($q) => $q->where('_id', '>', $state['after_id']))
                    ->orderBy('_id')->limit(min(100, $cap - $read))->get();
                if ($rows->isEmpty()) {
                    $state['after_id'] = $state['through_id'];
                    break;
                }
                foreach ($rows as $row) {
                    ++$read;
                    ++$counts['scanned'];
                    $id = (string) $row->getKey();
                    $state['after_id'] = $id;
                    $status = $row->getRawOriginal('to_status');
                    $type = $source === 'points' ? 'puntos_acreditados'
                        : (is_string($status) ? (self::CANJES[$status] ?? null) : null);
                    if ($type === null) {
                        ++$counts['invalid'];
                        continue;
                    }
                    $key = 'platform-community:'.$type.':'.$id;
                    if ($notices->findOne(['idempotency_key' => $key], ['projection' => ['_id' => 1]])) {
                        continue;
                    }
                    $payload = $this->payload($row);
                    if ($payload === null) {
                        ++$counts['invalid'];
                        continue;
                    }
                    $now = new UTCDateTime(now());
                    try {
                        // Insert-only payload: concurrent scanners and future rounds cannot rewrite it.
                        $result = $notices->updateOne(['idempotency_key' => $key], ['$setOnInsert' => [
                            'tipo' => $type, 'source_id' => $id, 'idempotency_key' => $key, 'payload' => $payload,
                            'status' => 'PENDING', 'attempts' => 0, 'next_attempt_at' => $now,
                            'locked_until' => null, 'lease_token' => null, 'last_error' => null,
                            'created_at' => $now, 'updated_at' => $now,
                        ]], ['upsert' => true]);
                        $queued += $result->getUpsertedCount();
                        $counts['queued'] += $result->getUpsertedCount();
                    } catch (\MongoDB\Driver\Exception\BulkWriteException $error) {
                        if ($error->getCode() !== 11000 || ! $notices->findOne(['idempotency_key' => $key])) {
                            throw $error;
                        }
                    }
                    if ($queued >= $limit) {
                        break;
                    }
                }
            }
            unset($state['_id']);
            $scans->updateOne(['_id' => $source], ['$set' => $state], ['upsert' => true]);
        }

        return $counts;
    }

    /** Due claims are atomic; an expired worker cannot finish a newer worker's lease. */
    public function deliver(int $limit = 30): array
    {
        $counts = ['attempted' => 0, 'delivered' => 0, 'omitted' => 0, 'pending' => 0];
        if ($this->preflight($limit) === null) {
            return $counts;
        }
        $table = DB::connection('platform')->getCollection(CommunityNotice::COLLECTION);
        $due = ['status' => 'PENDING', 'next_attempt_at' => ['$lte' => new UTCDateTime(now())],
            '$or' => [['locked_until' => null], ['locked_until' => ['$lte' => new UTCDateTime(now())]]]];
        $rows = $table->find($due, ['sort' => ['next_attempt_at' => 1, '_id' => 1], 'limit' => $limit, 'typeMap' => self::ARRAY_MAP]);
        foreach ($rows as $row) {
            $token = bin2hex(random_bytes(16));
            $now = new UTCDateTime(now());
            $attempt = $row['attempts'] + 1;
            $claim = $table->updateOne($due + ['_id' => $row['_id'], 'updated_at' => $row['updated_at'], 'attempts' => $row['attempts']],
                ['$set' => ['lease_token' => $token, 'locked_until' => new UTCDateTime(now()->addMinutes(2)),
                    'updated_at' => $now, 'next_attempt_at' => new UTCDateTime(now()->addSeconds(min(3600, 30 * (2 ** min(7, $attempt - 1)))))],
                    '$inc' => ['attempts' => 1]]);
            if ($claim->getModifiedCount() !== 1) {
                continue;
            }
            ++$counts['attempted'];
            $ack = null;
            $error = null;
            try {
                $response = app(ClienteModuloHttp::class)->para('platform_notices', 'notifications:read')->withoutRedirecting()
                    ->get(self::PATH, ['clave_idempotencia' => $row['idempotency_key']]);
                $body = $response->json();
                if ($response->status() === 404 && is_array($body) && ($body['codigo'] ?? null) === 'no_encontrada'
                    && $this->matchesKey($body, $row['idempotency_key'])) {
                    $response = app(ClienteModuloHttp::class)->para('platform_notices', 'notifications:send')->withoutRedirecting()
                        ->withHeaders(['Idempotency-Key' => $row['idempotency_key']])->post(self::PATH, $row['payload']);
                }
                $ack = $this->acknowledgement($response, $row['idempotency_key']);
                if ($ack === null) {
                    $error = 'INVALID_RECEIPT_HTTP_'.$response->status();
                }
            } catch (Throwable) {
                // Neither exception text nor response metadata belongs in the outbox/logs.
                $error = 'TRANSPORT_UNAVAILABLE';
            }
            $outcome = $ack === null ? 'pending' : ($ack['estado'] === 'entregada' ? 'delivered' : 'omitted');
            $changes = ['lease_token' => null, 'locked_until' => null, 'last_error' => $error, 'updated_at' => new UTCDateTime(now())];
            if ($ack !== null) {
                $changes += ['status' => $ack['estado'] === 'entregada' ? 'DELIVERED' : 'OMITTED',
                    'notification_id' => $ack['notificacion_id'], 'delivered_at' => new UTCDateTime(now())];
            }
            $saved = $table->updateOne(['_id' => $row['_id'], 'status' => 'PENDING', 'lease_token' => $token, 'updated_at' => $now], ['$set' => $changes]);
            ++$counts[$saved->getMatchedCount() === 1 ? $outcome : 'pending'];
        }

        return $counts;
    }

    private function candidates(string $source, DateTimeImmutable $since): Builder
    {
        $query = $source === 'points' ? PointsLedger::where('type', 'earn')
            : RedemptionEvent::whereIn('to_status', array_keys(self::CANJES));

        return $query->where('created_at', '>=', new UTCDateTime($since))->where('created_at', '<=', now());
    }

    private function payload(PointsLedger|RedemptionEvent $fact): ?array
    {
        $id = (string) $fact->getKey();
        if (! $this->mongoId($id)) {
            return null;
        }
        if ($fact instanceof PointsLedger) {
            $amount = $fact->getRawOriginal('amount');
            $student = $fact->getRawOriginal('student_id');
            if ($fact->type !== 'earn' || ! is_int($amount) || $amount <= 0) {
                return null;
            }
            $type = 'puntos_acreditados';
            $destination = 'mi_actividad_recompensas';
            $subject = 'Puntos acreditados';
            $text = 'Se acreditaron '.$amount.' puntos a tu actividad de recompensas. Consulta el movimiento para ver los detalles.';
        } else {
            $redemptionId = $fact->getRawOriginal('redemption_id');
            if (! $this->mongoId($redemptionId) || ! isset(self::CANJES[$fact->to_status])) {
                return null;
            }
            $redemption = Redemption::find(strtolower($redemptionId));
            if ($redemption === null) {
                return null;
            }
            $student = $redemption->getRawOriginal('student_id');
            $id = strtolower($redemptionId);
            $type = self::CANJES[$fact->to_status];
            $destination = 'mi_canje_recompensas';
            [$subject, $text] = match ($fact->to_status) {
                'confirmed' => ['Canje confirmado', 'Tu canje fue confirmado. Consulta sus detalles.'],
                'delivered' => ['Canje entregado', 'Tu canje fue entregado. Consulta sus detalles.'],
                'cancelled' => ['Canje cancelado', 'Tu canje fue cancelado. Consulta sus detalles.'],
            };
        }
        if (! $this->mongoId($student)) {
            return null;
        }

        return ['destinatario_id' => strtolower($student), 'tipo' => $type, 'asunto' => $subject, 'texto' => $text,
            'accion' => ['destino' => $destination, 'id' => $id], 'importancia' => 'normal'];
    }

    private function preflight(int $limit): ?DateTimeImmutable
    {
        if (config('platform.notices.enabled', false) !== true) {
            return null;
        }
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('El límite debe ser un entero entre 1 y 100.');
        }
        $raw = config('platform.notices.since');
        if (! is_string($raw) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:(?:0[0-9]|1[0-3]):[0-5][0-9]|14:00))\z/', $raw)) {
            throw new InvalidArgumentException('Configura platform.notices.since como instante ISO 8601 válido con zona horaria.');
        }
        try {
            $since = new DateTimeImmutable($raw);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
                throw new InvalidArgumentException;
            }
        } catch (Throwable) {
            throw new InvalidArgumentException('platform.notices.since no es una fecha válida.');
        }
        foreach (['url', 'client_id', 'client_secret'] as $field) {
            $value = config('platform.notices.'.$field);
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('Falta configurar platform.notices.'.$field.'.');
            }
        }
        $parts = parse_url(config('platform.notices.url'));
        if (! is_array($parts) || ! isset($parts['host'], $parts['scheme'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || ! ($parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && app()->environment(['local', 'testing'])
                && in_array($parts['host'], ['localhost', '127.0.0.1', 'app'], true)))) {
            throw new InvalidArgumentException('platform.notices.url debe ser un origen HTTPS válido.');
        }
        $connection = DB::connection('platform');
        if ($connection->transactionLevel() !== 0 || $connection->getSession()?->isInTransaction()) {
            throw new LogicException('Ejecuta los avisos fuera de una transacción de Plataforma.');
        }

        return $since->setTimezone(new DateTimeZone('UTC'));
    }

    private function mongoId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A[0-9a-fA-F]{24}\z/', $id) === 1;
    }

    private function matchesKey(array $body, string $key): bool
    {
        foreach (['clave_idempotencia', 'idempotency_key'] as $field) {
            if (array_key_exists($field, $body) && $body[$field] !== $key) {
                return false;
            }
        }

        return true;
    }

    private function acknowledgement(Response $response, string $key): ?array
    {
        $body = $response->json();
        if (! in_array($response->status(), [200, 201], true) || ! is_array($body)
            || ! $this->mongoId($body['notificacion_id'] ?? null)
            || ! in_array($body['estado'] ?? null, ['entregada', 'omitida'], true) || ! $this->matchesKey($body, $key)) {
            return null;
        }

        return ['notificacion_id' => strtolower($body['notificacion_id']), 'estado' => $body['estado']];
    }
}
