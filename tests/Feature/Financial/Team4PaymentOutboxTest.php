<?php
use App\Domains\Financial\Integrations\Team4\{PaymentEventOutbox,Team4WebhookDelivery,WebhookSigner,PaymentEventPayload};
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Illuminate\Http\Client\ConnectionException;

beforeEach(function(){
    if(!app()->environment('testing')||config('database.connections.sqlsrv.database')!=='campus_digital_financial_testing'
        ||config('database.connections.mongodb.database')!=='campus_virtual_testing')throw new RuntimeException('Solo bases de pruebas.');
    DB::connection('sqlsrv')->beginTransaction();
    config(['financial_team4.enabled'=>true,'financial_team4.url'=>'http://inventory.test/api/equipo4/webhooks/payment-status','financial_team4.secret'=>'fixture-not-a-real-team-secret']);
    Http::preventStrayRequests();
});
afterEach(function(){while(DB::connection('sqlsrv')->transactionLevel()>0)DB::connection('sqlsrv')->rollBack();});
function t4oPayload(string $pi=''): array {return ['payment_intent_id'=>$pi?:'PI-'.Str::uuid(),'order_id'=>'ORDER-'.Str::uuid(),'business_id'=>'BUS-TEST','status'=>'PAID','amount_cents'=>34900,'occurred_at'=>'2026-10-21T12:00:00Z'];}
function t4oRow(string $id):object{return DB::connection('sqlsrv')->table('financial_team4_outbox')->where('event_id',$id)->first();}
function t4oAck():void{Http::fake(['inventory.test/*'=>Http::response(['received'=>true,'payment_event_id'=>'PE-FIXTURE'],202)]);}

