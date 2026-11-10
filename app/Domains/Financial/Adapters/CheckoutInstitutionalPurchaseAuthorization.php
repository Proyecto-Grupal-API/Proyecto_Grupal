<?php
namespace App\Domains\Financial\Adapters;
use App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization;
use App\Domains\Financial\Services\InstitutionalCheckoutConsent;

/** Only native checkout payment consent. Refunds retain the existing pending denial. */
final class CheckoutInstitutionalPurchaseAuthorization implements InstitutionalPurchaseAuthorization
{
    public function __construct(private readonly InstitutionalCheckoutConsent $consent,
        private readonly PendingInstitutionalPurchaseAuthorization $pending){}
    public function allows(string $actorId,string $action,array $context):bool
    {
        return $action==='purchase.pay' ? $this->consent->allows($actorId,$context) : $this->pending->allows($actorId,$action,$context);
    }
}
