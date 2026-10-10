<?php
namespace App\Domains\Financial\Contracts;
interface TransferRecipientProvider
{
    /** Returns only user_id, name and enrollment_number, never credentials or balance. */
    public function resolve(string $method, string $value, string $actorId, ?string $ip): array;
}
