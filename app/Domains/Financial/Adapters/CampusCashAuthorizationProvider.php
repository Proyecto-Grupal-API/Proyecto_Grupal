<?php
namespace App\Domains\Financial\Adapters;
use App\Domains\Financial\Contracts\CashAuthorizationProvider;
use CampusDigital\Authorization\Kernel;
use App\Models\User;
class CampusCashAuthorizationProvider implements CashAuthorizationProvider
{
    public const ACTIONS=['read','operate','adjust','close','manage','recover','approval_read','approval_manage','approval_review'];
    public function __construct(private Kernel $kernel){}
    public function allows(string $actorId,string $associationId,string $action):bool {
        if(!config('campus_authorization.enabled')||!in_array($action,self::ACTIONS,true)
            ||!preg_match('/^user:([a-f0-9]{24})$/i',$actorId,$matches))return false;
        try{
            $id=strtolower($matches[1]);$user=User::find($id);
            if(!$user||$user->deleted_at||$user->account_activation_pending||$user->must_change_password||!$user->hasVerifiedEmail())return false;
            if(!in_array($action,['read','approval_read'],true)&&(!$user->two_factor_secret||!$user->two_factor_confirmed_at))return false;
            $principal=$this->kernel->principalFor($id);
            return $principal&&$this->kernel->check($principal,'financial.cash.'.$action,['type'=>'association','id'=>$associationId])->allowed();
        }catch(\Throwable $e){report($e);return false;}
    }
}
