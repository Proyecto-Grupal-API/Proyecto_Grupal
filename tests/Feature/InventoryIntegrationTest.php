<?php

use App\Models\User;
use App\Models\InventoryModule\Category;
use App\Models\InventoryModule\InventoryBusiness;
use App\Models\InventoryModule\Product;
use App\Models\InventoryModule\StockMovement;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['team4.business_id'=>'business-A', 'team4.integration.business_id'=>'business-A',
        'team4.identidad'=>['url'=>'http://identity.test','client_id'=>'svc_inventory_test','client_secret'=>'test-secret'],
        'team4.integration.secrets'=>['equipo3'=>'test-E3','equipo2'=>'test-E2','equipo7'=>'test-E7'],
        'team4.integration.payment_events_enabled'=>false]);
    InventoryBusiness::create(['business_id'=>'business-A','name'=>'Negocio A','active'=>true]);
    (require database_path('migrations/2026_10_14_000000_create_inventory_domain_indexes.php'))->up();
    Artisan::call('team4:init-indexes');
    Artisan::call('inventario:demo');
    $this->actor=User::factory()->create(['must_change_password'=>false,'account_activation_pending'=>false]);
    $this->remoteRole='business_owner';
    $this->remoteMembership='active';
    $this->remoteAllowedBusinesses=['business-A'];
    $this->identityUnavailable=false;
    $this->identityAuthorizationFailAfter=null;
    $this->authorizationRequests=0;
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        if ($this->identityUnavailable) { return Http::response([],503); }
        if (str_contains($request->url(),'/api/oauth/token')) { return Http::response(['access_token'=>'test-token','expires_in'=>300]); }
        $data=$request->data();
        if (str_contains($request->url(),'/identity/assignments')) {
            $membership=in_array($data['scope_id'],$this->remoteAllowedBusinesses,true) ? $this->remoteMembership : null;
            return Http::response(['subject_id'=>$data['subject_id'],'scope_type'=>'business','scope_id'=>$data['scope_id'],
                'membership_status'=>$membership,'roles'=>$membership==='active' ? [['role_key'=>$this->remoteRole,'status'=>'active']] : []]);
        }
        if (str_contains($request->url(),'/identity/authorization/check')) {
            $this->authorizationRequests++;
            if ($this->identityAuthorizationFailAfter !== null && $this->authorizationRequests > $this->identityAuthorizationFailAfter) { return Http::response([],503); }
            $grants=['business_owner'=>['business.manage','business.operate','business.inventory.operate'],
                'business_manager'=>['business.operate','business.inventory.operate'],'inventory_manager'=>['business.inventory.operate'], 'buyer'=>[]];
            return Http::response(['authorized'=>in_array($data['scope_id'],$this->remoteAllowedBusinesses,true)
                && $this->remoteMembership==='active' && in_array($data['capability'],$grants[$this->remoteRole] ?? [],true)]);
        }
        throw new RuntimeException('Petición externa no esperada: '.$request->url());
    });
});

function inventorySigned($test, string $path, array $body, string $consumer='equipo3')
{
    $timestamp=(string) time();$json=json_encode($body);
    $payload=$timestamp.'|POST|'.$path.'|'.hash('sha256',$json);
    return $test->postJson($path,$body,['X-Team-Timestamp'=>$timestamp,
        'X-Team-Signature'=>hash_hmac('sha256',$payload,config('team4.integration.secrets.'.$consumer))]);
}
function inventoryLine(): array
{
    $row=DB::connection('inventory')->getCollection('inventories')->findOne(['business_id'=>'business-A','available'=>['$gt'=>10]]);
    return ['product_id'=>(string)$row['product_id'],'location_id'=>(string)$row['location_id'],'variant_id'=>null,'quantity'=>3];
}

it('requires the real session and never accepts the old impersonation session',function () {
    $this->withSession(['team4_impersonate_user_id'=>'USR-ADMIN-001'])->get('/equipo4')->assertRedirect('/login');
});

it('loads the imported operational pages with effective E1 permissions',function (string $path,string $page) {
    $this->actingAs($this->actor)->get($path)->assertOk()->assertInertia(fn (Assert $assert)=>$assert->component('Equipo4/'.$page)
        ->where('inventoryContext.user.id',(string)$this->actor->getKey())->where('inventoryContext.business_id','business-A'));
})->with([
    ['/equipo4','Dashboard'], ['/equipo4/productos','Productos'], ['/equipo4/souvenirs','Souvenirs'],
    ['/equipo4/almacenes','Almacenes'], ['/equipo4/categorias','Categorias'], ['/equipo4/inventario','Inventario'],
    ['/equipo4/kardex','Kardex'], ['/equipo4/proveedores','Proveedores'], ['/equipo4/compras','Compras'],
    ['/equipo4/recepciones','Recepciones'], ['/equipo4/costos','Costos'], ['/equipo4/reservas','Reservas'],
    ['/equipo4/devoluciones','Devoluciones'], ['/equipo4/alertas','Alertas'], ['/equipo4/conteos','Conteos'],
]);

it('denies inactive business membership even with an administrator in the old session',function () {
    $this->remoteMembership='revoked';
    $this->actingAs($this->actor)->withSession(['team4_impersonate_user_id'=>'USR-ADMIN-001'])->get('/equipo4')->assertRedirect(route('equipo4.not-authorized'));
    $this->postJson('/equipo4/productos',[])->assertForbidden();
});

