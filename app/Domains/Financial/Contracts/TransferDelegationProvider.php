<?php
namespace App\Domains\Financial\Contracts;
interface TransferDelegationProvider
{
    /**
     * Resolve a verified Module 1 user ID, never a body-supplied identity.
     * Implementations MUST verify trusted issuer/signature, audience, expiry,
     * authenticated client binding, action and request digest. Verify consent
     * and current account/session status and reject revoked grants. For confirm,
     * the proof must establish explicit consent to THIS immutable preparation.
     * Replay of the identical request must support financial idempotency; a proof
     * must never authorize altered data, a different route/client or action.
     * Invalid proofs throw UnauthorizedHttpException; dependency outages throw
     * FinancialDependencyUnavailableException. Never log or store raw proofs.
     */
    public function resolve(string $clientId, string $proof, string $action, string $requestDigest): string;
}
