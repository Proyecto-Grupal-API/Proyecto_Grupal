<?php
namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Domains\Financial\Contracts\{InstitutionalBudgetAuthorization,FinancialWebAuthorizer};
use App\Domains\Financial\Services\{InstitutionalBudgetReadAccess,InstitutionalBudgetIntegrity,InstitutionalBecaIssuanceService};
use App\Domains\Financial\Models\InstitutionalBonusBudget;
use App\Domains\Financial\Support\BonusIssuanceInput;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Native administrative BECA issuance only. No grant, budget approval or scholarship application. */
final class InstitutionalBecaWebController extends Controller
{
    public function review(Request $request,string $budgetId)
    {
        abort_if($request->query()!==[],422,'La revision no admite filtros.');
        return $this->perform(function()use($request,$budgetId){
            $budgetId=strtolower($budgetId); $this->actor($request,$budgetId);
            $data=DB::connection('sqlsrv')->transaction(function()use($budgetId){
                $b=InstitutionalBonusBudget::where('public_id',$budgetId)->lockForUpdate()->first();
                abort_unless($b,404);
                app(InstitutionalBudgetIntegrity::class)->assertState($b);
                abort_unless($b->status==='ACTIVE' && $b->bonus_type==='BECA' && $b->currency==='MXN'
                    && now()->gte($b->valid_from) && now()->lt($b->expires_at),409,'El presupuesto no esta disponible para emitir.');
                return ['budget_id'=>$budgetId,'purpose'=>$b->purpose,'version'=>(string)$b->version,'currency'=>$b->currency,
                    'available_amount_cents'=>(string)($b->approved_amount_cents-$b->consumed_amount_cents-$b->committed_amount_cents),
                    'valid_from'=>$b->valid_from->toISOString(),'expires_at'=>$b->expires_at->toISOString()];
            });
            return response()->json(['data'=>$data]);
        });
    }

    public function issue(Request $request)
    {
        abort_if(array_diff(array_keys($request->all()),['beneficiary_id','type','amount_cents','valid_from','expires_at',
            'combinable','allows_partial_use','external_reference','reason','restrictions','budget_id','budget_version','award_reference','confirm_institutional']),422,'Campos no admitidos.');
        $extra=$request->validate(['budget_id'=>['required','uuid'],'budget_version'=>['required','regex:/\A[1-9][0-9]{0,18}\z/','integer','min:1','max:'.PHP_INT_MAX],
            'award_reference'=>['required','string','max:150','regex:/\S/u'],'confirm_institutional'=>['required','accepted'],'type'=>['required','in:BECA']]);
        $data=BonusIssuanceInput::validate($request->all());
        $key=validator(['key'=>$request->header('Idempotency-Key')],['key'=>['required','string','regex:/\A[A-Za-z0-9._:-]{1,200}\z/']])->validate()['key'];
        return $this->perform(function()use($request,$extra,$data,$key){
            $budgetId=strtolower($extra['budget_id']);$actor=$this->actor($request,$budgetId);
            $result=app(InstitutionalBecaIssuanceService::class)->issue($actor,$budgetId,(int)$extra['budget_version'],
                [...$data,'award_reference'=>$extra['award_reference']],$key,
                ['identity_session_id'=>$request->session()->get('cd_session_id'),'native_session_hash'=>hash('sha256',$request->session()->getId())]);
            return response()->json(['data'=>['bonus_id'=>$result['bonus_id'],'operation_id'=>strtolower($result['operation']->public_id),
                'budget_id'=>$budgetId,'budget_version'=>(string)$result['operation']->after_data['version'],'action'=>'ISSUE_BECA'],
                'replayed'=>$result['replayed']],$result['replayed']?200:201);
        });
    }

    private function actor(Request $request,string $budgetId): string
    {
        $actor=app(InstitutionalBudgetReadAccess::class)->actor($request);
        if(!app(FinancialWebAuthorizer::class)->allows($actor,'bonus.issue')
            || !app(InstitutionalBudgetAuthorization::class)->allows($actor,'budget.commit',$budgetId))throw new AuthorizationException();
        return $actor;
    }
    private function perform(callable $work)
    {
        try{$response=$work();}
        catch(ValidationException $e){throw $e;}
        catch(AuthorizationException $e){$response=response()->json(['message'=>'No tienes autorizacion vigente para esta emision.'],403);}
        catch(\InvalidArgumentException $e){$response=response()->json(['message'=>$e->getMessage()],409);}
        catch(Throwable $e){
            $status=$e instanceof HttpExceptionInterface?$e->getStatusCode():503;
            $message=match($status){404=>'Presupuesto no disponible.',409=>'La emision o el presupuesto requieren revision. Comprueba el resultado antes de iniciar otra solicitud.',default=>'No se pudo verificar o completar la operacion. Conserva la misma solicitud para consultar o reintentar.'};
            $response=response()->json(['message'=>$message],$status);
        }
        return $response->header('Cache-Control','no-store, private');
    }
}
