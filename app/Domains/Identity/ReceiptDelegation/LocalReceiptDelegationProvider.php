<?php
namespace App\Domains\Identity\ReceiptDelegation;
use App\Domains\Financial\Contracts\ReceiptDelegationProvider;
use App\Domains\Financial\Models\ReceiptUserDelegation;
use App\Models\ServiceClient;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
class LocalReceiptDelegationProvider implements ReceiptDelegationProvider {
    public function __construct(private ReceiptSessionVerifier $sessions){}
    public function resolve(string $clientId,string $proof,string $action,string $digest):string {
        if(!preg_match('/^rcp_[a-f0-9]{64}$/',$proof))throw new UnauthorizedHttpException('Bearer','Delegación inválida.');
        $grant=ReceiptUserDelegation::where('proof_hash',hash('sha256',$proof))->first();
        if(!$grant||$grant->revoked_at||$grant->expires_at->lte(now())||$grant->client_id!==$clientId
            ||$grant->action!==$action||!hash_equals($grant->request_digest,$digest))
            throw new UnauthorizedHttpException('Bearer','Delegación inválida, vencida o revocada.');
        $client=ServiceClient::where('client_id',$clientId)->where('active',true)->first();
        $scopes=$client?->scopes??[];$scopes=is_array($scopes)?$scopes:iterator_to_array($scopes);
        if(!$client||!in_array('financial:receipt:read',$scopes,true))throw new UnauthorizedHttpException('Bearer','El cliente ya no está autorizado.');
        $this->sessions->assertAccountSession($grant->user_id,$grant->identity_session_id);
        $this->sessions->assertNativeSession($grant->user_id,$grant->native_session_id);
        // A revocation committed while verifying identity also stops this response.
        if(ReceiptUserDelegation::where('public_id',$grant->public_id)->whereNotNull('revoked_at')->exists())
            throw new UnauthorizedHttpException('Bearer','Delegación revocada.');
        return $grant->user_id;
    }
}