it('rechecks E1 on the following request after revocation',function () {
    $this->actingAs($this->actor)->get('/equipo4/productos')->assertOk();
    $this->remoteMembership='revoked';
    $this->get('/equipo4/productos')->assertRedirect(route('equipo4.not-authorized'));
});

it('does not grant owner actions to business managers',function () {
    $this->remoteRole='business_manager';
    $product=Product::first();
    $this->actingAs($this->actor)->get('/equipo4/productos')->assertOk()->assertInertia(fn (Assert $a)=>$a
        ->where('inventoryContext.user.role','business_manager')->where('permissions', fn ($permissions)=>!collect($permissions)->contains('compras.authorize')));
    $this->deleteJson('/equipo4/productos/'.$product->getKey())->assertForbidden();
    expect(Product::whereKey($product->getKey())->exists())->toBeTrue();
});

it('does not invent an E1 capability for the buyer role',function () {
    $this->remoteRole='buyer';
    $this->actingAs($this->actor)->get('/equipo4')->assertRedirect(route('equipo4.not-authorized'));
});

it('binds the stock adjustment actor to the authenticated subject',function () {
    $row=DB::connection('inventory')->getCollection('inventories')->findOne(['business_id'=>'business-A']);
    $this->actingAs($this->actor)->from('/equipo4/inventario')->post('/equipo4/inventario/adjust',[
        'inventory_id'=>(string)$row['_id'],'quantity'=>2,'reason'=>'Conteo físico verificado','actor_id'=>'another-subject'])->assertRedirect('/equipo4/inventario')->assertSessionHasNoErrors();
    $movement=StockMovement::first();
    expect($movement->actor_id)->toBe((string)$this->actor->getKey());
    $after=DB::connection('inventory')->getCollection('inventories')->findOne(['_id'=>$row['_id']]);
    expect((int)$after['on_hand'])->toBe((int)$row['on_hand']+2);
});

it('denies switching to another business until E1 authorizes it',function () {
    InventoryBusiness::create(['business_id'=>'business-B','name'=>'Negocio B','active'=>true]);
    Product::create(['business_id'=>'business-B','sku'=>'OTHER-001','name'=>'Producto de B','category'=>'souvenirs','active'=>true]);
    $this->actingAs($this->actor)->postJson('/equipo4/context',['business_id'=>'business-B'])->assertForbidden();
    $this->remoteAllowedBusinesses[]='business-B';
    $this->post('/equipo4/context',['business_id'=>'business-B'])->assertRedirect(route('equipo4.dashboard'));
    $this->get('/equipo4/productos')->assertOk()->assertInertia(fn (Assert $a)=>$a->where('inventoryContext.business_id','business-B')->has('products',1)->where('products.0.sku','OTHER-001'));
});

it('returns 503 without stock changes when identity is unavailable',function () {
    $before=Product::count();$this->identityUnavailable=true;
    $this->actingAs($this->actor)->postJson('/equipo4/productos',[])->assertStatus(503);
    expect(Product::count())->toBe($before);
});

it('protects all service reads and does not mix consumer signatures',function () {
    $this->getJson('/api/equipo4/products')->assertUnauthorized();
    inventorySigned($this,'/api/v1/inventory/availability',['items'=>[]],'equipo3')->assertUnauthorized();
});

it('reserves once, requires confirmed payment for sales and releases once',function () {
    $line=inventoryLine();$inv=DB::connection('inventory')->getCollection('inventories');
    $where=['business_id'=>'business-A','product_id'=>$line['product_id'],'location_id'=>$line['location_id']];
    $before=(array)$inv->findOne($where);
    $body=['order_id'=>'ORDER-TEST-001','items'=>[$line],'idempotency_key'=>'reserve-test-001'];
    $id=inventorySigned($this,'/api/equipo4/integration/reservations',$body)->assertCreated()->json('reservation_id');
    inventorySigned($this,'/api/equipo4/integration/reservations',$body)->assertCreated()->assertJsonPath('reservation_id',$id);
    $during=(array)$inv->findOne($where);
    expect((int)$during['available'])->toBe((int)$before['available']-3)->and((int)$during['reserved'])->toBe((int)$before['reserved']+3);
    inventorySigned($this,'/api/equipo4/integration/reservations/'.$id.'/confirm',[
        'order_id'=>'ORDER-TEST-001','payment_intent_id'=>'PI-PENDING','idempotency_key'=>'confirm-test-001'])->assertStatus(409);
    $release=['order_id'=>'ORDER-TEST-001','reason'=>'CUSTOMER_CANCELLED','idempotency_key'=>'release-test-001'];
    inventorySigned($this,'/api/equipo4/integration/reservations/'.$id.'/release',$release)->assertOk();
    inventorySigned($this,'/api/equipo4/integration/reservations/'.$id.'/release',$release)->assertOk();
    $after=(array)$inv->findOne($where);
    expect((int)$after['available'])->toBe((int)$before['available'])->and((int)$after['reserved'])->toBe((int)$before['reserved']);
});

it('does not accept financial callbacks until the integration is enabled',function () {
    inventorySigned($this,'/api/equipo4/webhooks/payment-status',['business_id'=>'business-A'],'equipo2')->assertStatus(503);
    expect(DB::connection('inventory')->getCollection('payment_events')->countDocuments([]))->toBe(0);
});