test('team4 serializer signs exact UTF8 body with numeric cents and stable item normalization',function(){
    $p=t4oPayload();$p['amount_cents']=10001;$p['business_id']='Negocio-á';$c=PaymentEventPayload::canonical($p);
    $body=PaymentEventPayload::body($c,'event-test',1);expect($body)->toContain('"amount":100.01');
    $h=app(WebhookSigner::class)->headers($body,'/api/equipo4/webhooks/payment-status',1792584000,'fixture-secret');
    $expected=hash_hmac('sha256','1792584000|POST|/api/equipo4/webhooks/payment-status|'.hash('sha256',$body),'fixture-secret');
    expect($h['X-Team-Signature'])->toBe($expected)->and($h['X-Team-Timestamp'])->toBe('1792584000');
    expect(fn()=>PaymentEventPayload::canonical([...$p,'amount_cents'=>100.01]))->toThrow(InvalidArgumentException::class);
    expect(fn()=>PaymentEventPayload::canonical([...$p,'status'=>'REFUNDED']))->toThrow(InvalidArgumentException::class);
});
test('team4 record requires financial transaction and rolls back its event with that transaction',function(){
    DB::connection('sqlsrv')->rollBack();
    expect(fn()=>app(PaymentEventOutbox::class)->record('outside',t4oPayload()))->toThrow(LogicException::class);
    DB::connection('sqlsrv')->beginTransaction();$payload=t4oPayload();
    $id=app(PaymentEventOutbox::class)->record('rollback-'.Str::uuid(),$payload);DB::connection('sqlsrv')->rollBack();
    expect(DB::connection('sqlsrv')->table('financial_team4_outbox')->where('event_id',$id)->exists())->toBeFalse();
});
test('team4 outbox retries registration once rejects altered source and increments only new events',function(){
    $p=t4oPayload();$key='payment-'.Str::uuid();$service=app(PaymentEventOutbox::class);
    $id=$service->record($key,$p);expect($service->record($key,$p))->toBe($id);
    expect(fn()=>$service->record($key,[...$p,'amount_cents'=>1]))->toThrow(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
    $id2=$service->record('refund-'.Str::uuid(),[...$p,'status'=>'PARTIALLY_REFUNDED','refund_id'=>'R-1','amount_cents'=>100]);
    expect((int)t4oRow($id)->event_version)->toBe(1)->and((int)t4oRow($id2)->event_version)->toBe(2);
});
test('team4 sends stored bytes and valid HMAC and requires acknowledgement before marking delivered',function(){
    $id=app(PaymentEventOutbox::class)->record('paid-'.Str::uuid(),t4oPayload());$original=t4oRow($id)->body;t4oAck();
    expect(app(Team4WebhookDelivery::class)->run())->toBe(1);
    Http::assertSent(function($r)use($original){
        $t=$r->header('X-Team-Timestamp')[0];$s=$r->header('X-Team-Signature')[0];
        return $r->body()===$original&&hash_equals($s,hash_hmac('sha256',$t.'|POST|/api/equipo4/webhooks/payment-status|'.hash('sha256',$original),'fixture-not-a-real-team-secret'));
    });
    expect(t4oRow($id)->state)->toBe('DELIVERED');expect(app(Team4WebhookDelivery::class)->run())->toBe(0);
});
test('team4 retry preserves event and bytes across recoverable failure',function(){
    $id=app(PaymentEventOutbox::class)->record('retry-'.Str::uuid(),t4oPayload());$body=t4oRow($id)->body;
    Http::fake(['inventory.test/*'=>Http::sequence()->push([],500)->push(['received'=>true,'payment_event_id'=>'PE-2'],202)]);
    app(Team4WebhookDelivery::class)->run();expect(t4oRow($id)->state)->toBe('RETRY');
    expect(app(Team4WebhookDelivery::class)->run())->toBe(0);
    DB::connection('sqlsrv')->table('financial_team4_outbox')->where('event_id',$id)->update(['available_at'=>now()->subSecond()]);
    app(Team4WebhookDelivery::class)->run();expect(t4oRow($id)->state)->toBe('DELIVERED')->and(t4oRow($id)->body)->toBe($body)->and((int)t4oRow($id)->attempts)->toBe(2);
    Http::assertSentCount(2);
});
test('team4 permanent rejection and malformed acknowledgement remain pending operator review',function(){
    foreach([401,403,409,422,302,202] as $status){
        $id=app(PaymentEventOutbox::class)->record('review-'.Str::uuid(),t4oPayload());
        Http::fake(['inventory.test/*'=>Http::response(['received'=>'true'], $status)]);
        app(Team4WebhookDelivery::class)->run();expect(t4oRow($id)->state)->toBe('REVIEW');
    }
});
test('team4 same intent cannot overtake blocked event or reuse another order',function(){
    $p=t4oPayload();$service=app(PaymentEventOutbox::class);$first=$service->record('first-'.Str::uuid(),$p);
    $second=$service->record('second-'.Str::uuid(),[...$p,'status'=>'PARTIALLY_REFUNDED','refund_id'=>'R-2','amount_cents'=>100]);
    expect(fn()=>$service->record('foreign-'.Str::uuid(),[...$p,'order_id'=>'OTHER']))->toThrow(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
    Http::fake(['inventory.test/*'=>Http::response([],401)]);app(Team4WebhookDelivery::class)->run();app(Team4WebhookDelivery::class)->run();
    expect(t4oRow($first)->state)->toBe('REVIEW')->and((int)t4oRow($second)->attempts)->toBe(0);Http::assertSentCount(1);
});
test('team4 network failure retries and expires after initial send plus five retries',function(){
    $id=app(PaymentEventOutbox::class)->record('network-'.Str::uuid(),t4oPayload());
    Http::fake(function(){throw new ConnectionException('fixture');});
    for($i=0;$i<6;$i++){
        DB::connection('sqlsrv')->table('financial_team4_outbox')->where('event_id',$id)->update(['available_at'=>now()->subSecond()]);
        app(Team4WebhookDelivery::class)->run();
    }
    expect(t4oRow($id)->state)->toBe('EXHAUSTED')->and((int)t4oRow($id)->attempts)->toBe(6);
    expect(app(Team4WebhookDelivery::class)->run())->toBe(0);
});
test('team4 delivery lease excludes reentrant worker and can recover interrupted attempt',function(){
    $id=app(PaymentEventOutbox::class)->record('lease-'.Str::uuid(),t4oPayload());
    Http::fake(function(){expect(app(Team4WebhookDelivery::class)->run())->toBe(0);return Http::response(['received'=>true,'payment_event_id'=>'PE-L'],202);});
    app(Team4WebhookDelivery::class)->run();expect(t4oRow($id)->state)->toBe('DELIVERED');
    $id2=app(PaymentEventOutbox::class)->record('crash-'.Str::uuid(),t4oPayload());
    DB::connection('sqlsrv')->table('financial_team4_outbox')->where('event_id',$id2)->update(['state'=>'SENDING','lease_token'=>(string)Str::uuid(),'lease_until'=>now()->subSecond(),'attempts'=>1]);
    t4oAck();app(Team4WebhookDelivery::class)->run();expect(t4oRow($id2)->state)->toBe('DELIVERED')->and((int)t4oRow($id2)->attempts)->toBe(2);
});
test('team4 disabled configuration and corrupted body never send HTTP',function(){
    $id=app(PaymentEventOutbox::class)->record('integrity-'.Str::uuid(),t4oPayload());config(['financial_team4.enabled'=>false]);
    expect(app(Team4WebhookDelivery::class)->run())->toBe(0);Http::assertNothingSent();
    config(['financial_team4.enabled'=>true]);DB::connection('sqlsrv')->table('financial_team4_outbox')->where('event_id',$id)->update(['body'=>'{}']);
    app(Team4WebhookDelivery::class)->run();expect(t4oRow($id)->state)->toBe('REVIEW');Http::assertNothingSent();
});
