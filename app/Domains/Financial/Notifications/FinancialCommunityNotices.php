<?php

namespace App\Domains\Financial\Notifications;

use App\Domains\Financial\Models\Bonus;
use App\Domains\Financial\Models\BonusBudgetCommitment;
use App\Domains\Financial\Models\EventPaymentRequest;
use App\Domains\Financial\Models\FinancialRefundRequest;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\InstitutionalBudgetOperation;
use App\Services\ClienteModuloHttp;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

/** SQL scan and HTTP delivery are separate phases; neither changes payment state. */
final class FinancialCommunityNotices
{
    private const PATH = '/api/v1/comunidad/notificaciones';
    private const TYPES = [FinancialCommunityNoticePayload::PAYMENT, FinancialCommunityNoticePayload::REFUND, FinancialCommunityNoticePayload::BECA];

    public function __construct(private readonly ClienteModuloHttp $http) {}

    /** At most $limit candidates per type, including invalid evidence; no HTTP. */
    public function scan(int $limit = 30): array
    {
        $counts = ['scanned' => 0, 'queued' => 0, 'invalid' => 0];
        $since = $this->preflight($limit);
        if ($since === null) {
            return $counts;
        }
        foreach (self::TYPES as $type) {
            // firstOrCreate handles concurrent creation using the unique primary key.
            $cursor = FinancialCommunityNoticeScan::firstOrCreate(['tipo' => $type], ['since' => $since->format('Y-m-d\TH:i:s.u\Z')]);
            $batch = DB::connection('sqlsrv')->transaction(function () use ($cursor, $since, $type, $limit) {
                $cursor = FinancialCommunityNoticeScan::whereKey($cursor->getKey())->lockForUpdate()->firstOrFail();
                $query = $this->candidates($type, $since);
                if ($cursor->since !== $since->format('Y-m-d\TH:i:s.u\Z') || $cursor->after_id >= $cursor->through_id) {
                    // A finite round also revisits lower IDs confirmed or repaired later.
                    $cursor->fill(['since' => $since->format('Y-m-d\TH:i:s.u\Z'), 'after_id' => 0, 'through_id' => (int) (clone $query)->max('id')]);
                }
                $rows = $query->where('id', '>', $cursor->after_id)->where('id', '<=', $cursor->through_id)
                    ->orderBy('id')->limit($limit)->get();
                $result = ['scanned' => 0, 'queued' => 0, 'invalid' => 0];
                foreach ($rows as $row) {
                    ++$result['scanned'];
                    $cursor->after_id = $row->getKey();
                    $field = match ($type) {
                        FinancialCommunityNoticePayload::PAYMENT => 'confirmed_at',
                        FinancialCommunityNoticePayload::REFUND => 'completed_at',
                        FinancialCommunityNoticePayload::BECA => 'created_at',
                    };
                    try {
                        $occurred = new DateTimeImmutable($row->getRawOriginal($field), new DateTimeZone('UTC'));
                        $errors = DateTimeImmutable::getLastErrors();
                        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
                            throw new InvalidArgumentException;
                        }
                    } catch (\Exception) {
                        ++$result['invalid'];
                        continue;
                    }
                    if ($occurred < $since) {
                        continue;
                    }
                    $payload = $this->payload($type, $row);
                    if ($payload === null) {
                        ++$result['invalid'];
                        continue;
                    }
                    $operation = $type === FinancialCommunityNoticePayload::BECA
                        ? strtolower($row->issue_operation_id)
                        : strtolower($type === FinancialCommunityNoticePayload::PAYMENT ? $row->transaction_id : $row->financial_transaction_id);
                    $notice = FinancialCommunityNotice::firstOrCreate(['tipo' => $type, 'operation_id' => $operation], [
                        'idempotency_key' => $payload['clave_idempotencia'], 'payload' => $payload,
                        'status' => 'PENDING', 'attempts' => 0, 'next_attempt_at' => Carbon::now('UTC'),
                    ]);
                    $result['queued'] += (int) $notice->wasRecentlyCreated;
                }
                if ($rows->isEmpty()) {
                    $cursor->after_id = $cursor->through_id;
                }
                $cursor->save();

                return $result;
            }, 3);
            foreach ($counts as $name => $count) {
                $counts[$name] += $batch[$name];
            }
        }