it('expires a reservation and returns its stock',function () {
    $line=inventoryLine();$inv=DB::connection('inventory')->getCollection('inventories');
    $where=['business_id'=>'business-A','product_id'=>$line['product_id'],'location_id'=>$line['location_id']];$before=(array)$inv->findOne($where);
    $id=inventorySigned($this,'/api/equipo4/integration/reservations',[
        'order_id'=>'ORDER-EXPIRE','items'=>[$line],'idempotency_key'=>'reserve-expire-001'])->assertCreated()->json('reservation_id');
    DB::connection('inventory')->getCollection('team3_api_reservations')->updateOne(['reservation_id'=>$id],['$set'=>['expires_at'=>new \MongoDB\BSON\UTCDateTime(now()->subMinute())]]);
    $this->artisan('inventario:expirar-reservas')->assertSuccessful();
    expect((int)$inv->findOne($where)['available'])->toBe((int)$before['available']);
});

function inventoryPurchasePayload(): array
{
    return ['supplier_id'=>(string) \App\Models\InventoryModule\Supplier::first()->getKey(),
        'expected_at'=>now()->addDay()->toDateString(),'status'=>'SOLICITADA','items'=>[
            ['product_id'=>(string) Product::first()->getKey(),'quantity'=>3,'unit_cost'=>25]]];
}
it('does not allow a manager to approve purchases through the create form',function () {
    $this->remoteRole='business_manager';$body=inventoryPurchasePayload();$body['status']='AUTORIZADA';
    $this->actingAs($this->actor)->postJson('/equipo4/compras',$body)->assertForbidden();
    expect(\App\Models\InventoryModule\PurchaseOrder::count())->toBe(0);
});
it('allows an inventory manager to request a purchase but requires an owner for approval',function () {
    $this->remoteRole='inventory_manager';
    $this->actingAs($this->actor)->from('/equipo4/compras')->post('/equipo4/compras',inventoryPurchasePayload())->assertRedirect('/equipo4/compras')->assertSessionHasNoErrors();
    $order=\App\Models\InventoryModule\PurchaseOrder::first();
    expect($order->status)->toBe('SOLICITADA')->and($order->requested_by)->toBe((string)$this->actor->getKey());
    $this->patchJson('/equipo4/compras/'.$order->getKey().'/status',['action'=>'autorizar'])->assertForbidden();
});
it('approves and receives a purchase with stock, cost and actual actor history',function () {
    $body=inventoryPurchasePayload();$db=DB::connection('inventory');
    $sum=fn ()=>(int) $db->getCollection('inventories')->aggregate([['$match'=>['business_id'=>'business-A','product_id'=>$body['items'][0]['product_id']]],['$group'=>['_id'=>null,'total'=>['$sum'=>'$on_hand']]]])->toArray()[0]['total'];
    $before=$sum();
    $this->actingAs($this->actor)->from('/equipo4/compras')->post('/equipo4/compras',$body)->assertSessionHasNoErrors();
    $order=\App\Models\InventoryModule\PurchaseOrder::first();
    $this->from('/equipo4/compras')->patch('/equipo4/compras/'.$order->getKey().'/status',['action'=>'autorizar'])->assertSessionHasNoErrors();
    expect($order->fresh()->authorized_by)->toBe((string)$this->actor->getKey());
    $line=\App\Models\InventoryModule\PurchaseOrderItem::first();
    $this->from('/equipo4/recepciones')->post('/equipo4/recepciones',[
        'purchase_order_id'=>(string)$order->getKey(),'items'=>[['purchase_order_item_id'=>(string)$line->getKey(),'quantity'=>3]]])->assertSessionHasNoErrors();
    expect($order->fresh()->status)->toBe('RECIBIDA_TOTAL')->and($sum())->toBe($before+3)
        ->and(\App\Models\InventoryModule\GoodsReceipt::first()->received_by)->toBe((string)$this->actor->getKey())
        ->and(StockMovement::first()->actor_id)->toBe((string)$this->actor->getKey())
        ->and(\App\Models\InventoryModule\CostHistory::count())->toBe(1);
});
it('renders a recoverable access page if permission checks fail during page sharing',function () {
    $this->identityAuthorizationFailAfter=1;
    $this->actingAs($this->actor)->get('/equipo4/productos')->assertStatus(503)
        ->assertInertia(fn (Assert $a)=>$a->component('Equipo4/NotAuthorized')->where('reason','identity_unavailable'));
});

it('serves scoped domain reads to the correct signed consumer',function (string $path) {
    Product::create(['business_id'=>'business-B','sku'=>'OTHER-001','name'=>'Producto B','category'=>'souvenirs','active'=>true]);
    $timestamp=(string)time();$payload=$timestamp.'|GET|'.$path.'|'.hash('sha256','');
    $answer=$this->call('GET',$path,[],[],[],['HTTP_ACCEPT'=>'application/json','HTTP_X_TEAM_TIMESTAMP'=>$timestamp,'HTTP_X_TEAM_SIGNATURE'=>hash_hmac('sha256',$payload,'test-E3')],'')->assertOk()->json();
    foreach ($answer as $row) { expect($row['business_id'])->toBe('business-A'); }
})->with(['/api/equipo4/products','/api/equipo4/warehouses','/api/equipo4/locations',
    '/api/equipo4/inventory','/api/equipo4/movements','/api/equipo4/suppliers','/api/equipo4/purchase-orders']);

