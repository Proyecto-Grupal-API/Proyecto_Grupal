<?php
namespace App\Domains\Financial\Services;
use App\Domains\Financial\Models\{InstitutionalBonusBudget,InstitutionalBudgetOperation};
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Read model only. The web caller must authorize the selected budget before invoking it. */
final class InstitutionalBudgetHistoryQuery
{
    public const ACTIONS = ['APPROVE','ADJUST','SUSPEND','RESUME','ISSUE_BECA','CONSUME_BECA','CANCEL_BECA','EXPIRE_BECA','REFUND_BECA','SETTLE_BECA','REFUND_CLOSED_BECA'];
    public function report(string $budgetId, ?string $action = null, int $perPage = 10, ?string $before = null): array
    {
        if (!Str::isUuid($budgetId) || ($action !== null && !in_array($action,self::ACTIONS,true)) || $perPage<1 || $perPage>20
            || ($before !== null && !preg_match('/\A[1-9][0-9]{0,17}\z/',$before))) throw new InvalidArgumentException('Filtros de historial no validos.');
        $budgetId=strtolower($budgetId);
        $budget=InstitutionalBonusBudget::where('public_id',$budgetId)->firstOrFail();
        $q=InstitutionalBudgetOperation::where('budget_id',$budgetId);
        if ($action !== null) $q->where('action',$action);
        if ($before !== null) $q->where('id','<',$before);
        $rows=$q->orderByDesc('id')->limit($perPage+1)->get();$more=$rows->count()>$perPage;
        $visible=$rows->take($perPage);
        return ['budget_id'=>$budgetId,'purpose'=>$budget->purpose,'currency'=>$budget->currency,
            'observed_at'=>now()->toISOString(),'money_moved'=>false,'history_is_not_reconciliation'=>true,
            'items'=>$visible->map(fn($op)=>$this->entry($op))->values()->all(),
            'has_more'=>$more,'next_cursor'=>$more?(string)$visible->last()->id:null];
    }
    private function entry(InstitutionalBudgetOperation $op): array
    {
        $before=$op->before_data;$after=$op->after_data;
        $valid=(is_array($before)||($op->action==='APPROVE'&&$before===null))&&is_array($after)&&in_array($op->action,self::ACTIONS,true);
        $before=is_array($before)?$before:[];$after=is_array($after)?$after:[];
        $refs=[];
        foreach(['bonus_id','commitment_id','purchase_payment_id','obligation_id','refund_request_id','settlement_id']as$key){
            if(!array_key_exists($key,$after))continue;
            if(is_string($after[$key])&&Str::isUuid($after[$key]))$refs[$key]=strtolower($after[$key]);else $valid=false;
        }
        foreach(['approved_amount_cents','committed_amount_cents','consumed_amount_cents','available_amount_cents','version','status']as$key) {
            if(!isset($after[$key]))$valid=false;
        }
        $beforeSnapshot=$this->snapshot($before,$valid);$afterSnapshot=$this->snapshot($after,$valid);
        $settlement=null;
        if($op->action==='SETTLE_BECA') {
            $obligation=$refs['obligation_id']??null;if($obligation===null)$valid=false;
            $settlement=['obligation_id'=>$obligation,
                'paid_amount_cents'=>$this->settlementAmount($after,'settled_delta_cents',$valid,true),
                'before'=>['settled_amount_cents'=>$this->settlementAmount($before,'settled_amount_cents',$valid),
                    'unpaid_amount_cents'=>$this->settlementAmount($before,'unpaid_amount_cents',$valid)],
                'after'=>['settled_amount_cents'=>$this->settlementAmount($after,'settled_amount_cents',$valid),
                    'unpaid_amount_cents'=>$this->settlementAmount($after,'unpaid_amount_cents',$valid)]];
        }
        return ['operation_id'=>strtolower($op->public_id),'action'=>in_array($op->action,self::ACTIONS,true)?$op->action:'UNKNOWN',
            'created_at'=>$op->created_at?->toISOString(),'reason'=>is_string($op->reason)?$op->reason:'',
            'before'=>$beforeSnapshot,'after'=>$afterSnapshot,'references'=>$refs,
            'settlement'=>$settlement,'recorded_fields_valid'=>$valid];
    }
    private function settlementAmount(array $data,string $key,bool &$valid,bool $positive=false): ?string
    {
        $v=$data[$key]??null;
        if((is_int($v)||is_string($v))&&preg_match('/\A(?:0|[1-9][0-9]{0,37})\z/',(string)$v)
            && (!$positive||(string)$v!=='0'))return (string)$v;
        $valid=false;return null;
    }
    private function snapshot(array $data,bool &$valid): array
    {
        $out=[];
        foreach(['approved_amount_cents','committed_amount_cents','consumed_amount_cents','available_amount_cents','version']as$key){
            $v=$data[$key]??null;
            if($v===null){$out[$key]=null;continue;}
            if((is_int($v)||is_string($v))&&preg_match('/\A-?(?:0|[1-9][0-9]{0,37})\z/',(string)$v))$out[$key]=(string)$v;
            else {$out[$key]=null;$valid=false;}
        }
        $status=$data['status']??null;
        if($status!==null&&!in_array($status,['ACTIVE','SUSPENDED'],true)){$status=null;$valid=false;}
        $out['status']=$status;return $out;
    }
}
