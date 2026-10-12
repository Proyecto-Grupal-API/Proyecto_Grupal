<?php
namespace Tests;
use App\Domains\Financial\Contracts\{ReceiptAdministrativeAuthorization,LegacyCashReceiptAuthorization};
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
/** Test-only contract stub. Never registered by a production provider. */
final class ReceiptAuthorizationFixture implements ReceiptAdministrativeAuthorization,LegacyCashReceiptAuthorization {
    public array $inactive=[];public bool $available=true;private array $global=[];private array $scopes=[];
    public function __construct(private string $prefix){}
    public function allowGlobal(string $id):void {$this->global[$id]=true;}
    public function allowScope(string $id,array $actions,?array $scope,bool $all,?int $ends,string $reason,?int $starts=null):string {
        $key=(string)count($this->scopes);$this->scopes[$key]=compact('id','actions','scope','ends','starts');return $key;
    }
    public function revoke(string $id):void {unset($this->scopes[$id]);}
    private function available():void {if(!$this->available)throw new FinancialDependencyUnavailableException('Fixture unavailable.');}
    public function assertGlobal(string $userId):void {
        $this->available();if(!isset($this->global[$userId])||in_array($userId,$this->inactive,true))throw new AccessDeniedHttpException();
    }
    public function assertRead(string $userId,?array $scope):void {
        $this->available();
        $valid=$scope===null||(($scope['type']??'')==='business'&&in_array($scope['id']??'',["{$this->prefix}-business-a","{$this->prefix}-business-b"],true))||(($scope['type']??'')==='association'&&($scope['id']??'')==="{$this->prefix}-association");
        if(!$valid||in_array($userId,$this->inactive,true))throw new AccessDeniedHttpException();
        if(isset($this->global[$userId]))return;
        foreach($this->scopes as $g)if($g['id']===$userId&&$g['scope']===$scope&&in_array('module.2.9.read',$g['actions'],true)&&(!$g['starts']||$g['starts']<=time())&&(!$g['ends']||$g['ends']>time()))return;
        throw new AccessDeniedHttpException();
    }
}
