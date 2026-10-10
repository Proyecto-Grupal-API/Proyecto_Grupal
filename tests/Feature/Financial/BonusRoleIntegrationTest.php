<?php

use App\Domains\Identity\Contracts\ScopedCapabilityAuthorization;
use App\Domains\Identity\Authorization\FinancialRoleCatalog as Catalog;
use App\Domains\Identity\Exceptions\AuthorizationDependencyUnavailable;
use App\Domains\Financial\Contracts\BonusAuthorizationProvider;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Models\Bonus;
use App\Domains\Financial\Models\BonusAdministrativeOperation;
use App\Domains\Financial\Models\BonusLedgerEntry;
use App\Domains\Financial\Models\BonusRestriction;
use App\Domains\Financial\Models\Wallet;
use App\Models\RoleAssignment;
use App\Models\BusinessMembership;
use App\Models\User;
use Database\Seeders\FinancialAuthorizationCatalogSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    if (config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing'
        || \Illuminate\Support\Facades\DB::connection('mongodb')->getDatabaseName() !== 'campus_virtual_testing') {
        throw new RuntimeException('Prueba de roles limitada a las bases dedicadas de testing.');
    }
    config(['financial.bonus_roles.enabled' => true]);
    (require database_path('migrations/2026_10_10_050000_create_bonus_role_authorization_indexes.php'))->up();
    $this->seed(FinancialAuthorizationCatalogSeeder::class);
    \App\Models\Permission::whereIn('key', array_keys(Catalog::PERMISSIONS))->update(['active' => true]);
    $this->withoutVite(); $this->withoutMiddleware(PreventRequestForgery::class);
    $this->bonusIds = [];
});
afterEach(function () {
    $ids = Bonus::where('external_reference','like','test-bonus-role-%')->pluck('public_id');
    BonusAdministrativeOperation::whereIn('bonus_id',$ids)->delete(); BonusLedgerEntry::whereIn('bonus_id',$ids)->delete();
    BonusRestriction::whereIn('bonus_id',$ids)->delete(); Bonus::whereIn('public_id',$ids)->delete();
});
function brUser(): User { return User::factory()->create(['email'=>'test-bonus-role-'.Str::uuid().'@example.test','email_verified_at'=>now()]); }
function brRole(User $u, string $role='admin', ?string $scope=null, ?string $id=null, array $extra=[]): RoleAssignment {
    return RoleAssignment::create([...['user_id'=>(string)$u->getKey(),'role_key'=>$role,'scope_type'=>$scope,'scope_id'=>$id,'status'=>'active','reason'=>'Prueba de roles'],...$extra]);
}
function brPayload(User $u, string $type='BENEFICIO', ?string $scope=null, ?string $id=null): array {
    return ['beneficiary_id'=>(string)$u->getKey(),'type'=>$type,'issuer_scope_type'=>$scope,'issuer_scope_id'=>$id,'amount_cents'=>10000,
        'valid_from'=>now()->subMinute()->toISOString(),'expires_at'=>now()->addDay()->toISOString(),'combinable'=>true,'allows_partial_use'=>true,
        'external_reference'=>'test-bonus-role-'.Str::uuid(),'reason'=>'Apoyo aprobado','restrictions'=>in_array($type,['NEGOCIO','CATEGORIA'])?[['type'=>$type,'target_id'=>$id??'category-A']]:[]];
}
function brKey(): string { return 'test-bonus-role-'.Str::uuid(); }
function brIssue($test, User $u, array $data, ?string $key=null) {
    return $test->actingAs($u)->postJson('/finanzas/bonos/administracion/issue',$data,['Idempotency-Key'=>$key??brKey(),'X-Correlation-Id'=>'test-bonus-role-correlation']);
}

test('role integration uses no embedded legacy roles and can be switched off without deleting data', function () {
    $u=brUser();$recipient=brUser();$u->assignRole('admin');
    brIssue($this,$u,brPayload($recipient))->assertForbidden();
    brRole($u);$response=brIssue($this,$u,brPayload($recipient))->assertCreated();
    $id=$response->json('data.bonus_id');config(['financial.bonus_roles.enabled'=>false]);
    brIssue($this,$u,brPayload($recipient))->assertForbidden();
    expect(Bonus::where('public_id',$id)->exists())->toBeTrue()->and(BonusLedgerEntry::where('bonus_id',$id)->count())->toBe(1);
    expect(app(BonusAuthorizationProvider::class)->canIssueBonus('USER',(string)$u->getKey(),BonusType::BECA))->toBeFalse();
});

