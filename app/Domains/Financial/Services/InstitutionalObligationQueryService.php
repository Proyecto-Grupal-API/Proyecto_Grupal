<?php
namespace App\Domains\Financial\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Operator-only read model. Shared budget locks coordinate with the institutional writers. */
final class InstitutionalObligationQueryService
{
    public function report(?string $budgetId = null, ?string $business = null, int $page = 1, int $perPage = 10, bool $details = false, bool $settlements = false): array
    {
        if (($budgetId !== null && !Str::isUuid($budgetId)) || ($business !== null && ($budgetId === null
            || $business === '' || strlen($business) > 100 || preg_match('/[\x00-\x20\x7f]/',$business)))
            || (($details || $settlements) && $budgetId === null) || ($details && $settlements) || $page < 1 || $page > 1000000 || $perPage < 1 || $perPage > 20) {
            throw new InvalidArgumentException('Filtros no validos: UUID de presupuesto; negocio/detalle requieren presupuesto; pagina positiva y 1 a 20 registros.');
        }
        $budgetId = $budgetId === null ? null : strtolower($budgetId);
        return DB::connection('sqlsrv')->transaction(function () use ($budgetId,$business,$page,$perPage,$details,$settlements) {
            $db = DB::connection('sqlsrv');
            $selection = $db->table('institutional_bonus_budgets')->select('institutional_bonus_budgets.*')
                ->selectRaw('CAST(CAST(approved_amount_cents AS decimal(38,0))-CAST(committed_amount_cents AS decimal(38,0))-CAST(consumed_amount_cents AS decimal(38,0)) AS varchar(40)) AS available_amount_cents')
                ->orderBy('id')->lock('with (holdlock)');
            if ($budgetId !== null) $selection->where('public_id',$budgetId);
            else $selection->offset(($page-1)*$perPage);
            $budgets = $selection->limit($budgetId === null ? $perPage+1 : 1)->get();
            $moreBudgets = $budgetId === null && $budgets->count() > $perPage;
            $rows = []; $consistent = true;
            foreach ($budgets->take($perPage) as $budget) {
                $id = strtolower($budget->public_id);
                $scope = fn () => $this->bonuses($id);
                $debts = fn () => $db->table('institutional_bonus_obligations')->where('budget_id',$id);
                $totals = $debts()->selectRaw("CAST(COUNT_BIG(*) AS varchar(40)) AS obligation_count,
                    CAST(COALESCE(SUM(CAST(amount_cents AS decimal(38,0))),0) AS varchar(40)) AS purchase_amount_cents,
                    CAST(COALESCE(SUM(CAST(refunded_amount_cents AS decimal(38,0))),0) AS varchar(40)) AS refunded_amount_cents,
                    CAST(COALESCE(SUM(CAST(amount_cents AS decimal(38,0))-CAST(refunded_amount_cents AS decimal(38,0))),0) AS varchar(40)) AS net_consumed_amount_cents,
                    CAST(COALESCE(SUM(CAST(settled_amount_cents AS decimal(38,0))),0) AS varchar(40)) AS settled_amount_cents,
                    CAST(COALESCE(SUM(CAST(amount_cents AS decimal(38,0))-CAST(refunded_amount_cents AS decimal(38,0))-CAST(settled_amount_cents AS decimal(38,0))),0) AS varchar(40)) AS pending_amount_cents")->first();
                $journal = $db->table('bonus_ledger_entries as e')->whereIn('e.bonus_id',$scope()->select('b.public_id'));
                $ledgerNet = (clone $journal)->selectRaw("CAST(COALESCE(SUM(CASE WHEN e.movement_type IN ('CONSUMO','DEVOLUCION')
                    THEN -CAST(e.amount_cents AS decimal(38,0)) ELSE CAST(0 AS decimal(38,0)) END),0) AS varchar(40)) AS amount")->value('amount');
                $remaining = $scope()->selectRaw('CAST(COALESCE(SUM(CAST(b.remaining_amount_cents AS decimal(38,0))),0) AS varchar(40)) AS amount')->value('amount');
                $actualRefunds = $db->table('institutional_bonus_refunds as r')->join('institutional_bonus_obligations as o','o.public_id','=','r.obligation_id')
                    ->where('o.budget_id',$id)->selectRaw('CAST(COALESCE(SUM(CAST(r.amount_cents AS decimal(38,0))),0) AS varchar(40)) AS amount')->value('amount');
                $closedRefundTotal = $db->table('institutional_bonus_refunds as r')
                    ->join('institutional_bonus_obligations as o','o.public_id','=','r.obligation_id')
                    ->join('institutional_budget_operations as op','op.public_id','=','r.operation_id')
                    ->where('o.budget_id',$id)->where('op.action','REFUND_CLOSED_BECA')
                    ->selectRaw('CAST(COALESCE(SUM(CAST(r.amount_cents AS decimal(38,0))),0) AS varchar(40)) AS amount')->value('amount');
                $actualSettlements = $db->table('institutional_bonus_settlements as s')->join('institutional_bonus_obligations as o','o.public_id','=','s.obligation_id')
                    ->where('o.budget_id',$id)->selectRaw('CAST(COALESCE(SUM(CAST(s.amount_cents AS decimal(38,0))),0) AS varchar(40)) AS amount')->value('amount');
                $findings = [];
                $storedConsumed = (string)$budget->consumed_amount_cents; $storedCommitted = (string)$budget->committed_amount_cents;
                foreach ([['CONSUMED_VS_OBLIGATIONS',$storedConsumed,$totals->net_consumed_amount_cents],
                    ['CONSUMED_VS_LEDGER',$storedConsumed,$ledgerNet],['COMMITTED_VS_BONUS_BALANCES',$storedCommitted,$remaining],
                    ['REFUND_COUNTER_VS_EVIDENCE',$totals->refunded_amount_cents,$actualRefunds],
                    ['SETTLEMENT_COUNTER_VS_EVIDENCE',$totals->settled_amount_cents,$actualSettlements]] as [$code,$a,$b]) {
                    if ($a !== (string)$b) $findings[] = ['code'=>$code,'expected_cents'=>(string)$b,'stored_cents'=>$a];
                }
                // Scope includes either marker, issuance binding or obligation: a broken marker is not silently omitted.
                $badBonus = $scope()->leftJoin('bonus_budget_commitments as c','c.bonus_id','=','b.public_id')
                    ->leftJoin('institutional_budget_operations as i','i.public_id','=','c.issue_operation_id')
                    ->where(function ($q) use ($id) {
                        $q->whereNull('c.public_id')->orWhereNull('i.public_id')->orWhereNull('b.institutional_budget_id')
                            ->orWhere('b.institutional_budget_id','<>',$id)->orWhere('c.budget_id','<>',$id)->orWhere('i.budget_id','<>',$id)
                            ->orWhere('i.action','<>','ISSUE_BECA')->orWhereColumn('c.amount_cents','<>','b.original_amount_cents')
                            ->orWhere('b.type','<>','BECA')->orWhere('b.currency','<>','MXN')->orWhere('c.currency','<>','MXN')
                            ->orWhere('c.status','<>','COMMITTED')->orWhere('b.remaining_amount_cents','<',0)
                            ->orWhereColumn('b.remaining_amount_cents','>','b.original_amount_cents')
                            ->orWhereRaw("(b.status IN ('CANCELADO','EXPIRADO','AGOTADO') AND b.remaining_amount_cents <> 0)")
                            ->orWhereRaw("COALESCE(JSON_VALUE(CASE WHEN ISJSON(i.after_data)=1 THEN i.after_data ELSE '{}' END,'$.bonus_id'),'') <> LOWER(CONVERT(varchar(36),b.public_id))");
                    });
                $this->finding($findings,'BONUS_BINDING',$badBonus,'b.public_id');
                $badDebt = $db->table('institutional_bonus_obligations as o')->where('o.budget_id',$id)
                    ->leftJoin('purchase_payments as p','p.public_id','=','o.purchase_payment_id')
                    ->leftJoin('bonuses as b','b.public_id','=','o.bonus_id')->leftJoin('wallets as w','w.public_id','=','p.wallet_id')
                    ->leftJoin('institutional_budget_operations as op','op.public_id','=','o.operation_id')
                    ->where(function ($q) use ($id,$budget) {
                        $q->whereNull('p.public_id')->orWhereNull('b.public_id')->orWhereNull('op.public_id')->orWhereNull('w.public_id')
                            ->orWhere('w.owner_type','<>','USER')->orWhereColumn('w.owner_id','<>','b.beneficiary_id')
                            ->orWhereNull('b.institutional_budget_id')->orWhere('b.institutional_budget_id','<>',$id)
                            ->orWhereColumn('p.bonus_id','<>','o.bonus_id')->orWhereNull('p.bonus_id')
                            ->orWhereColumn('p.business_id','<>','o.business_id')->orWhereNull('p.business_id')
                            ->orWhereColumn('p.total_amount_cents','<>','o.amount_cents')->orWhereColumn('p.bonus_amount_cents','<>','o.amount_cents')
                            ->orWhere('p.wallet_amount_cents','<>',0)->orWhere('p.status','<>','COMPLETADO')
                            ->orWhereRaw('o.settled_amount_cents <> (SELECT COALESCE(SUM(CAST(s.amount_cents AS decimal(38,0))),0) FROM institutional_bonus_settlements s WHERE s.obligation_id=o.public_id)')
                            ->orWhereRaw('o.settled_amount_cents > o.amount_cents - o.refunded_amount_cents')
                            ->orWhere('o.currency','<>','MXN')->orWhere('p.currency','<>','MXN')->orWhere('o.status','<>','OPEN')
                            ->orWhere('o.payer_reference','<>',$budget->payer_reference)->orWhere('op.action','<>','CONSUME_BECA')
                            ->orWhere('op.budget_id','<>',$id)
                            ->orWhereRaw("COALESCE(JSON_VALUE(CASE WHEN ISJSON(op.after_data)=1 THEN op.after_data ELSE '{}' END,'$.purchase_payment_id'),'') <> LOWER(CONVERT(varchar(36),p.public_id))")
                            ->orWhereRaw('o.refunded_amount_cents <> (SELECT COALESCE(SUM(CAST(r.amount_cents AS decimal(38,0))),0) FROM institutional_bonus_refunds r WHERE r.obligation_id=o.public_id)');
                    });
                $this->finding($findings,'OBLIGATION_EVIDENCE',$badDebt,'o.public_id');
                $missing = $db->table('purchase_payments as p')->whereIn('p.bonus_id',$scope()->select('b.public_id'))
                    ->where('p.bonus_amount_cents','>',0)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('institutional_bonus_obligations as o')->whereColumn('o.purchase_payment_id','p.public_id'));
                $this->finding($findings,'PURCHASE_WITHOUT_OBLIGATION',$missing,'p.public_id');
                $badRefund = $db->table('institutional_bonus_refunds as r')->join('institutional_bonus_obligations as o','o.public_id','=','r.obligation_id')
                    ->where('o.budget_id',$id)->leftJoin('purchase_refund_requests as f','f.public_id','=','r.refund_request_id')
                    ->leftJoin('institutional_budget_operations as op','op.public_id','=','r.operation_id')
                    ->leftJoin('financial_transactions as t','t.public_id','=','f.financial_transaction_id')
                    ->where(function ($q) use ($id) {
                        $q->whereNull('f.public_id')->orWhereNull('op.public_id')->orWhereNull('t.public_id')
                            ->orWhereColumn('f.purchase_payment_id','<>','o.purchase_payment_id')->orWhere('f.status','<>','COMPLETADA')
                            ->orWhere('f.wallet_amount_cents','<>',0)->orWhereColumn('f.total_amount_cents','<>','r.amount_cents')
                            ->orWhereColumn('f.bonus_amount_cents','<>','r.amount_cents')->orWhere('f.currency','<>','MXN')
                            ->orWhereNotIn('op.action',['REFUND_BECA','REFUND_CLOSED_BECA'])->orWhere('op.budget_id','<>',$id)
                            ->orWhere('t.status','<>','COMPLETADA')->orWhere('t.reference_type','<>','PURCHASE_REFUND')
                            ->orWhereColumn('t.reference_id','<>','r.refund_request_id')
                            ->orWhereRaw("(SELECT COUNT_BIG(*) FROM purchase_refund_bonuses a WHERE a.refund_request_id=f.public_id AND a.bonus_id=o.bonus_id AND a.amount_cents=r.amount_cents) <> 1");
                    });
                $this->finding($findings,'REFUND_EVIDENCE',$badRefund,'r.public_id');
                // Terminal refunds must have both the return and an immediate, fully evidenced
                // release. Do not silently accept a counter without the second ledger movement.
                $closedRefund = $db->table('institutional_bonus_refunds as r')
                    ->join('institutional_bonus_obligations as o','o.public_id','=','r.obligation_id')
                    ->join('institutional_budget_operations as op','op.public_id','=','r.operation_id')
                    ->join('bonuses as b','b.public_id','=','o.bonus_id')
                    ->join('purchase_refund_requests as f','f.public_id','=','r.refund_request_id')
                    ->join('financial_transactions as t','t.public_id','=','f.financial_transaction_id')
                    ->where('o.budget_id',$id)->where('op.action','REFUND_CLOSED_BECA')
                    ->where(function ($q) {
                        $q->whereNotIn('b.status',['CANCELADO','EXPIRADO','AGOTADO'])->orWhere('b.remaining_amount_cents','<>',0)
                            ->orWhereRaw("(b.status='AGOTADO' AND (b.expires_at IS NULL OR b.expires_at > ?))",[now()])
                            ->orWhereRaw("COALESCE(JSON_VALUE(CASE WHEN ISJSON(op.after_data)=1 THEN op.after_data ELSE '{}' END,'$.refund_destination'),'') <> 'BUDGET_AVAILABLE'")
                            ->orWhereRaw("COALESCE(JSON_VALUE(CASE WHEN ISJSON(op.after_data)=1 THEN op.after_data ELSE '{}' END,'$.terminal_bonus_status'),'') <> b.status")
                            ->orWhereRaw("COALESCE(TRY_CONVERT(decimal(38,0),JSON_VALUE(CASE WHEN ISJSON(op.after_data)=1 THEN op.after_data ELSE '{}' END,'$.restored_amount_cents')),-1) <> 0")
                            ->orWhereRaw("COALESCE(TRY_CONVERT(decimal(38,0),JSON_VALUE(CASE WHEN ISJSON(op.after_data)=1 THEN op.after_data ELSE '{}' END,'$.released_refund_amount_cents')),-1) <> CAST(r.amount_cents AS decimal(38,0))")
                            ->orWhereRaw("COALESCE(JSON_VALUE(CASE WHEN ISJSON(t.metadata)=1 THEN t.metadata ELSE '{}' END,'$.refund_destination'),'') <> 'BUDGET_AVAILABLE'")
                            ->orWhereRaw("COALESCE(TRY_CONVERT(decimal(38,0),JSON_VALUE(CASE WHEN ISJSON(t.metadata)=1 THEN t.metadata ELSE '{}' END,'$.restored_bonus_amount_cents')),-1) <> 0")
                            ->orWhereRaw("COALESCE(TRY_CONVERT(decimal(38,0),JSON_VALUE(CASE WHEN ISJSON(t.metadata)=1 THEN t.metadata ELSE '{}' END,'$.released_budget_amount_cents')),-1) <> CAST(r.amount_cents AS decimal(38,0))")
                            ->orWhereRaw("(SELECT COUNT_BIG(*) FROM bonus_ledger_entries e WHERE e.bonus_id=o.bonus_id AND e.reference_type='BUDGET_REFUND_RELEASE' AND e.reference_id=r.refund_request_id AND e.movement_type=CASE WHEN b.status='CANCELADO' THEN 'CANCELACION' ELSE 'EXPIRACION' END AND CAST(e.amount_cents AS decimal(38,0))=-CAST(r.amount_cents AS decimal(38,0)) AND e.remaining_after_cents=0) <> 1");
                    });
                $this->finding($findings,'CLOSED_REFUND_RELEASE_EVIDENCE',$closedRefund,'r.public_id');
                // Window arithmetic remains DECIMAL, including gross turnover larger than a PHP integer.
                $running = (clone $journal)->join('bonuses as b','b.public_id','=','e.bonus_id')->select('e.public_id','e.amount_cents','e.remaining_after_cents','b.original_amount_cents')
                    ->selectRaw('SUM(CAST(e.amount_cents AS decimal(38,0))) OVER (PARTITION BY e.bonus_id ORDER BY e.id ROWS UNBOUNDED PRECEDING) AS running_balance');
                $badJournal = $db->query()->fromSub($running,'j')->where(function ($q) {
                    $q->whereRaw('CAST(j.remaining_after_cents AS decimal(38,0)) <> j.running_balance')
                        ->orWhere('j.running_balance','<',0)->orWhereColumn('j.running_balance','>','j.original_amount_cents');
                });
                $this->finding($findings,'LEDGER_RUNNING_BALANCE',$badJournal,'j.public_id');
                $missingLedger = $scope()->where(function ($q) {
                    $q->whereNotExists(fn ($s) => $s->selectRaw('1')->from('bonus_ledger_entries as e')->whereColumn('e.bonus_id','b.public_id'))
                        ->orWhereRaw('CAST(b.remaining_amount_cents AS decimal(38,0)) <> (SELECT COALESCE(SUM(CAST(e.amount_cents AS decimal(38,0))),0) FROM bonus_ledger_entries e WHERE e.bonus_id=b.public_id)');
                });
                $this->finding($findings,'BONUS_VS_LEDGER_BALANCE',$missingLedger,'b.public_id');
                $missingMovement = $debts()->from('institutional_bonus_obligations as o')->where('o.budget_id',$id)
                    ->whereRaw("(SELECT COUNT_BIG(*) FROM bonus_ledger_entries e WHERE e.bonus_id=o.bonus_id AND e.reference_type='BUDGET_PURCHASE' AND e.reference_id=o.purchase_payment_id AND e.movement_type='CONSUMO' AND CAST(e.amount_cents AS decimal(38,0))=-CAST(o.amount_cents AS decimal(38,0))) <> 1");
                $this->finding($findings,'OBLIGATION_WITHOUT_MOVEMENT',$missingMovement,'o.public_id');
                $refundMovement = $db->table('institutional_bonus_refunds as r')->join('institutional_bonus_obligations as o','o.public_id','=','r.obligation_id')->where('o.budget_id',$id)
                    ->whereRaw("(SELECT COUNT_BIG(*) FROM bonus_ledger_entries e WHERE e.bonus_id=o.bonus_id AND e.reference_type='PURCHASE_REFUND' AND e.reference_id=r.refund_request_id AND e.movement_type='DEVOLUCION' AND e.amount_cents=r.amount_cents) <> 1");
                $this->finding($findings,'REFUND_WITHOUT_MOVEMENT',$refundMovement,'r.public_id');
                $orphanJournal = (clone $journal)->whereRaw("(NOT (
                    (e.movement_type='EMISION' AND e.reference_type='BONUS_ISSUANCE' AND e.reference_id=e.bonus_id)
                    OR (e.movement_type='CONSUMO' AND e.reference_type='BUDGET_PURCHASE' AND EXISTS
                        (SELECT 1 FROM institutional_bonus_obligations o WHERE o.bonus_id=e.bonus_id AND o.purchase_payment_id=e.reference_id AND CAST(o.amount_cents AS decimal(38,0))=-CAST(e.amount_cents AS decimal(38,0))))
                    OR (e.movement_type='DEVOLUCION' AND e.reference_type='PURCHASE_REFUND' AND EXISTS
                        (SELECT 1 FROM institutional_bonus_refunds r JOIN institutional_bonus_obligations o ON o.public_id=r.obligation_id WHERE o.bonus_id=e.bonus_id AND r.refund_request_id=e.reference_id AND r.amount_cents=e.amount_cents))
                    OR (e.movement_type IN ('CANCELACION','EXPIRACION') AND e.reference_type='BUDGET_REFUND_RELEASE' AND EXISTS
                        (SELECT 1 FROM institutional_bonus_refunds r JOIN institutional_bonus_obligations o ON o.public_id=r.obligation_id
                        JOIN institutional_budget_operations op ON op.public_id=r.operation_id JOIN bonuses b ON b.public_id=o.bonus_id
                        WHERE o.bonus_id=e.bonus_id AND r.refund_request_id=e.reference_id AND op.action='REFUND_CLOSED_BECA'
                        AND e.movement_type=CASE WHEN b.status='CANCELADO' THEN 'CANCELACION' WHEN b.status IN ('EXPIRADO','AGOTADO') THEN 'EXPIRACION' ELSE '' END
                        AND CAST(e.amount_cents AS decimal(38,0))=-CAST(r.amount_cents AS decimal(38,0))))
                    OR (e.movement_type IN ('CANCELACION','EXPIRACION') AND e.reference_type='BUDGET_RELEASE' AND EXISTS
                        (SELECT 1 FROM institutional_budget_operations op WHERE op.public_id=e.reference_id AND op.budget_id=? AND op.action IN ('CANCEL_BECA','EXPIRE_BECA')))
                ) OR e.reference_type IS NULL OR e.reference_id IS NULL)",[$id]);
                $this->finding($findings,'LEDGER_WITHOUT_EVIDENCE',$orphanJournal,'e.public_id');
                $missingRefund = $db->table('purchase_refund_requests as f')->where('f.status','COMPLETADA')->where('f.bonus_amount_cents','>',0)
                    ->whereIn('f.purchase_payment_id',$db->table('purchase_payments as p')->whereIn('p.bonus_id',$scope()->select('b.public_id'))->select('p.public_id'))
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('institutional_bonus_refunds as r')->whereColumn('r.refund_request_id','f.public_id'));
                $this->finding($findings,'COMPLETED_REFUND_WITHOUT_EVIDENCE',$missingRefund,'f.public_id');
                $badSettlement = $db->table('institutional_bonus_settlements as s')
                    ->leftJoin('institutional_bonus_obligations as o','o.public_id','=','s.obligation_id')
                    ->leftJoin('purchase_payments as p','p.public_id','=','o.purchase_payment_id')
                    ->leftJoin('institutional_budget_operations as op','op.public_id','=','s.operation_id')
                    ->where(fn ($q) => $q->where('s.budget_id',$id)->orWhere('o.budget_id',$id))
                    ->where(function ($q) {
                        $q->whereNull('o.public_id')->orWhereNull('op.public_id')->orWhereNull('p.public_id')
                            ->orWhereColumn('s.budget_id','<>','o.budget_id')->orWhereColumn('op.budget_id','<>','o.budget_id')
                            ->orWhere('op.action','<>','SETTLE_BECA')->orWhereColumn('s.currency','<>','o.currency')
                            ->orWhereRaw("COALESCE(JSON_VALUE(CASE WHEN ISJSON(op.after_data)=1 THEN op.after_data ELSE '{}' END,'$.settlement_id'),'') <> LOWER(CONVERT(varchar(36),s.public_id))")
                            ->orWhereRaw("COALESCE(JSON_VALUE(CASE WHEN ISJSON(op.after_data)=1 THEN op.after_data ELSE '{}' END,'$.obligation_id'),'') <> LOWER(CONVERT(varchar(36),s.obligation_id))")
                            ->orWhereRaw("COALESCE(JSON_VALUE(CASE WHEN ISJSON(op.after_data)=1 THEN op.after_data ELSE '{}' END,'$.paid_at'),'') <> s.paid_at_utc")
                            ->orWhereRaw("COALESCE(JSON_VALUE(CASE WHEN ISJSON(op.after_data)=1 THEN op.after_data ELSE '{}' END,'$.payment_key'),'') <> s.payment_key")
                            ->orWhereRaw("COALESCE(JSON_VALUE(CASE WHEN ISJSON(op.after_data)=1 THEN op.after_data ELSE '{}' END,'$.recipient_reference'),'') <> s.recipient_reference")
                            ->orWhereRaw("COALESCE(JSON_VALUE(CASE WHEN ISJSON(op.after_data)=1 THEN op.after_data ELSE '{}' END,'$.payment_provider'),'') <> s.payment_provider")
                            ->orWhereRaw("COALESCE(JSON_VALUE(CASE WHEN ISJSON(op.after_data)=1 THEN op.after_data ELSE '{}' END,'$.payment_reference'),'') <> s.payment_reference")
                            ->orWhereRaw("COALESCE(TRY_CONVERT(decimal(38,0),JSON_VALUE(CASE WHEN ISJSON(op.after_data)=1 THEN op.after_data ELSE '{}' END,'$.settled_delta_cents')),-1) <> CAST(s.amount_cents AS decimal(38,0))")
                            ->orWhereRaw('TRY_CONVERT(datetimeoffset(6),s.paid_at_utc) IS NULL')
                            ->orWhereRaw('TRY_CONVERT(datetimeoffset(6),s.paid_at_utc) < CAST(p.created_at AS datetimeoffset(6))')
                            ->orWhereRaw('TRY_CONVERT(datetimeoffset(6),s.paid_at_utc) > TRY_CONVERT(datetimeoffset(6),?)',[now()->toISOString()]);
                    });
                $this->finding($findings,'SETTLEMENT_EVIDENCE',$badSettlement,'s.public_id');
                $missingSettlement = $db->table('institutional_budget_operations as op')->where('op.budget_id',$id)->where('op.action','SETTLE_BECA')
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('institutional_bonus_settlements as s')->whereColumn('s.operation_id','op.public_id'));
                $this->finding($findings,'SETTLEMENT_WITHOUT_RECORD',$missingSettlement,'op.public_id');
                if ($budget->currency !== 'MXN' && $totals->obligation_count !== '0') $findings[] = ['code'=>'BUDGET_CURRENCY'];
                $clean = $findings === []; $consistent = $consistent && $clean;
                $rows[] = ['budget_id'=>$id,'currency'=>$budget->currency,'status'=>$budget->status,'version'=>(string)$budget->version,
                    'approved_amount_cents'=>(string)$budget->approved_amount_cents,'available_amount_cents'=>(string)$budget->available_amount_cents,'committed_amount_cents'=>$storedCommitted,
                    'consumed_amount_cents'=>$storedConsumed,'observed_bonus_balances_cents'=>(string)$remaining,
                    'observed_ledger_consumed_cents'=>(string)$ledgerNet,'observed_refund_evidence_cents'=>(string)$actualRefunds,'observed_closed_refund_release_cents'=>(string)$closedRefundTotal,'observed_settlement_evidence_cents'=>(string)$actualSettlements,
                    'obligations'=>(array)$totals,'consistent'=>$clean,'findings'=>$findings];
            }
            $entries = []; $more = false;
            if ($budgetId !== null && $rows !== []) {
                if ($settlements) {
                    $q = $db->table('institutional_bonus_settlements as s')->join('institutional_bonus_obligations as o','o.public_id','=','s.obligation_id')
                        ->where('o.budget_id',$budgetId);
                    if ($business !== null) $q->where('o.business_id',$business);
                    $q->selectRaw("LOWER(CONVERT(varchar(36),s.public_id)) AS settlement_id, LOWER(CONVERT(varchar(36),s.obligation_id)) AS obligation_id,
                        LOWER(CONVERT(varchar(36),s.operation_id)) AS operation_id, o.business_id,s.currency,
                        CAST(s.amount_cents AS varchar(40)) AS amount_cents,s.payment_provider,s.payment_reference,s.paid_at_utc")->orderBy('s.id');
                } else {
                $q = $db->table('institutional_bonus_obligations')->where('budget_id',$budgetId);
                if ($business !== null) $q->where('business_id',$business);
                if ($details) $q->selectRaw("LOWER(CONVERT(varchar(36),public_id)) AS obligation_id, LOWER(CONVERT(varchar(36),purchase_payment_id)) AS purchase_payment_id,
                    LOWER(CONVERT(varchar(36),bonus_id)) AS bonus_id, business_id,currency,
                    CAST(amount_cents AS varchar(40)) AS purchase_amount_cents,CAST(refunded_amount_cents AS varchar(40)) AS refunded_amount_cents,
                    CAST(CAST(amount_cents AS decimal(38,0))-CAST(refunded_amount_cents AS decimal(38,0)) AS varchar(40)) AS net_consumed_amount_cents,
                    CAST(settled_amount_cents AS varchar(40)) AS settled_amount_cents,
                    CAST(CAST(amount_cents AS decimal(38,0))-CAST(refunded_amount_cents AS decimal(38,0))-CAST(settled_amount_cents AS decimal(38,0)) AS varchar(40)) AS pending_amount_cents")->orderBy('id');
                else $q->selectRaw("business_id,currency,CAST(COUNT_BIG(*) AS varchar(40)) AS obligation_count,
                    CAST(SUM(CAST(amount_cents AS decimal(38,0))) AS varchar(40)) AS purchase_amount_cents,
                    CAST(SUM(CAST(refunded_amount_cents AS decimal(38,0))) AS varchar(40)) AS refunded_amount_cents,
                    CAST(SUM(CAST(amount_cents AS decimal(38,0))-CAST(refunded_amount_cents AS decimal(38,0))) AS varchar(40)) AS net_consumed_amount_cents,
                    CAST(SUM(CAST(settled_amount_cents AS decimal(38,0))) AS varchar(40)) AS settled_amount_cents,
                    CAST(SUM(CAST(amount_cents AS decimal(38,0))-CAST(refunded_amount_cents AS decimal(38,0))-CAST(settled_amount_cents AS decimal(38,0))) AS varchar(40)) AS pending_amount_cents")
                    ->groupBy('business_id','currency')->orderBy('business_id')->orderBy('currency');
                }
                $entries = $q->offset(($page-1)*$perPage)->limit($perPage+1)->get(); $more = $entries->count() > $perPage;
                $entries = $entries->take($perPage)->map(fn ($r) => (array)$r)->all();
            }
            return ['observed_at'=>now()->toISOString(),'basis'=>'COORDINATED_CURRENT_READ','reconciliation_scope'=>'SELECTED_BUDGETS_ALL_BUSINESSES',
                'business_filter_applies_to_entries_only'=>true,'budget_id'=>$budgetId,'business_id'=>$business,'money_moved'=>false,
                'cash_backing_certified'=>false,'settlement_implemented'=>true,'settlement_mode'=>'EXTERNAL_PAYMENT_RECORDS','status'=>$rows === [] ? 'NO_DATA' : ($consistent ? 'CONSISTENT' : 'DIFFERENCES'),
                'consistent'=>$rows === [] ? null : $consistent,'budgets'=>$rows,
                'entries_kind'=>$settlements?'SETTLEMENTS':($details?'OBLIGATIONS':'BUSINESSES'),'entries'=>$entries,
                'page'=>$page,'per_page'=>$perPage,'has_more'=>$budgetId === null ? $moreBudgets : $more];
        });
    }

    private function bonuses(string $budgetId)
    {
        return DB::connection('sqlsrv')->table('bonuses as b')->where(function ($q) use ($budgetId) {
            $q->where('b.institutional_budget_id',$budgetId)
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('bonus_budget_commitments as c')->whereColumn('c.bonus_id','b.public_id')->where('c.budget_id',$budgetId))
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('institutional_bonus_obligations as o')->whereColumn('o.bonus_id','b.public_id')->where('o.budget_id',$budgetId));
        });
    }
    private function finding(array &$out, string $code, $query, string $column): void
    {
        $sample = $query->selectRaw("LOWER(CONVERT(varchar(36),$column)) AS entity_id, CAST(COUNT_BIG(*) OVER () AS varchar(40)) AS anomaly_count")
            ->orderBy($column)->limit(5)->get();
        if ($sample->isNotEmpty()) $out[] = ['code'=>$code,'count'=>(string)$sample->first()->anomaly_count,'sample_ids'=>$sample->pluck('entity_id')->all()];
    }
}