it('normalizes empty legacy timestamp documents and serializes the catalogue',function () {
    $product=Product::first();$db=DB::connection('inventory');
    $db->getCollection('products')->updateOne(['_id'=>new \MongoDB\BSON\ObjectId((string)$product->getKey())],
        ['$set'=>['created_at'=>new \stdClass(),'updated_at'=>new \stdClass()]]);
    (require database_path('migrations/2026_10_14_000100_normalize_inventory_dates.php'))->up();
    $row=$db->getCollection('products')->findOne(['_id'=>new \MongoDB\BSON\ObjectId((string)$product->getKey())]);
    expect($row['created_at'])->toBeInstanceOf(\MongoDB\BSON\UTCDateTime::class)
        ->and($product->fresh()->toArray()['sku'])->toBe($product->sku);
});

it('receives a purchase in parts without closing it or receiving the same units twice',function () {
    $this->actingAs($this->actor)->from('/equipo4/compras')->post('/equipo4/compras',inventoryPurchasePayload())->assertSessionHasNoErrors();
    $order=\App\Models\InventoryModule\PurchaseOrder::first();$line=\App\Models\InventoryModule\PurchaseOrderItem::first();
    $this->patch('/equipo4/compras/'.$order->getKey().'/status',['action'=>'autorizar'])->assertSessionHasNoErrors();
    $body=['purchase_order_id'=>(string)$order->getKey(),'items'=>[['purchase_order_item_id'=>(string)$line->getKey(),'quantity'=>1]]];
    $this->from('/equipo4/recepciones')->post('/equipo4/recepciones',$body)->assertSessionHasNoErrors();
    expect($order->fresh()->status)->toBe('AUTORIZADA');
    $this->getJson('/equipo4/recepciones/order/'.$order->getKey())->assertOk()->assertJsonPath('items.0.quantity',2);
    $body['items'][0]['quantity']=3;$this->postJson('/equipo4/recepciones',$body)->assertUnprocessable();
    expect(\App\Models\InventoryModule\GoodsReceipt::count())->toBe(1);
    $body['items'][0]['quantity']=2;$this->from('/equipo4/recepciones')->post('/equipo4/recepciones',$body)->assertSessionHasNoErrors();
    expect($order->fresh()->status)->toBe('RECIBIDA_TOTAL')->and(\App\Models\InventoryModule\GoodsReceiptItem::sum('quantity'))->toBe(3);
});
it('rejects over-receipt without creating a receipt or changing stock',function () {
    $this->actingAs($this->actor)->from('/equipo4/compras')->post('/equipo4/compras',inventoryPurchasePayload())->assertSessionHasNoErrors();
    $order=\App\Models\InventoryModule\PurchaseOrder::first();$line=\App\Models\InventoryModule\PurchaseOrderItem::first();
    $this->patch('/equipo4/compras/'.$order->getKey().'/status',['action'=>'autorizar'])->assertSessionHasNoErrors();
    $this->postJson('/equipo4/recepciones',['purchase_order_id'=>(string)$order->getKey(),'items'=>[['purchase_order_item_id'=>(string)$line->getKey(),'quantity'=>4]]])->assertUnprocessable();
    expect(\App\Models\InventoryModule\GoodsReceipt::count())->toBe(0)->and(StockMovement::count())->toBe(0);
});

it('reserves checkout stock with an explicit expiration and isolates the signed business path',function () {
    $line=inventoryLine();
    $body=['order_id'=>'expiry-order','items'=>[$line],'idempotency_key'=>'expiry-order-reserve','expires_at'=>now()->addMinutes(15)->toISOString()];
    $path='/api/equipo4/businesses/business-A/integration/reservations';
    $response=inventorySigned($this,$path,$body)->assertCreated();
    $row=DB::connection('inventory')->getCollection('team3_api_reservations')->findOne(['reservation_id'=>$response->json('reservation_id')]);
    expect($row['expires_at'])->toBeInstanceOf(\MongoDB\BSON\UTCDateTime::class);
    inventorySigned($this,'/api/equipo4/businesses/business-A/integration/reservation-status',[
        'order_id'=>'expiry-order','idempotency_key'=>'expiry-order-reserve'])->assertOk()->assertJsonPath('state','completed');
    inventorySigned($this,'/api/equipo4/businesses/business-B/integration/reservation-status',[
        'order_id'=>'expiry-order','idempotency_key'=>'expiry-order-reserve'])->assertForbidden();
});

it('uses the reservation route id when the signed path also contains a business id',function () {
    $line=inventoryLine();$stock=DB::connection('inventory')->getCollection('inventories')->findOne(['business_id'=>'business-A','product_id'=>$line['product_id'],'location_id'=>$line['location_id']]);
    $body=['order_id'=>'scoped-route-order','items'=>[$line],'idempotency_key'=>'scoped-route-reserve','expires_at'=>now()->addMinutes(15)->toISOString()];
    $reservation=inventorySigned($this,'/api/equipo4/businesses/business-A/integration/reservations',$body)->assertCreated()->json('reservation_id');
    $path='/api/equipo4/businesses/business-A/integration/reservations/'.$reservation;$ts=(string)time();
    $this->call('GET',$path,[],[],[],['HTTP_ACCEPT'=>'application/json','HTTP_X_TEAM_TIMESTAMP'=>$ts,
        'HTTP_X_TEAM_SIGNATURE'=>hash_hmac('sha256',$ts.'|GET|'.$path.'|'.hash('sha256',''),'test-E3')],'')->assertOk()->assertJsonPath('reservation_id',$reservation);
    inventorySigned($this,$path.'/confirm',['order_id'=>'scoped-route-order','payment_intent_id'=>'not-paid','idempotency_key'=>'scoped-route-confirm'])->assertConflict();
    inventorySigned($this,$path.'/release',['order_id'=>'scoped-route-order','idempotency_key'=>'scoped-route-release'])->assertOk()->assertJsonPath('status','RELEASED');
    $after=DB::connection('inventory')->getCollection('inventories')->findOne(['_id'=>$stock['_id']]);
    expect($after['available'])->toBe($stock['available'])->and($after['reserved'])->toBe($stock['reserved']);
});