test('every role obeys the closed issuance matrix and business membership', function (string $role) {
    $u=brUser();$scope=Catalog::ROLES[$role][1];$id=$scope?'A':null;brRole($u,$role,$scope,$id);
    if($scope==='business'){BusinessMembership::create(['user_id'=>(string)$u->getKey(),'business_id'=>$id,'status'=>'active']);}
    foreach(BonusType::cases() as $type){expect(app(ScopedCapabilityAuthorization::class)->authorized((string)$u->getKey(),'bonus.issue.'.$type->value,$scope,$id))
        ->toBe(in_array('bonus.issue.'.$type->value,Catalog::MAPPINGS[$role]??[],true));}
})->with(array_keys(Catalog::ROLES));

test('committee issuance stores its context and audit once with immutable replay and no wallet credit', function () {
    $u=brUser();$recipient=brUser();brRole($u,'scholarship_committee','council','C');$data=brPayload($recipient,'BECA','council','C');$key=brKey();
    $balances=Wallet::orderBy('id')->get(['public_id','available_balance_cents','held_balance_cents'])->toArray();
    $first=brIssue($this,$u,$data,$key)->assertCreated();$id=$first->json('data.bonus_id');
    brIssue($this,$u,$data,$key)->assertOk()->assertJsonPath('data.bonus_id',$id)->assertJsonPath('replayed',true);
    $b=Bonus::where('public_id',$id)->firstOrFail();$op=BonusAdministrativeOperation::where('idempotency_key',$key)->firstOrFail();
    expect($b->issuer_scope_type)->toBe('council')->and($b->issuer_scope_id)->toBe('C')->and($b->issuer_id)->toBe((string)$u->getKey())
        ->and($op->request_data['audit_context']['correlation_id'])->toBe('test-bonus-role-correlation')->and($b->ledgerEntries()->count())->toBe(1)
        ->and(Wallet::orderBy('id')->get(['public_id','available_balance_cents','held_balance_cents'])->toArray())->toBe($balances);
    $changed=$data;$changed['issuer_scope_id']='OTHER';brIssue($this,$u,$changed,$key)->assertForbidden();
    $changed=$data;$changed['amount_cents']=10100;brIssue($this,$u,$changed,$key)->assertConflict();
});

test('issuance permissions and contexts shown in the interface match actual grants', function () {
    $u=brUser();brRole($u,'scholarship_committee','council','C');
    $this->actingAs($u)->get('/finanzas/bonos/administracion')->assertOk()->assertInertia(fn(Assert $p)=>$p->component('Financial/BonusAdministration')
        ->where('roleIntegration',true)->where('permissions.issue',true)->where('permissions.manage_roles',false)
        ->where('issuerContexts.0.scope_type','council')->where('issuerContexts.0.scope_id','C')->where('issuerContexts.0.types',['BECA']));
    brIssue($this,$u,brPayload(brUser(),'BENEFICIO','council','C'))->assertForbidden();
    brIssue($this,$u,brPayload(brUser(),'BECA'))->assertForbidden();
});

test('business issuance cannot escape the authorized restriction and suspension blocks replay', function () {
    $u=brUser();$recipient=brUser();brRole($u,'business_owner','business','A');
    $m=BusinessMembership::create(['user_id'=>(string)$u->getKey(),'business_id'=>'A','status'=>'active']);
    $data=brPayload($recipient,'NEGOCIO','business','A');$bad=$data;$bad['restrictions'][0]['target_id']='B';
    brIssue($this,$u,$bad)->assertConflict();$key=brKey();brIssue($this,$u,$data,$key)->assertCreated();
    $m->transitionTo('suspended');brIssue($this,$u,$data,$key)->assertForbidden();
});

