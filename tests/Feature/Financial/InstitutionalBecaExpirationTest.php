<?php

use App\Domains\Financial\Contracts\{InstitutionalBudgetAuthorization, FinancialWebAuthorizer, BonusAuthorizationProvider};
use App\Domains\Financial\Enums\{BonusType, BonusRestrictionType};
use App\Domains\Financial\Models\{InstitutionalBonusBudget, InstitutionalBudgetOperation, BonusBudgetCommitment, Bonus,
    BonusLedgerEntry, BonusAdministrativeOperation, Wallet};
use App\Domains\Financial\Services\{InstitutionalBudgetService, InstitutionalBecaIssuanceService, BonusService, BonusAdministrationService};
use App\Domains\Financial\Exceptions\IdentityFinancialAuthorizationUnavailable;
use App\Models\User;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

beforeEach(function () {
    if (!app()->environment('testing') || config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing'
        || config('database.connections.mongodb.database') !== 'campus_virtual_testing') throw new RuntimeException('Solo bases de pruebas.');
    DB::connection('sqlsrv')->beginTransaction(); Http::preventStrayRequests(); $this->freezeTime();
    $this->bxOperator = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->bxRecipient = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->bxActor = (string) $this->bxOperator->getKey();
    $this->bxAuthority = new class implements InstitutionalBudgetAuthorization {
        public bool $commit = true; public bool $release = true; public bool $expire = true;
        public function allows(string $actorId, string $action, ?string $budgetId = null): bool { return match ($action) { 'budget.commit' => $this->commit, 'budget.release' => $this->release, 'budget.expire' => $this->expire, default => true };  }
    };
    $this->bxAccess = new class implements FinancialWebAuthorizer {
        public bool $issue = true; public bool $cancel = true;
        public function allows(string $userId, string $action, ?string $walletId = null): bool { return match ($action) { 'bonus.issue' => $this->issue, 'bonus.cancel' => $this->cancel, default => false };  }
    };
    $this->bxIssuer = new class implements BonusAuthorizationProvider {
        public bool $allowed = true; public bool $unavailable = false;
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $type): bool {
            if ($this->unavailable) throw new IdentityFinancialAuthorizationUnavailable('FIXTURE_UNAVAILABLE');
            return $this->allowed && $issuerType === 'USER' && $type === BonusType::BECA;
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class, $this->bxAuthority);
    app()->instance(FinancialWebAuthorizer::class, $this->bxAccess);
    app()->instance(BonusAuthorizationProvider::class, $this->bxIssuer);
    $this->bxService = app(InstitutionalBecaIssuanceService::class);
    $data = ['institution_reference' => 'fixture-institution', 'approval_reference' => 'approval-'.Str::uuid(),
        'payer_reference' => 'fixture-approved-payer', 'purpose' => 'Programa BECA', 'approved_amount_cents' => 10000,
        'currency' => 'MXN', 'reason' => 'Aprobacion de prueba', 'valid_from' => now()->subDay()->toISOString(), 'expires_at' => now()->addMonth()->toISOString()];
    $r = app(InstitutionalBudgetService::class)->approve($this->bxActor, $data, 'approve-'.Str::uuid());
    $this->bxBudget = InstitutionalBonusBudget::where('public_id', $r['operation']->budget_id)->firstOrFail();
});
afterEach(function () {
    InstitutionalBudgetOperation::flushEventListeners(); BonusBudgetCommitment::flushEventListeners(); BonusLedgerEntry::flushEventListeners();
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});
function bxIssuePayload($test): array
{
    return ['award_reference' => 'fixture-award-'.Str::uuid(), 'beneficiary_id' => (string) $test->bxRecipient->getKey(), 'type' => 'BECA', 'amount_cents' => 3000,
        'valid_from' => now()->subMinute()->toISOString(), 'expires_at' => now()->addDay()->toISOString(),
        'combinable' => true, 'allows_partial_use' => true, 'reason' => 'Beca de prueba con presupuesto',
        'external_reference' => 'test-budget-issue-'.Str::uuid(), 'restrictions' => []];
}
function bxIssue($test, ?array $data = null, ?string $key = null, int $version = 1): array
{
    return $test->bxService->issue($test->bxActor, $test->bxBudget->public_id, $version,
        $data ?? bxIssuePayload($test), $key ?? 'issue-'.Str::uuid());
}
function bxState($test): array
{
    return [$test->bxBudget->fresh()->toArray(), Bonus::count(), BonusLedgerEntry::count(), BonusAdministrativeOperation::count(),
        BonusBudgetCommitment::count(), InstitutionalBudgetOperation::count(),
        Wallet::orderBy('id')->get(['public_id','available_balance_cents','held_balance_cents'])->toArray()];
}


