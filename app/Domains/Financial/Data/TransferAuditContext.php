<?php
namespace App\Domains\Financial\Data;
use App\Domains\Financial\Support\FinancialCorrelation;
use InvalidArgumentException;
final class TransferAuditContext
{
    public function __construct(public readonly string $channel, public readonly ?string $clientId = null,
        public readonly ?string $ip = null, public readonly ?string $correlationId = null,
        public readonly ?string $sessionId = null, public readonly ?string $deviceId = null,
        public readonly string $identityContextStatus = 'NOT_PROVIDED')
    {
        if (!in_array($channel, ['WEB', 'API', 'INTERNAL'], true)
            || !in_array($identityContextStatus, ['VERIFIED', 'NOT_PROVIDED', 'NOT_VERIFIED'], true)
            || ($channel === 'API' && ($clientId === null || trim($clientId) === ''))
            || ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP) === false)) {
            throw new InvalidArgumentException('Contexto de auditoría inválido.');
        }
        foreach ([$clientId, $sessionId, $deviceId] as $id) { if ($id !== null && mb_strlen($id) > 255) { throw new InvalidArgumentException('Identificador de auditoría demasiado largo.'); } }
        if ($correlationId !== null && mb_strlen($correlationId) > 100) { throw new InvalidArgumentException('Correlación demasiado larga.'); }
        if ($identityContextStatus !== 'VERIFIED' && ($sessionId !== null || $deviceId !== null)) { throw new InvalidArgumentException('No se pueden atribuir sesiones o dispositivos no verificados.'); }
    }
    public static function internal(): self { return new self('INTERNAL', correlationId: app(FinancialCorrelation::class)->id()); }
    public function toArray(): array
    {
        return ['channel' => $this->channel, 'client_id' => $this->clientId, 'ip' => $this->ip,
            'correlation_id' => $this->correlationId, 'session_id' => $this->sessionId, 'device_id' => $this->deviceId,
            'identity_context_status' => $this->identityContextStatus];
    }
}
