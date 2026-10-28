<?php

namespace App\Services;

use App\Models\AuditoriaComunidad;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use MongoDB\BSON\UTCDateTime;
use Ramsey\Uuid\Uuid;

/** E6 reads only its own audit trail. E7 persists the projection through HTTP. */
final class CommunityPlatformDelivery
{
    public function envelope(AuditoriaComunidad $audit): array
    {
        $id = (string) $audit->getKey();

        return ['event_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'campus:community:audit:'.$id)->toString(),
            'actor_id' => (string) ($audit->usuario_id ?: 'not_recorded'), 'actor_role' => 'not_recorded',
            'action' => (string) $audit->accion, 'entity_type' => 'community_record', 'entity_id' => (string) ($audit->entidad_id ?: $id),
            'correlation_id' => 'community:'.$id, 'occurred_at' => $audit->{$audit->getCreatedAtColumn()}->toIso8601String(),
            'after' => ['organization_id' => (string) $audit->organizacion_id, 'source_audit_id' => $id]];
    }

    public function deliver(int $limit = 100): array
    {
        if (config('platform.delivery.enabled') !== true) {
            return ['sent' => 0, 'failed' => 0, 'enabled' => false];
        }
        $url = rtrim((string) config('platform.delivery.url'), '/');
        $parts = parse_url($url);
        abort_unless(is_array($parts) && isset($parts['host'],$parts['scheme']) && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment']) && empty($parts['path'])
            && ($parts['scheme'] === 'https' || (app()->environment(['local', 'testing']) && $parts['scheme'] === 'http' && in_array($parts['host'], ['app', 'localhost', '127.0.0.1'], true))), 503, 'Origen E7 inválido.');
        $secret = config('platform.ingest.secrets.community');
        abort_unless(is_string($secret) && strlen($secret) >= 20, 503, 'Falta credencial de transporte E7.');
        $receipts = DB::connection('mongodb')->getCollection('community_platform_deliveries');
        $sent = 0;
        $failed = 0;
        // ponytail: scans historical audits each pass; add a durable cursor if this grows beyond the local course workload.
        // Bound deliveries. A failed old record does not prevent delivery of later records.
        $last = null;
        $examined = 0;
        while ($examined < $limit) {
            $query = AuditoriaComunidad::orderBy('_id')->when($last, fn ($q) => $q->where('_id', '>', $last));
            $rows = $query->limit(100)->get();
            if ($rows->isEmpty()) {
                break;
            }
            foreach ($rows as $audit) {
                $last = (string) $audit->getKey();
                if ($receipts->findOne(['audit_id' => $last, 'status' => 'sent'])) {
                    continue;
                }
                if (++$examined > $limit) {
                    break;
                }
                $body = $this->envelope($audit);
                $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $path = '/api/v1/plataforma/auditoria';
                $time = (string) time();
                $error = null;
                try {
                    $response = Http::acceptJson()->connectTimeout(3)->timeout(10)->withoutRedirecting()->withHeaders([
                        'X-Platform-Domain' => 'community', 'X-Platform-Timestamp' => $time,
                        'X-Platform-Signature' => hash_hmac('sha256', $time.'|POST|'.$path.'|'.hash('sha256', $json), $secret),
                    ])->withBody($json, 'application/json')->post($url.$path);
                    if (! in_array($response->status(), [200, 202], true) || $response->json('event_id') !== $body['event_id'] || ! in_array($response->json('estado'), ['aceptado', 'duplicado'], true)) {
                        $error = 'invalid_receipt_'.$response->status();
                    }
                } catch (\Throwable $e) {
                    $error = 'transport_unavailable';
                }
                $receipts->updateOne(['audit_id' => $last], ['$set' => ['event_id' => $body['event_id'], 'status' => $error === null ? 'sent' : 'failed', 'last_error' => $error, 'updated_at' => new UTCDateTime(now())], '$inc' => ['attempts' => 1]], ['upsert' => true]);
                if ($error === null) {
                    $sent++;
                } else {
                    $failed++;
                }
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'enabled' => true];
    }
}