it('keeps supplier product catalog and additional contacts within the selected business', function () {
    $product = Product::where('business_id', 'business-A')->firstOrFail();
    $data = ['code'=>'SUP-NEW', 'legal_name'=>'Proveedor catálogo', 'contact_name'=>'Contacto principal',
        'payment_terms'=>'CONTADO','status'=>'ACTIVO','supplied_products'=>[$product->sku], 'contacts'=>['Contacto adicional']];
    $this->actingAs($this->actor)->postJson('/equipo4/proveedores', $data)->assertRedirect();
    $supplier = \App\Models\InventoryModule\Supplier::where('code','SUP-NEW')->firstOrFail();
    expect($supplier->supplied_products)->toBe([$product->sku])->and($supplier->contacts)->toBe(['Contacto adicional']);
    $this->get('/equipo4/proveedores')->assertInertia(fn (Assert $a)=>$a
        ->where('suppliers',fn ($rows)=>collect($rows)->contains(fn ($r)=>$r['code']==='SUP-NEW' && $r['supplied_products']===[$product->sku] && $r['contacts']===['Contacto adicional']))
        ->where('allProducts',fn ($rows)=>collect($rows)->contains('sku',$product->sku)));
    Product::create(['business_id'=>'business-B','sku'=>'ONLY-B','name'=>'Ajeno','active'=>true]);
    $this->putJson('/equipo4/proveedores/'.$supplier->getKey(), [...$data,'supplied_products'=>['ONLY-B']])->assertUnprocessable();
    expect($supplier->fresh()->supplied_products)->toBe([$product->sku]);
});

it('rejects malformed supplier arrays before persisting them', function () {
    $this->actingAs($this->actor)->postJson('/equipo4/proveedores', ['code'=>'SUP-BAD','legal_name'=>'No guardar',
        'contact_name'=>'Contacto','payment_terms'=>'CONTADO','status'=>'ACTIVO',
        'supplied_products'=>[['sku'=>'objeto']], 'contacts'=>[['unsafe'=>'objeto']]])->assertUnprocessable();
    expect(\App\Models\InventoryModule\Supplier::where('code','SUP-BAD')->exists())->toBeFalse();
});

it('persists category and product descriptive attributes without inventing stock variants', function () {
    $this->actingAs($this->actor)->postJson('/equipo4/categorias', ['name'=>'Textiles','active'=>true,
        'has_sizes'=>true,'has_colors'=>true,'has_seasons'=>false])->assertRedirect();
    $category=\App\Models\InventoryModule\Category::where('slug','textiles')->firstOrFail();
    expect($category->has_sizes)->toBeTrue()->and($category->has_seasons)->toBeFalse();
    $this->postJson('/equipo4/productos', ['sku'=>'TEXT-NEW','name'=>'Playera','category'=>'textiles',
        'stock_min'=>0,'stock_max'=>20,'active'=>true,'sizes'=>['M','L'],'colors'=>['Azul'],'seasons'=>[]])->assertRedirect();
    $product=Product::where('sku','TEXT-NEW')->firstOrFail();
    expect($product->sizes)->toBe(['M','L'])->and($product->colors)->toBe(['Azul']);
    $this->get('/equipo4/productos')->assertInertia(fn (Assert $a)=>$a
        ->where('products',fn ($rows)=>collect($rows)->contains(fn ($r)=>$r['sku']==='TEXT-NEW' && $r['sizes']===['M','L'])));
    $this->putJson('/equipo4/productos/'.$product->getKey(), ['sku'=>'TEXT-NEW','name'=>'Playera','category'=>'textiles',
        'stock_min'=>0,'stock_max'=>20,'active'=>true,'sizes'=>[['bad'=>'objeto']]])->assertUnprocessable();
});

it('projects the supplier catalog for the purchase selector without cross business products', function () {
    $product=Product::where('business_id','business-A')->firstOrFail();
    $supplier=\App\Models\InventoryModule\Supplier::where('business_id','business-A')->firstOrFail();
    $supplier->forceFill(['supplied_products'=>[$product->sku]])->save();
    $this->actingAs($this->actor)->get('/equipo4/compras')->assertInertia(fn (Assert $a)=>$a
        ->where('suppliersList',fn ($rows)=>collect($rows)->contains(fn ($r)=>$r['_id']===(string)$supplier->getKey() && $r['supplied_products']===[$product->sku])));
});

