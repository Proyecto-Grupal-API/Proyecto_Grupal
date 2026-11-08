<?php
namespace App\Http\Controllers\Financial;
use App\Http\Controllers\Controller;
use App\Domains\Financial\Contracts\{FinancialWebAuthorizer,InstitutionalBudgetAuthorization};
use App\Domains\Financial\Enums\{BonusStatus,BonusMovementType};
use App\Domains\Financial\Models\{Bonus,BonusLedgerEntry,InstitutionalBonusBudget,InstitutionalBudgetOperation};
use App\Domains\Financial\Services\{InstitutionalBudgetReadAccess,InstitutionalBudgetIntegrity,InstitutionalBonusIntegrity,InstitutionalBecaReleaseService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Cancels unused institutional balance only; never expires, refunds or pays obligations. */
final class InstitutionalBecaCancellationWebController extends Controller
{
    public function review(Request $r,string $bonusId)
    {
        abort_if($r->query()!==[],422,'La revision no admite filtros.');
        return $this->perform(function()use($r,$bonusId){
            $state=$this->inspect($r,strtolower($bonusId));$b=$state['bonus'];$budget=$state['budget'];
            $cancellable=$this->cancellable($b);
            return response()->json(['data'=>[...$this->snapshot($budget,$b),'currency'=>$b->currency,'status'=>$b->status->value,
                'consumed_amount_cents'=>$state['consumed_amount_cents'],'cancellable'=>$cancellable,
                'review_token'=>$cancellable?$this->token($r,$state['actor'],$this->snapshot($budget,$b)):null,
                'last_cancellation'=>$state['last_cancellation']]]);
        });
    }
    public function cancel(Request $r,string $bonusId)
    {
        abort_if(array_diff(array_keys($r->all()),['budget_version','reason','confirm_cancel','review_token']),422,'Campos no admitidos.');
        $d=$r->validate(['budget_version'=>['required','regex:/\A[1-9][0-9]{0,18}\z/','integer','min:1','max:'.PHP_INT_MAX],
            'reason'=>['required','string','max:1000','regex:/\S/u'],'confirm_cancel'=>['required','accepted'],
            'review_token'=>['required','string','size:64','regex:/\A[0-9a-f]{64}\z/']]);
        $key=validator(['key'=>$r->header('Idempotency-Key')],['key'=>['required','string','regex:/\A[A-Za-z0-9._:-]{1,200}\z/']])->validate()['key'];
        return $this->perform(function()use($r,$bonusId,$d,$key){
            $state=$this->inspect($r,strtolower($bonusId),strtolower($key));$b=$state['bonus'];$budget=$state['budget'];$original=$state['existing'];
            $snapshot=$this->snapshot($budget,$b);
            if($original){
                $before=$original->before_data;
                abort_unless(is_int($before['version']??null)&&is_int($before['bonus_remaining_cents']??null)
                    &&($before['bonus_remaining_cents']??0)>0&&is_string($before['bonus_status']??null),409);
                $snapshot['budget_version']=(string)$before['version'];$snapshot['remaining_amount_cents']=(string)$before['bonus_remaining_cents'];
                $snapshot['bonus_status']=$before['bonus_status'];
            }else abort_unless($this->cancellable($b),409,'El bono ya no tiene saldo cancelable.');
            abort_unless((string)$d['budget_version']===$snapshot['budget_version']
                &&hash_equals($this->token($r,$state['actor'],$snapshot),$d['review_token']),409,'El bono o la revision cambiaron. Revisa nuevamente.');
            // Inspection transaction has ended: domain acquires its request lock before budget -> bonus locks.
            // Its exact expected version makes a concurrent change fail, without a lock-order inversion.
            $actor=$this->actor($r);abort_unless($actor===$state['actor'],403);
            $result=app(InstitutionalBecaReleaseService::class)->cancel($actor,strtolower($budget->public_id),strtolower($b->public_id),
                (int)$d['budget_version'],$d['reason'],$key,['identity_session_id'=>$r->session()->get('cd_session_id'),
                    'native_session_hash'=>hash('sha256',$r->session()->getId())]);
            $op=$result['operation'];
            return response()->json(['data'=>['operation_id'=>strtolower($op->public_id),'bonus_id'=>$result['bonus_id'],'action'=>'CANCEL_BECA',
                'released_amount_cents'=>(string)$op->after_data['released_amount_cents'],'budget_version'=>(string)$op->after_data['version']],
                'replayed'=>$result['replayed']],$result['replayed']?200:201);
        });
    }
    private function actor(Request $r):string
    {
        $actor=app(InstitutionalBudgetReadAccess::class)->actor($r);
        if(!app(FinancialWebAuthorizer::class)->allows($actor,'bonus.cancel'))throw new AuthorizationException();
        return $actor;
    }
    private function ownership(string $actor,Bonus $bonus):void
    {
        if(($bonus->issuer_type!=='USER'||strtolower($bonus->issuer_id)!==$actor)
            &&!app(FinancialWebAuthorizer::class)->allows($actor,'bonus.cancel.all'))abort(404);
    }
    private function inspect(Request $r,string $bonusId,?string $key=null):array
    {
        $actor=$this->actor($r);$global=app(FinancialWebAuthorizer::class)->allows($actor,'bonus.cancel.all');
        $probe=Bonus::where('public_id',$bonusId)->when(!$global,fn($q)=>$q->where('issuer_type','USER')->where('issuer_id',$actor))->first();
        abort_unless($probe,404);$budgetId=$probe->getRawOriginal('institutional_budget_id');abort_unless($budgetId,409,'El bono no tiene compromiso institucional.');
        if(!app(InstitutionalBudgetAuthorization::class)->allows($actor,'budget.release',strtolower($budgetId)))throw new AuthorizationException();
        return DB::connection('sqlsrv')->transaction(function()use($r,$actor,$bonusId,$budgetId,$key){
            $budget=InstitutionalBonusBudget::where('public_id',$budgetId)->lockForUpdate()->first();abort_unless($budget,409);
            $b=Bonus::where('public_id',$bonusId)->lockForUpdate()->first();abort_unless($b,409);
            abort_unless($this->actor($r)===$actor,403);$this->ownership($actor,$b);
            if(!app(InstitutionalBudgetAuthorization::class)->allows($actor,'budget.release',strtolower($budgetId)))throw new AuthorizationException();
            // GET validates the preview; POST delegates full pre/post integrity to the writer under its own locks.
            if($key===null){app(InstitutionalBudgetIntegrity::class)->assertState($budget);app(InstitutionalBonusIntegrity::class)->assertState($budget,$b);}
            $existing=$key?InstitutionalBudgetOperation::where('idempotency_key',$key)->first():null;
            if($existing)abort_unless($existing->action==='CANCEL_BECA'&&strtolower($existing->budget_id)===strtolower($budgetId)
                &&strtolower((string)($existing->after_data['bonus_id']??''))===$bonusId&&$existing->actor_id===$actor,409);
            $consumed=null;$op=null;
            if($key===null){
                $consumed=DB::connection('sqlsrv')->table('institutional_bonus_obligations')->where('bonus_id',$b->public_id)
                    ->selectRaw('CAST(COALESCE(SUM(CAST(amount_cents AS decimal(38,0))-CAST(refunded_amount_cents AS decimal(38,0))),0) AS varchar(40)) AS amount')->value('amount');
                $last=BonusLedgerEntry::where('bonus_id',$b->public_id)->where('reference_type','BUDGET_RELEASE')
                    ->where('movement_type',BonusMovementType::CANCELACION->value)->orderByDesc('id')->first();
                $op=$last?InstitutionalBudgetOperation::where('public_id',$last->reference_id)->where('action','CANCEL_BECA')->first():null;
            }
            return ['actor'=>$actor,'budget'=>$budget,'bonus'=>$b,'existing'=>$existing,
                'consumed_amount_cents'=>$consumed===null?null:(string)$consumed,
                'last_cancellation'=>$op?['operation_id'=>strtolower($op->public_id),'released_amount_cents'=>(string)$op->after_data['released_amount_cents'],
                    'reason'=>$op->reason,'created_at'=>$op->created_at->toISOString()]:null];
        });
    }
    private function cancellable(Bonus $b):bool
    {
        return in_array($b->status,[BonusStatus::ACTIVO,BonusStatus::PENDIENTE],true)&&$b->remaining_amount_cents>0&&$b->expires_at&&now()->lt($b->expires_at);
    }
    private function snapshot(InstitutionalBonusBudget $budget,Bonus $b):array
    {
        return ['bonus_id'=>strtolower($b->public_id),'budget_id'=>strtolower($budget->public_id),'budget_version'=>(string)$budget->version,
            'remaining_amount_cents'=>(string)$b->remaining_amount_cents,'bonus_status'=>$b->status->value,'expires_at'=>$b->expires_at->toISOString()];
    }
    private function token(Request $r,string $actor,array $snapshot):string
    {
        $key=config('app.key');abort_unless(is_string($key)&&strlen($key)>=32,503);
        return hash_hmac('sha256',json_encode(['CANCEL_BECA',$actor,$r->session()->get('cd_session_id'),hash('sha256',$r->session()->getId()),$snapshot],JSON_THROW_ON_ERROR),$key);
    }
    private function perform(callable $work)
    {
        try{$response=$work();}
        catch(ValidationException $e){throw $e;}
        catch(AuthorizationException $e){$response=response()->json(['message'=>'No tienes autorizacion vigente para esta cancelacion.'],403);}
        catch(\InvalidArgumentException $e){$response=response()->json(['message'=>$e->getMessage()],409);}
        catch(Throwable $e){$status=$e instanceof HttpExceptionInterface?$e->getStatusCode():503;
            $response=response()->json(['message'=>match($status){404=>'Bono no disponible.',409=>'La cancelacion o su evidencia requieren revision. Conserva la solicitud y vuelve a consultar.',default=>'No se pudo verificar o completar la cancelacion. Conserva la misma solicitud.'}],$status);}
        return $response->header('Cache-Control','no-store, private');
    }
}
