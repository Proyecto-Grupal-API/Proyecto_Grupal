<?php
namespace App\Domains\Identity\Contracts;
interface ScopedCapabilityAuthorization extends CapabilityAuthorization
{
    /** Effective contexts only, never identity role documents. A global grant uses null/null. */
    public function contexts(string $userId, string $capability): array;
}