it('retains deleted product cost history without merging a reused SKU or another business', function () {
    $this->actingAs($this->actor);
    app(\App\Services\InventoryModule\BusinessContext::class)->select('business-A');
    $old=Product::create(['business_id'=>'business-A','sku'=>'HISTORY-SKU','name'=>'Producto anterior','active'=>true]);
    $service=app(\App\Services\InventoryModule\CostService::class);
    $service->register(['product_id'=>(string)$old->getKey(),'cost'=>12,'suggested_price'=>24]);
    $old->delete();
    $new=Product::create(['business_id'=>'business-A','sku'=>'HISTORY-SKU','name'=>'Producto nuevo','active'=>false]);
    $service->register(['product_id'=>(string)$new->getKey(),'cost'=>30,'suggested_price'=>60]);
    DB::connection('inventory')->getCollection('cost_histories')->insertOne(['business_id'=>'business-B','product_id'=>'FOREIGN',
        'sku'=>'PRIVATE-SKU','cost'=>999,'effective_at'=>new \MongoDB\BSON\UTCDateTime(now())]);
    $this->get('/equipo4/costos')->assertOk()->assertInertia(fn (Assert $a)=>$a
        ->has('archivedCosts',1)->where('archivedCosts.0.product_id',(string)$old->getKey())
        ->where('archivedCosts.0.sku','HISTORY-SKU')->where('archivedCosts.0.last_cost',12)
        ->where('products',fn ($rows)=>collect($rows)->contains(fn ($r)=>$r['_id']===(string)$new->getKey() && (float)$r['last_cost']===30.0)));
    $this->getJson('/equipo4/costos/'.$old->getKey().'/history')->assertOk()->assertJsonCount(1,'history')->assertJsonPath('history.0.cost',12);
});

it('keeps legacy cost history accessible with bounded archived pagination and literal search', function () {
    $this->actingAs($this->actor);
    $collection=DB::connection('inventory')->getCollection('cost_histories');
    for ($i=0;$i<27;$i++) {
        $collection->insertOne(['business_id'=>'business-A','product_id'=>(string)new \MongoDB\BSON\ObjectId,
            'cost'=>$i,'sku'=>$i===0?'LEGACY.[A]':'LEGACY-'.$i,'effective_at'=>new \MongoDB\BSON\UTCDateTime(now())]);
    }
    $this->get('/equipo4/costos')->assertInertia(fn (Assert $a)=>$a->has('archivedCosts',25)->where('archivedPagination.total',27));
    $this->get('/equipo4/costos?archived_page=2')->assertInertia(fn (Assert $a)=>$a->has('archivedCosts',2));
    $this->get('/equipo4/costos?q=LEGACY.%5BA%5D')->assertInertia(fn (Assert $a)=>$a->has('archivedCosts',1)->where('archivedCosts.0.sku','LEGACY.[A]'));
});

it('never manufactures a paid event for a MANUAL checkout reference', function (string $prefix) {
    $id = inventorySigned($this, $prefix.'/reservations', [
        'order_id' => 'ORDER-MANUAL-1', 'items' => [inventoryLine()], 'idempotency_key' => 'manual-reserve-1',
    ])->assertCreated()->json('reservation_id');
    inventorySigned($this, $prefix.'/reservations/'.$id.'/confirm', [
        'order_id' => 'ORDER-MANUAL-1', 'payment_intent_id' => 'MANUAL-do-not-trust', 'idempotency_key' => 'manual-confirm-1',
    ])->assertConflict();
    expect(DB::connection('inventory')->getCollection('payment_events')->countDocuments())->toBe(0);
    expect(DB::connection('inventory')->getCollection('team3_api_reservations')->findOne(['reservation_id' => $id])['status'])->toBe('RESERVED');
})->with(['/api/equipo4/integration', '/api/equipo4/businesses/business-A/integration']);

it('retains the E7 reservation contract on the shared canonical path', function () {
    $product = Product::first();
    $body = ['origin_reference' => 'reward-contract-1', 'origin_type' => 'REWARD', 'product_reference' => $product->sku,
        'quantity' => 1, 'idempotency_key' => 'e7-contract-reserve-1'];
    $id = inventorySigned($this, '/api/v1/inventory/reservations', $body, 'equipo7')->assertCreated()->assertJsonPath('status', 'reserved')->json('reservation_id');
    inventorySigned($this, '/api/v1/inventory/reservations/'.$id.'/release', [
        'reason' => 'CANCELLED', 'idempotency_key' => 'e7-contract-release-1'], 'equipo7')->assertOk();
    expect(DB::connection('inventory')->getCollection('payment_events')->countDocuments())->toBe(0);
});

