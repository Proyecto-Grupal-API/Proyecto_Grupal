<?php
use App\Domains\Financial\Models\{Wallet,PurchasePayment,LedgerEntry,FinancialTransaction};
use App\Domains\Financial\Services\{PurchasePaymentService,PurchaseRefundService,PersonalReceiptQueryService,LedgerService};
use App\Domains\Financial\Enums\MovementType;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

beforeEach(function(){
    if(!app()->environment('testing') || config('database.connections.sqlsrv.database')!=='campus_digital_financial_testing'
        || config('database.connections.mongodb.database')!=='campus_virtual_testing') throw new RuntimeException('Solo bases de pruebas.');
    DB::connection('sqlsrv')->beginTransaction();Http::preventStrayRequests();config(['financial_team4.enabled'=>false]);
});
afterEach(function(){while(DB::connection('sqlsrv')->transactionLevel()>0)DB::connection('sqlsrv')->rollBack();});
function wpoWallet(int $balance=10000):Wallet {
    return Wallet::create(['public_id'=>(string)Str::uuid(),'owner_type'=>'USER','owner_id'=>'fixture-wpo-'.Str::uuid(),
        'type'=>'WALLET_USUARIO','currency'=>'MXN','status'=>'ACTIVA','available_balance_cents'=>$balance,'held_balance_cents'=>0]);
}
function wpoContext():array {return ['order_id'=>'ORDER-'.Str::uuid(),'business_id'=>'BUS-WPO','items'=>[]];}
function wpoEvent(string $purchase,int $version):array {
    $stream=DB::connection('sqlsrv')->table('financial_team4_streams')->where('payment_intent_id',$purchase)->value('id');
    return json_decode(DB::connection('sqlsrv')->table('financial_team4_outbox')->where('stream_id',$stream)->where('event_version',$version)->value('body'),true,512,JSON_THROW_ON_ERROR);
}
test('wallet purchase registers a genuine purchase debit and PAID without inventing a bonus',function(){
    $w=wpoWallet();$c=wpoContext();$key='wpo-'.Str::uuid();
    $r=app(PurchasePaymentService::class)->payWithWallet($w,2500,$key,'BUS-WPO',null,$c);
    $p=PurchasePayment::where('public_id',$r['purchase_payment_id'])->firstOrFail();
    expect($p->bonus_id)->toBeNull()->and($p->bonus_amount_cents)->toBe(0)->and($p->bonuses()->count())->toBe(0)
        ->and($w->fresh()->available_balance_cents)->toBe(7500)->and(wpoEvent($r['purchase_payment_id'],1)['status'])->toBe('PAID');
    $t=FinancialTransaction::where('idempotency_key',$key)->firstOrFail();
    expect(strtolower($t->reference_id))->toBe($r['purchase_payment_id'])->and($t->reference_type)->toBe('PURCHASE')
        ->and(LedgerEntry::where('transaction_id',$t->public_id)->firstOrFail()->amount_cents)->toBe(-2500);Http::assertNothingSent();
});
test('wallet purchase retry returns the same result without duplicate money or event',function(){
    $w=wpoWallet();$c=wpoContext();$key='wpo-'.Str::uuid();$s=app(PurchasePaymentService::class);
    $first=$s->payWithWallet($w,2500,$key,'BUS-WPO',null,$c);
    expect($s->payWithWallet($w,2500,$key,'BUS-WPO',null,$c))->toBe($first)
        ->and($w->fresh()->available_balance_cents)->toBe(7500)
        ->and(FinancialTransaction::where('idempotency_key',$key)->count())->toBe(1);
    $stream=DB::connection('sqlsrv')->table('financial_team4_streams')->where('payment_intent_id',$first['purchase_payment_id'])->value('id');
    expect(DB::connection('sqlsrv')->table('financial_team4_outbox')->where('stream_id',$stream)->count())->toBe(1);
    expect(fn()=>$s->payWithWallet($w,2501,$key,'BUS-WPO',null,$c))->toThrow(InvalidArgumentException::class);
    expect(fn()=>$s->payWithWallet($w,2500,$key,'OTHER',null,$c))->toThrow(InvalidArgumentException::class);
    expect(fn()=>$s->payWithWallet(wpoWallet(),2500,$key,'BUS-WPO',null,$c))->toThrow(InvalidArgumentException::class);
    expect(fn()=>$s->payWithWallet($w,2500,$key,'BUS-WPO',null,[...$c,'order_id'=>'OTHER']))->toThrow(ConflictHttpException::class);
});
test('wallet purchase validates positive amount key active status and wallet type',function(){
    $w=wpoWallet();$s=app(PurchasePaymentService::class);
    foreach([0,-1] as $amount)expect(fn()=>$s->payWithWallet($w,$amount,'wpo-'.Str::uuid()))->toThrow(InvalidArgumentException::class);
    expect(fn()=>$s->payWithWallet($w,1,' '))->toThrow(InvalidArgumentException::class);
    $w->update(['status'=>'BLOQUEADA']);
    expect(fn()=>$s->payWithWallet($w,1,'wpo-'.Str::uuid()))->toThrow(InvalidArgumentException::class);
    $w->update(['status'=>'ACTIVA','type'=>'WALLET_NEGOCIO']);
    expect(fn()=>$s->payWithWallet($w,1,'wpo-'.Str::uuid()))->toThrow(InvalidArgumentException::class);
    expect($w->fresh()->available_balance_cents)->toBe(10000);
});
test('wallet purchase cannot spend held funds and insufficient funds leave no purchase',function(){
    $w=wpoWallet(100);$w->update(['held_balance_cents'=>9000]);$key='wpo-'.Str::uuid();
    expect(fn()=>app(PurchasePaymentService::class)->payWithWallet($w,101,$key))->toThrow(InvalidArgumentException::class);
    expect(PurchasePayment::where('idempotency_key',$key)->exists())->toBeFalse()
        ->and(FinancialTransaction::where('idempotency_key',$key)->exists())->toBeFalse()
        ->and($w->fresh()->available_balance_cents)->toBe(100)->and($w->fresh()->held_balance_cents)->toBe(9000);
});
test('wallet purchase event failure rolls back its ledger purchase and balance',function(){
    $w=wpoWallet();$key='wpo-'.Str::uuid();$c=wpoContext();$c['items']=[['product_id'=>'P','location_id'=>'L','quantity'=>0]];
    expect(fn()=>app(PurchasePaymentService::class)->payWithWallet($w,2000,$key,'BUS-WPO',null,$c))->toThrow(InvalidArgumentException::class);
    expect($w->fresh()->available_balance_cents)->toBe(10000)->and(PurchasePayment::where('idempotency_key',$key)->exists())->toBeFalse()
        ->and(FinancialTransaction::where('idempotency_key',$key)->exists())->toBeFalse();
});
test('wallet purchase refuses a financial key from another operation',function(){
    $w=wpoWallet();$key='wpo-'.Str::uuid();
    app(LedgerService::class)->credit($w,100,MovementType::RECARGA,$key,'FIXTURE','seed');
    expect(fn()=>app(PurchasePaymentService::class)->payWithWallet($w,100,$key))->toThrow(InvalidArgumentException::class);
    expect($w->fresh()->available_balance_cents)->toBe(10100)->and(PurchasePayment::where('idempotency_key',$key)->exists())->toBeFalse();
});
test('wallet purchase completes partial and full refunds with delta events and no bonus allocations',function(){
    $w=wpoWallet();$c=wpoContext();$r=app(PurchasePaymentService::class)->payWithWallet($w,3000,'wpo-'.Str::uuid(),'BUS-WPO',null,$c);
    $p=PurchasePayment::where('public_id',$r['purchase_payment_id'])->firstOrFail();$s=app(PurchaseRefundService::class);
    $refund=$s->request($p,1000,[],'wpo-request-'.Str::uuid(),'buyer');$s->approve($refund,'reviewer');
    $key='wpo-execute-'.Str::uuid();$s->complete($refund,$key,'executor');$s->complete($refund->fresh(),$key,'executor');
    expect($w->fresh()->available_balance_cents)->toBe(8000)->and(wpoEvent($r['purchase_payment_id'],2)['status'])->toBe('PARTIALLY_REFUNDED');
    $refund2=$s->request($p,2000,[],'wpo-request-'.Str::uuid(),'buyer');$s->approve($refund2,'reviewer');$s->complete($refund2,'wpo-execute-'.Str::uuid(),'executor');
    $event=wpoEvent($r['purchase_payment_id'],3);
    expect($w->fresh()->available_balance_cents)->toBe(10000)->and($event['status'])->toBe('REFUNDED')->and((float)$event['amount'])->toBe(20.0)->and($event['items'])->toBe([]);
});
test('wallet purchase receipt is listed once and reuses original accounting evidence',function(){
    $w=wpoWallet();$r=app(PurchasePaymentService::class)->payWithWallet($w,1500,'wpo-'.Str::uuid(),'BUS-WPO');
    $s=app(PersonalReceiptQueryService::class);$receipt=$s->purchase($w,$r['purchase_payment_id']);
    expect($receipt['purchase']['bonuses'])->toBe([])->and($receipt['purchase']['wallet_amount_cents'])->toBe(1500)
        ->and(count($receipt['entries']))->toBe(1)->and($s->listing($w)->total())->toBe(1)
        ->and($w->fresh()->available_balance_cents)->toBe(8500);
});
test('wallet purchase rejects corrupted replay evidence without another debit',function(){
    $w=wpoWallet();$key='wpo-'.Str::uuid();$s=app(PurchasePaymentService::class);$s->payWithWallet($w,1000,$key);
    $t=FinancialTransaction::where('idempotency_key',$key)->firstOrFail();
    DB::connection('sqlsrv')->table('ledger_entries')->where('transaction_id',$t->public_id)->update(['amount_cents'=>-999]);
    expect(fn()=>$s->payWithWallet($w,1000,$key))->toThrow(InvalidArgumentException::class);
    expect($w->fresh()->available_balance_cents)->toBe(9000);
});
test('wallet purchase enforces configured payment limits and preserves blocked attempt evidence',function(){
    $w=wpoWallet();$key='wpo-'.Str::uuid();
    app(\App\Domains\Financial\Services\FinancialLimitService::class)->create([
        'name'=>'Fixture payment limit','subject_type'=>'OWNER','subject_id'=>$w->owner_id,'operation'=>'PAGO',
        'period'=>'OPERACION','metric'=>'MONTO','max_amount_cents'=>100,'action'=>'BLOQUEAR',
        'currency'=>'MXN','active'=>true,'reason'=>'Fixture configured limit',
    ],'fixture-admin');
    expect(fn()=>app(PurchasePaymentService::class)->payWithWallet($w,101,$key))->toThrow(\App\Domains\Financial\Exceptions\FinancialLimitExceededException::class);
    expect($w->fresh()->available_balance_cents)->toBe(10000)->and(PurchasePayment::where('idempotency_key',$key)->exists())->toBeFalse()
        ->and(\App\Domains\Financial\Models\TransactionAlert::where('wallet_id',$w->public_id)->where('reference_type','PURCHASE_WALLET_ATTEMPT')->exists())->toBeTrue();
});
