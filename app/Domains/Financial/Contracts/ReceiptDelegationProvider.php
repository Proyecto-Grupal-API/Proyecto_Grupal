<?php
namespace App\Domains\Financial\Contracts;
interface ReceiptDelegationProvider
{
    /**
     * Return a canonical verified Module 1 user ID, never a supplied owner ID.
     * Verify signature, trusted issuer, audience, expiry, authenticated service
     * client, consent for action AND digest, current account/session validity,
     * and revocation on every request. A transfer proof is not a receipt proof.
     * Digest binds method, path and query. Never log/store raw proofs.
     * Invalid/expired/revoked proof: UnauthorizedHttpException (401).
     * Missing consent: AccessDeniedHttpException (403).
     * Dependency failure: FinancialDependencyUnavailableException (503).
     * Read-only replay of an identical valid request is permitted.
     */
    public function resolve(string $clientId, string $proof, string $action, string $requestDigest): string;
}