it('requires payment for the reservation order and binds a confirmation retry to its payment', function () {
    config(['team4.integration.payment_events_enabled' => true]);
    (require database_path('migrations/2026_10_17_000200_inventory_payment_events_v2.php'))->up();
    $line = inventoryLine();
    $path = '/api/equipo4/integration/reservations';
    $id = inventorySigned($this, $path, [
        'order_id' => 'ORDER-EXPECTED', 'items' => [$line], 'idempotency_key' => 'reserve-order-binding',
    ])->assertCreated()->json('reservation_id');
    $payment = [
        'event_id' => 'evt-other-order', 'event_version' => 1, 'payment_intent_id' => 'PI-OTHER',
        'business_id' => 'business-A', 'order_id' => 'ORDER-OTHER', 'status' => 'PAID',
        'amount' => 50, 'currency' => 'MXN', 'occurred_at' => now()->toIso8601String(),
    ];
    inventorySigned($this, '/api/equipo4/webhooks/payment-status', $payment, 'equipo2')->assertAccepted();
    $confirm = ['order_id' => 'ORDER-OTHER', 'payment_intent_id' => 'PI-OTHER', 'idempotency_key' => 'confirm-order-binding'];
    inventorySigned($this, $path.'/'.$id.'/confirm', $confirm)->assertConflict();
    inventorySigned($this, $path.'/'.$id.'/release', [
        'order_id' => 'ORDER-OTHER', 'idempotency_key' => 'release-wrong-order',
    ])->assertConflict();
    $db = DB::connection('inventory');
    $reservations = $db->getCollection('team3_api_reservations');
    expect($reservations->findOne(['reservation_id' => $id])['status'])->toBe('RESERVED')
        ->and($db->getCollection('stock_movements')->countDocuments(['type' => 'SALE']))->toBe(0);

    inventorySigned($this, '/api/equipo4/webhooks/payment-status', array_replace($payment, [
        'event_id' => 'evt-expected-order', 'payment_intent_id' => 'PI-EXPECTED', 'order_id' => 'ORDER-EXPECTED',
    ]), 'equipo2')->assertAccepted();
    $confirm = array_replace($confirm, ['order_id' => 'ORDER-EXPECTED', 'payment_intent_id' => 'PI-EXPECTED']);
    inventorySigned($this, $path.'/'.$id.'/confirm', $confirm)->assertOk()->assertJsonPath('status', 'CONFIRMED');
    inventorySigned($this, $path.'/'.$id.'/confirm', $confirm)->assertOk();
    inventorySigned($this, '/api/equipo4/webhooks/payment-status', array_replace($payment, [
        'event_id' => 'evt-second-intent', 'payment_intent_id' => 'PI-SECOND', 'order_id' => 'ORDER-EXPECTED',
    ]), 'equipo2')->assertAccepted();
    inventorySigned($this, $path.'/'.$id.'/confirm', array_replace($confirm, [
        'payment_intent_id' => 'PI-SECOND',
    ]))->assertConflict();
    expect($db->getCollection('stock_movements')->countDocuments(['type' => 'SALE']))->toBe(1)
        ->and($reservations->findOne(['reservation_id' => $id])['payment_intent_id'])->toBe('PI-EXPECTED');
});

it('rolls back an E7 expiry when its outbox write fails and queues it exactly once on retry', function () {
    $line = inventoryLine();
    $db = DB::connection('inventory');
    $inventory = $db->getCollection('inventories');
    $where = ['business_id' => 'business-A', 'product_id' => $line['product_id'], 'location_id' => $line['location_id']];
    $before = $inventory->findOne($where);
    $reserve = [
        'origin_reference' => 'reward-outbox-failure', 'origin_type' => 'REWARD',
        'product_reference' => $line['product_id'], 'quantity' => $line['quantity'], 'location_id' => $line['location_id'],
        'idempotency_key' => 'reserve-outbox-failure',
    ];
    $id = inventorySigned($this, '/api/v1/inventory/reservations', $reserve, 'equipo7')->assertCreated()->json('reservation_id');
    inventorySigned($this, '/api/equipo4/integration/reservations/'.$id.'/release', [
        'order_id' => 'reward-outbox-failure', 'idempotency_key' => 'release-other-origin',
    ])->assertNotFound();
    $reservations = $db->getCollection('team3_api_reservations');
    $reservations->updateOne(['reservation_id' => $id], ['$set' => ['expires_at' => new \MongoDB\BSON\UTCDateTime(now()->subMinute())]]);
    $outboxService = \App\Services\InventoryModule\OutboxService::class;
    $this->mock($outboxService)->shouldReceive('publish')->once()->andThrow(new RuntimeException('Synthetic outbox failure'));
    $this->artisan('e7:expire')->assertFailed();
    expect($reservations->findOne(['reservation_id' => $id])['status'])->toBe('RESERVED')
        ->and((int) $inventory->findOne($where)['reserved'])->toBe((int) $before['reserved'] + $line['quantity'])
        ->and($db->getCollection('integration_outbox')->countDocuments([]))->toBe(0);

    $this->app->forgetInstance($outboxService);
    $this->artisan('inventario:expirar-reservas')->assertSuccessful();
    $this->artisan('e7:expire')->assertSuccessful();
    $outbox = $db->getCollection('integration_outbox');
    expect($reservations->findOne(['reservation_id' => $id])['status'])->toBe('RELEASED')
        ->and((int) $inventory->findOne($where)['available'])->toBe((int) $before['available'])
        ->and($outbox->countDocuments(['payload.reservation_id' => $id, 'status' => 'PENDING']))->toBe(1);
});

it('persists souvenir preorder metadata and rejects an invalid preorder limit', function () {
    $category = Category::where('business_id', 'business-A')->where('slug', 'souvenirs')->firstOrFail();
    $payload = [
        'sku' => 'PREORDER-NEW',
        'name' => 'Souvenir de colección',
        'category' => $category->slug,
        'stock_min' => 0,
        'stock_max' => 50,
        'active' => true,
        'official' => true,
        'collections' => ['Generación 2026'],
        'packages' => ['Edición especial'],
        'preorder' => ['enabled' => true, 'available_at' => now()->addMonth()->toDateString(), 'limit' => 24],
    ];

    $this->actingAs($this->actor)->postJson('/equipo4/souvenirs', $payload)->assertRedirect();
    $product = Product::where('business_id', 'business-A')->where('sku', 'PREORDER-NEW')->firstOrFail();
    expect($product->official)->toBeTrue()
        ->and($product->collections)->toBe(['Generación 2026'])
        ->and($product->packages)->toBe(['Edición especial'])
        ->and($product->preorder['limit'])->toBe(24);

    $this->postJson('/equipo4/souvenirs', [...$payload, 'sku' => 'PREORDER-INVALID', 'preorder' => [
        'enabled' => true,
        'available_at' => now()->addMonth()->toDateString(),
        'limit' => 0,
    ]])->assertUnprocessable();
    expect(Product::where('business_id', 'business-A')->where('sku', 'PREORDER-INVALID')->exists())->toBeFalse();
});

