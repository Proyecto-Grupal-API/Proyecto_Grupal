<?php
use App\Models\User;
use CampusDigital\Authorization\Kernel;
use CampusDigital\Authorization\Store;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
function caUser(bool $factor=true): User {
    $u=User::factory()->create();
    $u->forceFill(['email_verified_at'=>now(),'account_activation_pending'=>false,'must_change_password'=>false,'two_factor_secret'=>$factor?'encrypted-fixture':null,'two_factor_confirmed_at'=>$factor?now():null])->save();
    return $u;
}
function caAdmin(): array {
    $u=caUser(); $k=app(Kernel::class); $id=$k->register((string)$u->getKey()); $k->seat(null,2,$id,'Test bootstrap',true); return [$u,$id,$k];
}
function caUrl(string $path=''): string { return '/administracion/autorizaciones'.$path; }
beforeEach(function() {
    if (!app()->environment('testing') || !str_ends_with((string)config('database.connections.mongodb.database'),'testing')) throw new RuntimeException('Dedicated testing database required.');
    config(['campus_authorization.enabled'=>true]);
    (require database_path('migrations/2026_10_10_060000_create_campus_authorization_indexes.php'))->up();
    $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,\App\Http\Middleware\EnsureSessionIsActive::class,\App\Http\Middleware\TrackDeviceSession::class]);
    $this->withoutVite();
});
test('campus administration requires a session and a global seat',function() {
    $this->getJson(caUrl('/records'))->assertUnauthorized();
    $this->actingAs(caUser())->getJson(caUrl('/records'))->assertForbidden();
    [$u,$id,$k]=caAdmin();
    $this->actingAs($u)->get(caUrl())->assertOk()->assertInertia(fn(Assert $page)=>$page->component('Authorization/Administration')->where('twoFactorReady',true));
    $this->getJson(caUrl('/records'))->assertOk()->assertJsonPath('data.seats.2',$id);
});
test('campus writes require confirmed second factor and derive the actor from session',function() {
    [$u,$id,$k]=caAdmin(); $target=caUser();
    $u->forceFill(['two_factor_confirmed_at'=>null])->save();
    $this->actingAs($u)->postJson(caUrl('/seats'),['identity'=>(string)$target->getKey(),'module'=>1,'version'=>1,'reason'=>'Assign'])->assertForbidden();
    $u->forceFill(['two_factor_confirmed_at'=>now()])->save();
    $this->actingAs($u)->postJson(caUrl('/seats'),['identity'=>(string)$target->getKey(),'module'=>1,'version'=>1,'reason'=>'Assign','actor'=>'forged'])->assertUnprocessable();
    $this->postJson(caUrl('/seats'),['identity'=>(string)$target->getKey(),'module'=>1,'version'=>1,'reason'=>'Assign'])->assertOk();
    $s=app(Kernel::class)->snapshot(); expect($s['events'][1]['actor'])->toBe($id)->and($s['events'][1]['audit']['channel'])->toBe('WEB');
});
test('campus competing stale forms cannot occupy a seat or duplicate audit',function() {
    [$u,$id,$k]=caAdmin(); $a=caUser();$b=caUser();
    $this->actingAs($u)->postJson(caUrl('/seats'),['identity'=>(string)$a->getKey(),'module'=>1,'version'=>1,'reason'=>'First'])->assertOk();
    $before=$k->snapshot();
    $this->postJson(caUrl('/seats'),['identity'=>(string)$b->getKey(),'module'=>1,'version'=>1,'reason'=>'Second stale form'])->assertConflict();
    expect($k->snapshot())->toBe($before)->and($k->principalFor((string)$b->getKey()))->toBeNull();
    $this->postJson(caUrl('/seats'),['identity'=>(string)$b->getKey(),'module'=>1,'version'=>2,'reason'=>'Occupied'])->assertConflict();
    expect($k->principalFor((string)$b->getKey()))->toBeNull();
});
test('campus modular grants isolate actions validate contexts and revoke immediately',function() {
    [$u,$id,$k]=caAdmin(); $target=caUser();
    DB::connection('mongodb')->table('campus_authorization_contexts')->insert(['type'=>'business','reference'=>'real-business','label'=>'Real','active'=>true]);
    $payload=['identity'=>(string)$target->getKey(),'module'=>2,'capabilities'=>['module.2.5.read'],'all_contexts'=>false,'scope_type'=>'business','scope_id'=>'invented','version'=>1,'reason'=>'Scoped'];
    $this->actingAs($u)->postJson(caUrl('/grants'),$payload)->assertConflict();
    expect($k->principalFor((string)$target->getKey()))->toBeNull();
    $payload['scope_id']='real-business';
    $grant=$this->postJson(caUrl('/grants'),$payload)->assertOk()->json('data');
    $principal=$k->principalFor((string)$target->getKey());
    expect($k->check($principal,'module.2.5.read',['type'=>'business','id'=>'real-business'])->allowed())->toBeTrue()
        ->and($k->check($principal,'module.2.5.create',['type'=>'business','id'=>'real-business'])->allowed())->toBeFalse();
    $this->actingAs($target)->getJson(caUrl('/records'))->assertForbidden();
    $this->actingAs($u)->postJson(caUrl('/grants/'.$grant.'/revoke'),['version'=>2,'reason'=>'Revoke'])->assertOk();
    expect($k->check($principal,'module.2.5.read',['type'=>'business','id'=>'real-business'])->allowed())->toBeFalse();
    $this->postJson(caUrl('/grants/'.$grant.'/revoke'),['version'=>3,'reason'=>'Again'])->assertConflict();
    expect($k->snapshot()['version'])->toBe(3);
});
test('campus scheduled grants and account changes are checked without positive cache',function() {
    [$u,$id,$k]=caAdmin();$target=caUser();
    $p=['identity'=>(string)$target->getKey(),'module'=>2,'capabilities'=>['module.2.6.execute'],'all_contexts'=>true,'version'=>1,'reason'=>'Scheduled','starts_at'=>now()->addHour()->toIso8601String(),'ends_at'=>now()->addHours(2)->toIso8601String()];
    $this->actingAs($u)->postJson(caUrl('/grants'),$p)->assertOk();
    expect($k->check($k->principalFor((string)$target->getKey()),'module.2.6.execute')->allowed())->toBeFalse();
    $u->forceFill(['account_activation_pending'=>true])->save();
    $this->getJson(caUrl('/records'))->assertForbidden();
});
test('campus release preserves principal and only another global administrator can release a seat',function() {
    [$u,$id,$k]=caAdmin(); $other=caUser(); $otherId=$k->register((string)$other->getKey()); $k->seat($id,1,$otherId,'Second');
    $this->actingAs($u)->postJson(caUrl('/seats/2/release'),['version'=>2,'reason'=>'Self'])->assertConflict();
    $this->actingAs($other)->postJson(caUrl('/seats/2/release'),['version'=>2,'reason'=>'Release'])->assertOk();
    expect($k->principalFor((string)$u->getKey()))->toBe($id)->and($k->check($id,'campus.roles.manage')->allowed())->toBeFalse();
    $this->actingAs($u)->getJson(caUrl('/records'))->assertForbidden();
});
test('campus Mongo transaction rejects a competing committed write without losing it',function() {
    [$u,$id,$k]=caAdmin(); $store=app(Store::class); $col=DB::connection('mongodb')->getMongoDB()->selectCollection('campus_authorization_state');
    $conflict=false;
    try {
        $store->transaction(function() use($store,$col) {
            $first=$store->get('authority');
            $committed=$first; $committed['version']=2;
            // Raw collection uses an independent session: another request commits after the first snapshot read.
            $session=DB::connection('mongodb')->getMongoClient()->startSession();
            try {
                $session->startTransaction();
                $col->updateOne(['key'=>'authority'],['$set'=>['payload'=>json_encode($committed,JSON_THROW_ON_ERROR)]],['session'=>$session]);
                $session->commitTransaction();
            } finally { $session->endSession(); }
            $first['version']=99; $store->put('authority',$first);
        });
    } catch (\Throwable $e) {
        $conflict=$e instanceof \App\Domains\CampusAuthorization\AuthorityConflict || in_array((int)$e->getCode(),[112,251],true) || str_contains($e->getMessage(),'WriteConflict');
        if (!$conflict) throw $e;
    }
    expect($conflict)->toBeTrue()->and(app(Kernel::class)->snapshot()['version'])->toBe(2);
});
test('campus disabled integration cannot consult or modify administration',function() {
    [$u,$id,$k]=caAdmin();config(['campus_authorization.enabled'=>false]);
    $this->actingAs($u)->getJson(caUrl('/records'))->assertForbidden();
});

test('campus web enforces seven distinct global holders and rejects mixed module capabilities',function() {
    [$u,$id,$k]=caAdmin();$this->actingAs($u);
    foreach([1,3,4,5,6,7] as $m) {
        $target=caUser();
        $this->postJson(caUrl('/seats'),['identity'=>(string)$target->getKey(),'module'=>$m,'version'=>$k->snapshot()['version'],'reason'=>'Fill seat'])->assertOk();
    }
    expect(count($k->snapshot()['seats']))->toBe(7);
    $extra=caUser();
    $this->postJson(caUrl('/seats'),['identity'=>(string)$extra->getKey(),'module'=>7,'version'=>7,'reason'=>'Eighth holder'])->assertConflict();
    $this->postJson(caUrl('/grants'),['identity'=>(string)$extra->getKey(),'module'=>2,'capabilities'=>['module.1.0.read'],'all_contexts'=>true,'version'=>7,'reason'=>'Cross module'])->assertConflict();
    expect($k->principalFor((string)$extra->getKey()))->toBeNull()->and($k->snapshot()['version'])->toBe(7);
});