        return $counts;
    }

    /** At most $limit due rows. Expired claims recover after a crashed worker. */
    public function deliver(int $limit = 30): array
    {
        $counts = ['attempted' => 0, 'delivered' => 0, 'omitted' => 0, 'pending' => 0];
        if ($this->preflight($limit) === null) {
            return $counts;
        }
        $rows = $this->due()->orderBy('next_attempt_at')->orderBy('id')->limit($limit)->get();
        foreach ($rows as $row) {
            $token = (string) Str::uuid();
            $attempt = $row->attempts + 1;
            $now = Carbon::now('UTC');
            // Compare-and-set claim; no SQL transaction spans OAuth/GET/POST.
            $claimed = $this->due()->whereKey($row->getKey())->where('attempts', $row->attempts)->update([
                'attempts' => $attempt, 'lease_token' => $token, 'locked_until' => $now->copy()->addMinutes(2),
                'next_attempt_at' => $now->copy()->addSeconds(min(3600, 30 * (2 ** min(7, $attempt - 1)))),
                'updated_at' => $now,
            ]);
            if (! $claimed) {
                continue;
            }
            ++$counts['attempted'];
            $outcome = 'pending';
            $error = null;
            $ack = null;
            try {
                $response = $this->http->para('financial_community_notices', 'notifications:read')
                    ->get(self::PATH, ['clave_idempotencia' => $row->idempotency_key]);
                $method = 'GET';
                if ($response->notFound()) {
                    $method = 'POST';
                    $response = $this->http->para('financial_community_notices', 'notifications:send')
                        ->withHeaders(['Idempotency-Key' => $row->idempotency_key])->post(self::PATH, $row->payload);
                }
                if (! $response->successful()) {
                    $error = $method.'_HTTP_'.$response->status();
                } elseif (($ack = $this->acknowledgement($response, $row->idempotency_key)) === null) {
                    $error = $method.'_INVALID_RESPONSE';
                } else {
                    $outcome = $ack['estado'] === 'entregada' ? 'delivered' : 'omitted';
                }
            } catch (Throwable) {
                // Exception text and response bodies can contain OAuth secrets. Never persist them.
                $error = 'TRANSPORT_UNAVAILABLE';
            }
            $changes = ['lease_token' => null, 'locked_until' => null, 'last_error' => $error, 'updated_at' => Carbon::now('UTC')];
            if ($outcome !== 'pending') {
                $changes += ['status' => $outcome === 'delivered' ? 'DELIVERED' : 'OMITTED',
                    'notification_id' => $ack['notificacion_id'], 'delivered_at' => Carbon::now('UTC')];
            }
            // An expired worker cannot acknowledge another worker's claim.
            $saved = FinancialCommunityNotice::whereKey($row->getKey())->where('lease_token', $token)->update($changes);
            ++$counts[$saved ? $outcome : 'pending'];
        }

        return $counts;
    }

    private function preflight(int $limit): ?DateTimeImmutable
    {
        if (config('financial_community_notices.enabled') !== true) {
            return null;
        }
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('El límite debe estar entre 1 y 500.');
        }
        $raw = config('financial_community_notices.since');
        if (! is_string($raw) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/', $raw)) {
            throw new InvalidArgumentException('Configura FINANCIAL_COMMUNITY_NOTICES_SINCE como instante ISO 8601 con zona horaria.');
        }
        try {
            $since = new DateTimeImmutable($raw);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
                throw new InvalidArgumentException;
            }
        } catch (Throwable) {
            throw new InvalidArgumentException('FINANCIAL_COMMUNITY_NOTICES_SINCE no es una fecha válida.');
        }
        if (DB::connection('sqlsrv')->transactionLevel() !== 0) {
            throw new LogicException('Ejecuta los avisos fuera de una transacción financiera.');
        }

        return $since->setTimezone(new DateTimeZone('UTC'));
    }

    private function candidates(string $type, DateTimeImmutable $since): Builder
    {
        // SQL supplies a coarse lower bound; the scan compares the exact UTC instant.
        // This handles fixed-millisecond event strings and SQL datetime precision alike.
        [$query, $table, $operation] = match ($type) {
            FinancialCommunityNoticePayload::PAYMENT => [EventPaymentRequest::where('status', 'CONFIRMED')
                ->where('confirmed_at', '>=', $since->format('Y-m-d\TH:i:s').'.000Z')->whereNotNull('transaction_id'),
                'event_payment_requests', 'transaction_id'],
            FinancialCommunityNoticePayload::REFUND => [FinancialRefundRequest::where('status', 'COMPLETADA')
                ->where('completed_at', '>=', $since->format('Y-m-d H:i:s'))->whereNotNull('financial_transaction_id'),
                'financial_refund_requests', 'financial_transaction_id'],
            FinancialCommunityNoticePayload::BECA => [BonusBudgetCommitment::where('award_reference', 'like', 'beca:%')
                ->where('created_at', '>=', $since->format('Y-m-d H:i:s')), 'bonus_budget_commitments', 'issue_operation_id'],
        };
        if ($type === FinancialCommunityNoticePayload::PAYMENT) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('financial_transactions as t')
                ->whereColumn('t.public_id', 'event_payment_requests.transaction_id')->where('t.status', 'COMPLETADA'));
        }

        return $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('financial_community_notices as n')
            ->where('n.tipo', $type)->whereColumn('n.operation_id', $table.'.'.$operation));
    }

    private function payload(string $type, mixed $row): ?array
    {
        if ($type === FinancialCommunityNoticePayload::PAYMENT) {
            return FinancialCommunityNoticePayload::payment($row, FinancialTransaction::where('public_id', $row->transaction_id)->first());
        }
        if ($type === FinancialCommunityNoticePayload::REFUND) {
            $payment = EventPaymentRequest::where('transaction_id', $row->original_transaction_id)->where('status', 'CONFIRMED')->first();

            return $payment ? FinancialCommunityNoticePayload::refund($payment,
                FinancialTransaction::where('public_id', $row->original_transaction_id)->first(), $row,
                FinancialTransaction::where('public_id', $row->financial_transaction_id)->first()) : null;
        }

        return FinancialCommunityNoticePayload::beca($row,
            InstitutionalBudgetOperation::where('public_id', $row->issue_operation_id)->first(),
            Bonus::where('public_id', $row->bonus_id)->first());
    }

    private function due(): Builder
    {
        $now = Carbon::now('UTC');

        return FinancialCommunityNotice::where('status', 'PENDING')->where('next_attempt_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<=', $now));
    }

    private function acknowledgement(Response $response, string $key): ?array
    {
        $body = $response->json();
        if (! is_array($body) || ! is_string($body['notificacion_id'] ?? null)
            || ! preg_match('/\A[^\s\p{C}]{1,128}\z/u', $body['notificacion_id'])
            || ! in_array($body['estado'] ?? null, ['entregada', 'omitida'], true)
            || (isset($body['clave_idempotencia']) && $body['clave_idempotencia'] !== $key)) {
            return null;
        }

        return $body;
    }
}