test('contextual list history and cancellation are isolated and permission is rechecked', function () {
    $a=brUser();$b=brUser();$recipient=brUser();$role=brRole($a,'association_president','association','A');brRole($b,'association_president','association','B');
    $response=brIssue($this,$a,brPayload($recipient,'BENEFICIO','association','A'))->assertCreated();$id=$response->json('data.bonus_id');
    $this->actingAs($b)->getJson('/finanzas/bonos/administracion/records')->assertOk()->assertJsonCount(0,'data');
    $this->getJson('/finanzas/bonos/administracion/'.$id.'/history')->assertNotFound();
    $this->postJson('/finanzas/bonos/administracion/'.$id.'/cancel',['reason'=>'Ajeno'],['Idempotency-Key'=>brKey()])->assertNotFound();
    $this->actingAs($a)->getJson('/finanzas/bonos/administracion/records')->assertOk()->assertJsonCount(1,'data');
    $key=brKey();$url='/finanzas/bonos/administracion/'.$id.'/cancel';
    $this->postJson($url,['reason'=>'Fin del apoyo'],['Idempotency-Key'=>$key])->assertCreated();
    $this->postJson($url,['reason'=>'Fin del apoyo'],['Idempotency-Key'=>$key])->assertOk();
    $role->transitionTo('revoked');$this->postJson($url,['reason'=>'Fin del apoyo'],['Idempotency-Key'=>$key])->assertForbidden();
    expect(BonusLedgerEntry::where('bonus_id',$id)->where('movement_type','CANCELACION')->count())->toBe(1);
});

test('auditor can query all bonus contexts but cannot issue cancel or administer roles', function () {
    $admin=brUser();brRole($admin);$auditor=brUser();brRole($auditor,'auditor');$recipient=brUser();
    $id=brIssue($this,$admin,brPayload($recipient))->assertCreated()->json('data.bonus_id');
    $this->actingAs($auditor)->getJson('/finanzas/bonos/administracion/records?beneficiary_id='.$recipient->getKey())->assertOk()->assertJsonCount(1,'data');
    $this->getJson('/finanzas/bonos/administracion/'.$id.'/history')->assertOk();
    $this->postJson('/finanzas/bonos/administracion/'.$id.'/cancel',['reason'=>'Prohibido'],['Idempotency-Key'=>brKey()])->assertForbidden();
    brIssue($this,$auditor,brPayload($recipient))->assertForbidden();$this->get('/finanzas/bonos/roles')->assertForbidden();
});

test('revocation expiry and disabled permission cannot authorize issuer even before service replay', function () {
    $this->freezeTime();$u=brUser();$recipient=brUser();brRole($u,'scholarship_committee','council','C',['ends_at'=>now()->addMinute()]);
    $data=brPayload($recipient,'BECA','council','C');$key=brKey();brIssue($this,$u,$data,$key)->assertCreated();
    $this->travel(1)->minutes();brIssue($this,$u,$data,$key)->assertForbidden();
    $admin=brUser();brRole($admin);\App\Models\Permission::where('key','bonus.issue.BECA')->firstOrFail()->fill(['active'=>false])->save();
    brIssue($this,$admin,brPayload($recipient,'BECA'))->assertForbidden();
});

test('role approval outages return 503 and cannot create a bonus', function () {
    $u=brUser();$recipient=brUser();$data=brPayload($recipient);
    app()->instance(ScopedCapabilityAuthorization::class,new class implements ScopedCapabilityAuthorization{
        public function authorized(string $u,string $c,?string $t=null,?string $id=null):bool{throw new AuthorizationDependencyUnavailable('Fixture');}
        public function contexts(string $u,string $c):array{throw new AuthorizationDependencyUnavailable('Fixture');}
    });
    brIssue($this,$u,$data)->assertStatus(503)->assertJsonPath('code','BONUS_AUTHORIZATION_UNAVAILABLE');
    expect(Bonus::where('external_reference',$data['external_reference'])->exists())->toBeFalse();
});

test('new role integration keeps the whole emission atomic after audit failure', function () {
    $u=brUser();brRole($u,'scholarship_committee','council','C');$data=brPayload(brUser(),'BECA','council','C');$key=brKey();
    BonusAdministrativeOperation::creating(fn()=>throw new RuntimeException('test-bonus-role-failure'));
    try{brIssue($this,$u,$data,$key)->assertStatus(500);}finally{BonusAdministrativeOperation::flushEventListeners();}
    expect(Bonus::where('external_reference',$data['external_reference'])->exists())->toBeFalse();
    brIssue($this,$u,$data,$key)->assertCreated();
});

