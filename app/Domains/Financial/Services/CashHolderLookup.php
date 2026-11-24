<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Contracts\TransferRecipientProvider;
use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Enums\WalletType;
use InvalidArgumentException;

/**
 * FIN-05: la caja encuentra al titular por matrícula (o identificador de usuario) sin copiar un UUID a mano. Reutiliza el
 * adaptador de destinatarios de E2, que consulta el directorio de E1 con sus reglas (preflight de integridad, cuentas
 * vigentes, mensajes diferenciados). Sólo devuelve lo necesario para verificar antes de operar: titular, matrícula, wallet
 * personal, moneda y estado. No es un directorio abierto: la ruta exige el permiso de operar el turno.
 */
class CashHolderLookup
{
    public function __construct(private readonly TransferRecipientProvider $recipients, private readonly WalletService $wallets) {}

    public function find(string $value, string $actor, ?string $ip, string $registerCurrency): array
    {
        $value = trim($value);
        $holder = $this->recipients->resolve(preg_match('/^[a-f0-9]{24}$/i', $value) ? 'USER_ID' : 'ENROLLMENT', $value, $actor, $ip);
        $wallet = $this->wallets->findByOwner('USER', strtolower($holder['user_id']), WalletType::USUARIO);
        if (! $wallet) {
            throw new InvalidArgumentException('El titular no tiene una wallet personal.');
        }
        $active = $wallet->status === WalletStatus::ACTIVA;
        $sameCurrency = $wallet->currency === $registerCurrency;

        return ['holder_name' => $holder['name'], 'enrollment_number' => $holder['enrollment_number'] ?? null,
            'wallet_id' => strtolower($wallet->public_id), 'currency' => $wallet->currency, 'status' => $wallet->status->value,
            'operable' => $active && $sameCurrency,
            'notice' => ! $active ? 'La wallet no está activa: no admite operaciones de caja.' : (! $sameCurrency ? 'La wallet usa otra moneda que la caja.' : null)];
    }
}
