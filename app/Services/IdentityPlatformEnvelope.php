<?php
namespace App\Services;

use InvalidArgumentException;
use Illuminate\Support\Str;

/** Explicit E1 -> E7 adapter; legacy outbox format and event identity stay unchanged. */
final class IdentityPlatformEnvelope
{
    private const EVENTS = [
        'student.profile.changed.v1' => ['identity.student.profile.changed.v1', ['student_id','operation','changed_fields','actor_id']],
        'student.consent.changed.v1' => ['identity.student.consent.changed.v1', ['student_id','consent_id','status','version','actor_id']],
        'identity.credential.changed.v1' => ['identity.credential.changed.v1', ['credential_id','credential_type','operation','status','actor_id','student_id','old_status','new_status']],
    ];

    public static function prepare(object $event, string $sink, string $secret, ?int $time = null): array
    {
        $url = parse_url($sink);
        $local = in_array(config('app.env'), ['local','testing'], true);
        if (!$url || array_diff(array_keys($url), ['scheme','host','port','path'])
            || ($url['path'] ?? '') !== '/api/v1/plataforma/eventos'
            || !(($url['scheme'] ?? '') === 'https' || (($url['scheme'] ?? '') === 'http' && $local
                && in_array($url['host'] ?? '', ['localhost','127.0.0.1','[::1]'], true)))
            || strlen($secret) < 20 || !Str::isUuid((string) $event->event_id)
            || !isset(self::EVENTS[$event->event_name])) {
            throw new InvalidArgumentException('Unsupported identity platform transport configuration or event.');
        }
        [$name,$fields] = self::EVENTS[$event->event_name];
        $payload = $event->payload?->getArrayCopy() ?? [];
        // Free-text reasons and undeclared fields do not cross this analytics boundary.
        $payload = array_intersect_key($payload, array_flip($fields));
        $body = json_encode(['event_id'=>$event->event_id,'event_name'=>$name,'source_domain'=>'identity',
            'aggregate_id'=>$event->aggregate_id,'occurred_at'=>$event->occurred_at?->toDateTime()->format('Y-m-d\TH:i:s.u\Z'),
            'payload'=>$payload], JSON_THROW_ON_ERROR);
        $timestamp = (string) ($time ?? time());
        $signature = hash_hmac('sha256', $timestamp.'|POST|'.$url['path'].'|'.hash('sha256',$body), $secret);
        return ['body'=>$body,'headers'=>['X-Platform-Domain'=>'identity','X-Platform-Timestamp'=>$timestamp,
            'X-Platform-Signature'=>$signature]];
    }
}