function bxRelease($test, string $id, ?string $key = null, bool $expiry = false, int $version = 2, string $reason = 'Liberacion de prueba'): array
{
    $service = app(\App\Domains\Financial\Services\InstitutionalBecaReleaseService::class);
    return $service->{$expiry ? 'expire' : 'cancel'}($test->bxActor, $test->bxBudget->public_id, $id, $version, $reason, $key ?? 'release-'.Str::uuid());
}

function bxSweep($t, bool $execute = true, int $limit = 100, int $after = 0, ?int $through = null, ?string $cutoff = null): array {
    return app(\App\Domains\Financial\Services\InstitutionalBecaExpirationService::class)->batch($t->bxActor,$execute,$limit,$after,$through,$cutoff);
}
function bxDue($t): array {
    $r=bxIssue($t,null,null,$t->bxBudget->fresh()->version);
    $t->travelTo(Bonus::where('public_id',$r['bonus_id'])->firstOrFail()->expires_at);
    return $r;
}

test('institutional expiration preview is read only even with default denial and does not claim authorization',function(){
    bxDue($this); app()->instance(InstitutionalBudgetAuthorization::class,new \App\Domains\Financial\Adapters\PendingInstitutionalBudgetAuthorization());
    $before=bxState($this);$r=bxSweep($this,false);
    expect($r['counts'])->toBe(['PREVIEW'=>1])->and(bxState($this))->toBe($before); Http::assertNothingSent();
});
test('institutional expiration releases only at expiry and a repeated scan cannot duplicate evidence',function(){
    $r=bxIssue($this);$bonus=Bonus::where('public_id',$r['bonus_id'])->firstOrFail();
    expect(bxSweep($this)['items'])->toBe([]);
    $this->travelTo($bonus->expires_at);$result=bxSweep($this);
    expect($result['counts'])->toBe(['EXPIRED'=>1])->and($result['items'][0]['released_amount_cents'])->toBe('3000')
        ->and($bonus->fresh()->status)->toBe(\App\Domains\Financial\Enums\BonusStatus::EXPIRADO)
        ->and($this->bxBudget->fresh()->committed_amount_cents)->toBe(0);
    $before=bxState($this); expect(bxSweep($this)['items'])->toBe([])->and(bxState($this))->toBe($before);
    Http::assertNothingSent();
});
test('institutional expiration default denial and revocation never release a commitment',function(){
    bxDue($this);$before=bxState($this);$this->bxAuthority->expire=false;
    expect(bxSweep($this)['counts'])->toBe(['DENIED'=>1])->and(bxState($this))->toBe($before);
    app()->instance(InstitutionalBudgetAuthorization::class,new \App\Domains\Financial\Adapters\PendingInstitutionalBudgetAuthorization());
    expect(bxSweep($this)['counts'])->toBe(['DENIED'=>1])->and(bxState($this))->toBe($before);
});
test('institutional expiration does not inherit cancellation rights and can process suspended budgets',function(){
    bxDue($this);$this->bxAccess->cancel=false;$this->bxAuthority->release=false;$this->bxBudget->update(['status'=>'SUSPENDED']);
    expect(bxSweep($this)['counts'])->toBe(['EXPIRED'=>1])->and($this->bxBudget->fresh()->status)->toBe('SUSPENDED');
});
test('institutional expiration keyset advances beyond denied rows and fixes the initial high water mark',function(){
    $a=bxIssue($this);$b=bxIssue($this,null,null,2);$this->travelTo(Bonus::where('public_id',$b['bonus_id'])->firstOrFail()->expires_at);
    $this->bxAuthority->expire=false;$one=bxSweep($this,true,1);
    expect($one['has_more'])->toBeTrue()->and($one['counts'])->toBe(['DENIED'=>1]);
    $this->bxAuthority->expire=true;$two=bxSweep($this,true,1,$one['next_cursor'],$one['through'],$one['cutoff']);
    expect($two['has_more'])->toBeFalse()->and($two['items'][0]['bonus_id'])->toBe(strtolower($b['bonus_id']))
        ->and($two['counts'])->toBe(['EXPIRED'=>1]);
    expect(Bonus::where('public_id',$a['bonus_id'])->firstOrFail()->remaining_amount_cents)->toBe(3000);
});
test('institutional expiration fixed cutoff and high water do not include later due awards',function(){
    $a=bxIssue($this);$b=bxIssue($this,null,null,2);$this->travelTo(Bonus::where('public_id',$b['bonus_id'])->firstOrFail()->expires_at);
    $page=bxSweep($this,false,1);
    // A new award expires later; it must wait for a new scan.
    $c=bxIssue($this,null,null,3);$this->travelTo(Bonus::where('public_id',$c['bonus_id'])->firstOrFail()->expires_at);
    $next=bxSweep($this,false,100,$page['next_cursor'],$page['through'],$page['cutoff']);
    expect(array_column($next['items'],'bonus_id'))->toBe([strtolower($b['bonus_id'])]);
});
test('institutional expiration catches missing markers rather than expiring through the legacy path',function(){
    $r=bxDue($this);DB::connection('sqlsrv')->table('bonuses')->where('public_id',$r['bonus_id'])->update(['institutional_budget_id'=>null]);
    $before=bxState($this);expect(bxSweep($this)['counts'])->toBe(['REVIEW_REQUIRED'=>1])->and(bxState($this))->toBe($before);
});
test('institutional expiration corrupt accounting is left intact for review',function(){
    bxDue($this);$this->bxBudget->increment('committed_amount_cents');$before=bxState($this);
    expect(bxSweep($this)['counts'])->toBe(['REVIEW_REQUIRED'=>1])->and(bxState($this))->toBe($before);
});
test('institutional expiration audit failure rolls back and next scan can retry the stable operation key',function(){
    bxDue($this);$before=bxState($this);
    InstitutionalBudgetOperation::creating(function($op){if($op->action==='EXPIRE_BECA')throw new RuntimeException('PRIVATE_AUDIT_FAILURE');});
    try {$r=bxSweep($this);expect($r['counts'])->toBe(['UNAVAILABLE'=>1])->and(json_encode($r))->not->toContain('PRIVATE_AUDIT_FAILURE');}
    finally {InstitutionalBudgetOperation::flushEventListeners();}
    expect(bxState($this))->toBe($before)->and(bxSweep($this)['counts'])->toBe(['EXPIRED'=>1]);
});
test('institutional expiration occupied domain lock leaves pending work without duplicate evidence',function(){
    $r=bxDue($this);$key='institution-budget:'.hash('sha256','institutional-beca-expire:'.strtolower($r['bonus_id']));
    $locks=app(\App\Domains\Financial\Support\FinancialJobLock::class);$token=$locks->acquire($key,180);$before=bxState($this);
    try {expect(bxSweep($this)['counts'])->toBe(['REVIEW_REQUIRED'=>1])->and(bxState($this))->toBe($before);}
    finally {$locks->release($key,$token);}
    expect(bxSweep($this)['counts'])->toBe(['EXPIRED'=>1]);
});
test('institutional expiration preserves consumed obligations purchases wallet and outbox exactly',function(){
    $r=bxIssue($this);$buyer=(string)$this->bxRecipient->getKey();
    $wallet=app(\App\Domains\Financial\Services\WalletService::class)->create('USER',$buyer,\App\Domains\Financial\Enums\WalletType::USUARIO);
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class,new class implements \App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization{
        public function allows(string $actorId,string $action,array $context):bool{return $action==='purchase.pay';}
    });
    app(\App\Domains\Financial\Services\InstitutionalBecaPurchaseService::class)->pay($wallet,$r['bonus_id'],1000,'consume-'.Str::uuid(),$buyer,'fixture-consent',[
        'order_id'=>'fixture-order-'.Str::uuid(),'business_id'=>'fixture-business','items'=>[]]);
    $tables=['wallets','purchase_payments','institutional_bonus_obligations','financial_transactions','ledger_entries','financial_team4_outbox','financial_team4_streams'];
    $snapshot=function()use($tables){$a=[];foreach($tables as$t)$a[$t]=DB::connection('sqlsrv')->table($t)->orderBy('id')->get()->toArray();return $a;};
    $before=$snapshot();$this->travelTo(Bonus::where('public_id',$r['bonus_id'])->firstOrFail()->expires_at);
    $s=bxSweep($this);expect($s['items'][0]['released_amount_cents'])->toBe('2000')->and($snapshot())->toEqual($before)
        ->and($this->bxBudget->fresh()->consumed_amount_cents)->toBe(1000)->and($this->bxBudget->fresh()->committed_amount_cents)->toBe(0);
});
test('institutional expiration excludes legacy unbacked and terminal bonuses',function(){
    $r=bxIssue($this);bxRelease($this,$r['bonus_id']);
    app(BonusService::class)->issue('USER',(string)$this->bxRecipient->getKey(),'USER',$this->bxActor,BonusType::BECA,500,now()->subMinute(),now()->addHour(),true,true,'fixture-unbacked-'.Str::uuid());
    $this->travel(2)->days();$before=bxState($this);expect(bxSweep($this)['items'])->toBe([])->and(bxState($this))->toBe($before);
});
test('institutional expiration validates input and CLI defaults to preview with sanitized failures',function(){
    bxDue($this);$before=bxState($this);$service=app(\App\Domains\Financial\Services\InstitutionalBecaExpirationService::class);
    foreach([0,501]as$limit)expect(fn()=>$service->batch($this->bxActor,true,$limit))->toThrow(InvalidArgumentException::class);
    expect(fn()=>$service->batch('bad',true))->toThrow(InvalidArgumentException::class);
    $this->artisan('financial:expire-institutional-becas',['--actor'=>$this->bxActor])->assertSuccessful();
    expect(bxState($this))->toBe($before);
    $this->artisan('financial:expire-institutional-becas',['--actor'=>$this->bxActor,'--limit'=>'1e2'])->assertExitCode(2);
    $this->bxAuthority->expire=false;
    $this->artisan('financial:expire-institutional-becas',['--actor'=>$this->bxActor,'--confirm'=>true,'--all'=>true])->assertFailed();
    expect(bxState($this))->toBe($before);
});
test('institutional expiration CLI traverses batches and configuration never implies domain authority',function(){
    bxIssue($this);$b=bxIssue($this,null,null,2);$this->travelTo(Bonus::where('public_id',$b['bonus_id'])->firstOrFail()->expires_at);
    config(['financial_institutional_expiration.actor_id'=>$this->bxActor]);
    $this->artisan('financial:expire-institutional-becas',['--confirm'=>true,'--all'=>true,'--limit'=>'1'])->assertSuccessful();
    expect($this->bxBudget->fresh()->committed_amount_cents)->toBe(0)
        ->and(InstitutionalBudgetOperation::where('action','EXPIRE_BECA')->count())->toBe(2);
});
test('institutional expiration ledger failure rolls back the release and does not expose details',function(){
    bxDue($this);$before=bxState($this);
    BonusLedgerEntry::creating(function($e){if($e->reference_type==='BUDGET_RELEASE')throw new RuntimeException('PRIVATE_LEDGER_FAILURE');});
    try {$r=bxSweep($this);expect($r['counts'])->toBe(['UNAVAILABLE'=>1])->and(json_encode($r))->not->toContain('PRIVATE_LEDGER_FAILURE');}
    finally {BonusLedgerEntry::flushEventListeners();}
    expect(bxState($this))->toBe($before)->and(bxSweep($this)['counts'])->toBe(['EXPIRED'=>1]);
});
test('institutional expiration schedule is absent by default and enabled task still grants no authority',function(){
    $scheduleClass=\Illuminate\Console\Scheduling\Schedule::class;
    $matching=function($schedule){return collect($schedule->events())->filter(fn($e)=>str_contains((string)($e->command??''),'financial:expire-institutional-becas'))->values();};
    config(['financial_institutional_expiration.schedule_enabled'=>false]);app()->forgetInstance($scheduleClass);
    expect($matching(app($scheduleClass)))->toHaveCount(0);
    config(['financial_institutional_expiration.schedule_enabled'=>true,'financial_institutional_expiration.actor_id'=>'invalid']);app()->forgetInstance($scheduleClass);
    expect($matching(app($scheduleClass)))->toHaveCount(0);
    config(['financial_institutional_expiration.actor_id'=>$this->bxActor]);app()->forgetInstance($scheduleClass);
    $events=$matching(app($scheduleClass));expect($events)->toHaveCount(1)->and($events[0]->expression)->toBe('* * * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue()->and($events[0]->command)->toContain('--confirm','--all');
    bxDue($this);$before=bxState($this);
    app()->instance(InstitutionalBudgetAuthorization::class,new \App\Domains\Financial\Adapters\PendingInstitutionalBudgetAuthorization());
    $this->artisan('financial:expire-institutional-becas',['--confirm'=>true,'--all'=>true])->assertFailed();
    expect(bxState($this))->toBe($before);
});
