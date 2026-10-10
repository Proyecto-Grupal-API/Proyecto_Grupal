<?php
namespace App\Domains\Financial\Contracts;
interface TransferSessionContextProvider
{
    /** Resolve only a server-held, unrevoked Module 1 session owned by this user. Never a cookie secret or body-supplied device ID. */
    public function resolve(string $userId, ?string $registeredSessionId): array;
}