it('serves the same signed service contract under the versioned /api/v1 path and keeps the historical path',function () {
    $line=inventoryLine();
    $legacy=inventorySigned($this,'/api/equipo4/integration/availability',['items'=>[$line]])->assertOk()->json();
    $versioned=inventorySigned($this,'/api/v1/inventory/equipo4/integration/availability',['items'=>[$line]])->assertOk()->json();
    expect($versioned)->toBe($legacy);
    // Una firma calculada sobre la ruta histórica no autoriza la ruta versionada (la firma cubre la ruta invocada).
    $timestamp=(string) time();$json=json_encode(['items'=>[$line]]);
    $this->postJson('/api/v1/inventory/equipo4/integration/availability',['items'=>[$line]],['X-Team-Timestamp'=>$timestamp,
        'X-Team-Signature'=>hash_hmac('sha256',$timestamp.'|POST|/api/equipo4/integration/availability|'.hash('sha256',$json),config('team4.integration.secrets.equipo3'))])->assertUnauthorized();
    $this->getJson('/api/v1/inventory/equipo4/products')->assertUnauthorized();
});

// Diseño E4 4.9 y rúbrica 10.x: alertas de reabastecimiento, kardex filtrable y dashboard coherente con la base.
it('raises a low stock alert when a reorder point is exceeded and lets the owner discard it',function () {
    $this->actingAs($this->actor)->post('/equipo4/reglas-reorden/sync-all')->assertRedirect();
    $rules=DB::connection('inventory')->getCollection('reorder_rules');$alerts=DB::connection('inventory')->getCollection('stock_alerts');
    $rule=(array)$rules->findOne(['business_id'=>'business-A']);
    expect($rule)->not->toBeEmpty();
    $this->put('/equipo4/reglas-reorden/'.$rule['_id'],['reorder_point'=>-1,'active'=>true])->assertSessionHasErrors('reorder_point');
    $this->put('/equipo4/reglas-reorden/'.$rule['_id'],['reorder_point'=>1000000,'active'=>true])->assertRedirect();
    $this->post('/equipo4/alertas/generate')->assertRedirect();
    $alert=(array)$alerts->findOne(['business_id'=>'business-A','product_id'=>$rule['product_id'],'location_id'=>$rule['location_id'],'status'=>'ACTIVE']);
    expect($alert['type'] ?? null)->toBe('LOW_STOCK');
    $this->get('/equipo4')->assertOk()->assertInertia(fn (Assert $a)=>$a->where('stats.alerts_active',$alerts->countDocuments(['business_id'=>'business-A','status'=>'ACTIVE'])));
    $this->post('/equipo4/alertas/'.$alert['_id'].'/discard',['reason'=>'Reabasto programado'])->assertRedirect();
    expect((array)$alerts->findOne(['_id'=>$alert['_id']]))->not->toMatchArray(['status'=>'ACTIVE']);
});

it('filters the kardex report by movement type and text and its counters match the stored movements',function () {
    $row=DB::connection('inventory')->getCollection('inventories')->findOne(['business_id'=>'business-A']);
    foreach ([2,-1] as $qty) {
        $this->actingAs($this->actor)->from('/equipo4/inventario')->post('/equipo4/inventario/adjust',[
            'inventory_id'=>(string)$row['_id'],'quantity'=>$qty,'reason'=>'Conteo físico '.$qty])->assertSessionHasNoErrors();
    }
    $movements=DB::connection('inventory')->getCollection('stock_movements');
    $total=$movements->countDocuments(['business_id'=>'business-A']);
    $type=(string)((array)$movements->findOne(['business_id'=>'business-A']))['type'];
    $this->actingAs($this->actor)->get('/equipo4/kardex')->assertOk()->assertInertia(fn (Assert $a)=>$a->component('Equipo4/Kardex')
        ->where('pagination.total',$total)->where('kpis.0.value',$total));
    $this->get('/equipo4/kardex?type='.$type)->assertOk()->assertInertia(fn (Assert $a)=>$a->where('filters.type',$type)
        ->where('pagination.total',$movements->countDocuments(['business_id'=>'business-A','type'=>$type])));
    $this->get('/equipo4/kardex?q=texto-que-no-existe-'.uniqid())->assertOk()->assertInertia(fn (Assert $a)=>$a->where('pagination.total',0)->where('movements',[]));
});

it('shows dashboard indicators equal to direct aggregates of the business inventory',function () {
    $inv=DB::connection('inventory')->getCollection('inventories');$onHand=0;$available=0;
    foreach ($inv->find(['business_id'=>'business-A']) as $row) { $onHand+=(int)$row['on_hand']; $available+=(int)$row['available']; }
    $this->actingAs($this->actor)->get('/equipo4')->assertOk()->assertInertia(fn (Assert $a)=>$a->component('Equipo4/Dashboard')
        ->where('stats.inventory_units',$onHand)->where('stats.inventory_available',$available)
        ->where('stats.products',DB::connection('inventory')->getCollection('products')->countDocuments(['business_id'=>'business-A']))
        ->where('stats.warehouses',DB::connection('inventory')->getCollection('warehouses')->countDocuments(['business_id'=>'business-A'])));
});