test('role administration requires global authority and confirmed two factor and preserves generations', function () {
    $admin=brUser();brRole($admin);$target=brUser();$d=['user_id'=>(string)$target->getKey(),'role_key'=>'scholarship_committee','scope_type'=>'council','scope_id'=>'C','reason'=>'Designación aprobada'];
    $this->actingAs($admin)->postJson('/finanzas/bonos/roles',$d)->assertForbidden();
    $admin->forceFill(['two_factor_secret'=>'test-confirmed-factor','two_factor_confirmed_at'=>now()])->save();
    $this->actingAs($admin)->get('/finanzas/bonos/roles')->assertOk()->assertInertia(fn(Assert $p)=>$p->component('Financial/BonusRoles')->where('twoFactorReady',true));
    $id=$this->postJson('/finanzas/bonos/roles',$d)->assertOk()->json('data.id');
    expect(app(ScopedCapabilityAuthorization::class)->authorized((string)$target->getKey(),'bonus.issue.BECA','council','C'))->toBeTrue();
    $this->postJson('/finanzas/bonos/roles',$d)->assertConflict();
    $this->postJson('/finanzas/bonos/roles/'.$id.'/revoke',['reason'=>'Fin del nombramiento'])->assertOk();
    expect(app(ScopedCapabilityAuthorization::class)->authorized((string)$target->getKey(),'bonus.issue.BECA','council','C'))->toBeFalse();
    $this->postJson('/finanzas/bonos/roles',$d)->assertOk()->assertJsonPath('data.generation',2);
    expect(RoleAssignment::where('user_id',(string)$target->getKey())->count())->toBe(2)
        ->and(RoleAssignment::whereKey($id)->firstOrFail()->initial_reason)->toBe('Designación aprobada');
});

test('non administrators cannot grant roles and contextual admin never gains platform authority', function () {
    $u=brUser();brRole($u,'admin','association','A');$target=brUser();
    $this->actingAs($u)->get('/finanzas/bonos/roles')->assertForbidden();
    $this->postJson('/finanzas/bonos/roles',['user_id'=>(string)$target->getKey(),'role_key'=>'admin','reason'=>'Escalación'])->assertForbidden();
    expect(RoleAssignment::where('user_id',(string)$target->getKey())->exists())->toBeFalse();
});

test('role assignment failure rolls back its associated business membership', function () {
    $admin=brUser();brRole($admin);$admin->forceFill(['two_factor_secret'=>'fixture','two_factor_confirmed_at'=>now()])->save();$target=brUser();
    $d=['user_id'=>(string)$target->getKey(),'role_key'=>'business_owner','scope_type'=>'business','scope_id'=>'A','reason'=>'Alta de responsable'];
    $fail = true;
    RoleAssignment::creating(function () use (&$fail) { if ($fail) { $fail = false; throw new RuntimeException('test-bonus-role-assignment-failure'); } });
    $this->actingAs($admin)->postJson('/finanzas/bonos/roles',$d)->assertStatus(500);
    expect(BusinessMembership::where('user_id',(string)$target->getKey())->exists())->toBeFalse();
    $this->postJson('/finanzas/bonos/roles',$d)->assertOk();
    expect(BusinessMembership::where('user_id',(string)$target->getKey())->count())->toBe(1);
});


test('the issuer context is immutable and cross context reads require the explicit all permission', function () {
    $admin=brUser();brRole($admin);$recipient=brUser();
    $id=brIssue($this,$admin,brPayload($recipient,'BECA','council','C'))->assertCreated()->json('data.bonus_id');
    $bonus=Bonus::where('public_id',$id)->firstOrFail();
    expect(fn()=>$bonus->fill(['issuer_scope_id'=>'OTHER'])->save())->toThrow(InvalidArgumentException::class);
    expect($bonus->fresh()->issuer_scope_id)->toBe('C');
    \App\Models\Permission::where('key','bonus.view.all')->firstOrFail()->fill(['active'=>false])->save();
    $this->actingAs($admin)->getJson('/finanzas/bonos/administracion/'.$id.'/history')->assertNotFound();
    expect(app(ScopedCapabilityAuthorization::class)->authorized((string)$admin->getKey(),'bonus.view','council','C'))->toBeFalse();
});
