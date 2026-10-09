<?php

namespace App\Domains\Financial\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Lock exclusivo respaldado por la tabla financial_job_locks (índice
 * único en lock_key). Un lock vencido (proceso caído) puede tomarse de
 * nuevo después de su TTL.
 */
class FinancialJobLock
{
    /**
     * @return string|null  token del dueño, o null si el lock está ocupado
     */
    public function acquire(string $key, int $ttlSeconds): ?string
    {
        $token = (string) Str::uuid();
        $now = now();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                DB::connection('sqlsrv')->table('financial_job_locks')->insert([
                    'lock_key' => $key,
                    'owner_token' => $token,
                    'acquired_at' => $now,
                    'expires_at' => $now->copy()->addSeconds(max(1, $ttlSeconds)),
                ]);

                return $token;
            } catch (QueryException $exception) {
                // Only a unique-key conflict means another worker owns the lock.
                // Permission/schema/connection failures must remain visible.
                $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
                $driverCode = (int) ($exception->errorInfo[1] ?? 0);

                if (!in_array($driverCode, [2601, 2627, 1062], true)
                    && $sqlState !== '23505') {
                    throw $exception;
                }

                // Ocupado: solo se libera si ya venció.
                $released = DB::connection('sqlsrv')->table('financial_job_locks')
                    ->where('lock_key', $key)
                    ->where('expires_at', '<=', $now)
                    ->delete();

                if ($released === 0) {
                    return null;
                }
            }
        }

        return null;
    }

    public function release(string $key, string $token): void
    {
        DB::connection('sqlsrv')->table('financial_job_locks')
            ->where('lock_key', $key)
            ->where('owner_token', $token)
            ->delete();
    }

    /** Call inside the persistence transaction: fences out expired workers. */
    public function assertOwned(string $key, string $token, int $ttlSeconds): void
    {
        $connection = DB::connection('sqlsrv');
        $owned = $connection->table('financial_job_locks')
            ->where('lock_key', $key)->where('owner_token', $token)
            ->where('expires_at', '>', now())->lockForUpdate()->first();

        if (!$owned) {
            throw new RuntimeException('El lock de conciliación venció o cambió de propietario.');
        }

        $connection->table('financial_job_locks')
            ->where('lock_key', $key)->where('owner_token', $token)
            ->update(['expires_at' => now()->addSeconds(max(1, $ttlSeconds))]);
    }

    public function isHeld(string $key): bool
    {
        return DB::connection('sqlsrv')->table('financial_job_locks')
            ->where('lock_key', $key)
            ->where('expires_at', '>=', now())
            ->exists();
    }
}
