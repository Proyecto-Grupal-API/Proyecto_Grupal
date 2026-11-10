<?php

namespace App\Services\StudentServices\Platform;

use App\Models\StudentServices\Platform\ServicePlatformEvent;
use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Throwable;

/**
 * Registra hechos del Equipo 5 para la analítica del Equipo 7.
 *
 * Solo escribe en la salida local (service_platform_events); el envío HTTP
 * lo hace ServicePlatformDelivery desde el scheduler. Nunca rompe la
 * operación del alumno: si la salida falla se reporta y se sigue.
 *
 * Los payloads llevan identificadores y estados, nunca nombres ni correos
 * (acuerdo de privacidad de REQ-M5-E7-001).
 */
class ServicePlatformEvents
{
    /** Formato exigido por el receptor: dominio.entidad.accion.vN */
    public const NAME_PATTERN = '/^services(\.[a-z_]+)+\.v\d+$/';

    /** Llaves que el receptor rechaza en cualquier nivel del payload. */
    public const BLOCKED_KEYS = ['name', 'nombre', 'email', 'correo', 'password', 'token', 'secret', 'documento', 'expediente'];

    /**
     * @param  string  $entityAction  Por ejemplo "loan.created" (se completa a services.loan.created.v1).
     * @param  array<string, mixed>  $payload
     */
    public function emit(
        string $entityAction,
        string $aggregateId,
        array $payload,
        ?CarbonInterface $occurredAt = null,
    ): ?ServicePlatformEvent {
        try {
            $name = 'services.'.$entityAction.'.v1';

            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new InvalidArgumentException("Nombre de evento inválido: {$name}");
            }

            $payload = self::normalize($payload);

            if ($payload === []) {
                throw new InvalidArgumentException("El evento {$name} no tiene payload.");
            }

            return ServicePlatformEvent::create([
                'event_id' => (string) Str::uuid(),
                'event_name' => $name,
                'aggregate_id' => Str::limit($aggregateId, 100, ''),
                'occurred_at' => ($occurredAt ?? now())->toIso8601String(),
                'payload' => $payload,
                'status' => ServicePlatformEvent::PENDING,
                'attempts' => 0,
                'next_attempt_at' => now(),
                'last_error' => null,
                'sent_at' => null,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Deja el valor listo para JSON y sin datos personales: fechas a ISO 8601,
     * ObjectId a texto y sin las llaves bloqueadas por el receptor.
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    public static function normalize(array $value): array
    {
        $clean = [];

        foreach ($value as $key => $entry) {
            if (is_string($key) && in_array(strtolower($key), self::BLOCKED_KEYS, true)) {
                continue;
            }

            $clean[$key] = match (true) {
                $entry instanceof UTCDateTime => $entry->toDateTime()->format(DATE_ATOM),
                $entry instanceof DateTimeInterface => $entry->format(DATE_ATOM),
                $entry instanceof ObjectId => (string) $entry,
                $entry instanceof BackedEnum => $entry->value,
                is_array($entry) => self::normalize($entry),
                is_object($entry) => self::normalize((array) $entry),
                default => $entry,
            };
        }

        return $clean;
    }
}
