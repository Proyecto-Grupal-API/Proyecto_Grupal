<?php
namespace App\Domains\Identity\ReceiptDelegation;
use App\Models\{User,UserSession};
use Illuminate\Support\Facades\{Auth,Session};
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
class ReceiptSessionVerifier {
    public function assertSupportedDriver():void {
        if(!in_array(config('session.driver'),['file','database','redis','memcached','dynamodb','array'],true))
            throw new FinancialDependencyUnavailableException('La delegación requiere almacenamiento de sesión en el servidor.');
    }
    public function assertAccountSession(string $userId,string $sessionId):void {
        $u=User::find($userId);$session=UserSession::find($sessionId);
        if(!$u||$u->deleted_at||$u->account_activation_pending||$u->must_change_password||!$u->hasVerifiedEmail()
            ||!$session||$session->isRevoked()||(string)$session->user_id!==$userId)
            throw new UnauthorizedHttpException('Bearer','La cuenta o sesión autorizante ya no está vigente.');
    }
    public function assertNativeSession(string $userId,string $nativeId):void {
        $this->assertSupportedDriver();
        if(!preg_match('/^[a-zA-Z0-9]{40}$/',$nativeId))throw new UnauthorizedHttpException('Bearer','Sesión inválida.');
        $payload=Session::driver()->getHandler()->read($nativeId);
        if(!is_string($payload)||$payload===''||strlen($payload)>1048576)throw new UnauthorizedHttpException('Bearer','La sesión autorizante terminó.');
        if(config('session.encrypt',false))$payload=app('encrypter')->decrypt($payload);
        $data=config('session.serialization','php')==='json'?json_decode($payload,true):
            @unserialize($payload,['allowed_classes'=>[\MongoDB\BSON\ObjectId::class]]);
        $login=is_array($data)?($data[Auth::guard('web')->getName()]??null):null;
        if((!is_string($login)&&!($login instanceof \MongoDB\BSON\ObjectId))||(string)$login!==$userId)
            throw new UnauthorizedHttpException('Bearer','La sesión autorizante terminó o cambió de usuario.');
    }
}
