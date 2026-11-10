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
    $this->raOperator = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->raRecipient = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->raActor = (string) $this->raOperator->getKey();
    $this->raAuthority = new class implements InstitutionalBudgetAuthorization {
        public bool $commit = true; public bool $release = true; public bool $expire = true;
        public function allows(string $actorId, string $action, ?string $budgetId = null): bool { return match ($action) { 'budget.commit' => $this->commit, 'budget.release' => $this->release, 'budget.expire' => $this->expire, default => true };  }
    };
    $this->raAccess = new class implements FinancialWebAuthorizer {
        public bool $issue = true; public bool $cancel = true;
        public function allows(string $userId, string $action, ?string $walletId = null): bool { return match ($action) { 'bonus.issue' => $this->issue, 'bonus.cancel' => $this->cancel, default => false };  }
    };
    $this->raIssuer = new class implements BonusAuthorizationProvider {
        public bool $allowed = true; public bool $unavailable = false;
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $type): bool {
            if ($this->unavailable) throw new IdentityFinancialAuthorizationUnavailable('FIXTURE_UNAVAILABLE');
            return $this->allowed && $issuerType === 'USER' && $type === BonusType::BECA;
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class, $this->raAuthority);
    app()->instance(FinancialWebAuthorizer::class, $this->raAccess);
    app()->instance(BonusAuthorizationProvider::class, $this->raIssuer);
    $this->raService = app(InstitutionalBecaIssuanceService::class);
    $data = ['institution_reference' => 'fixture-institution', 'approval_reference' => 'approval-'.Str::uuid(),
        'payer_reference' => 'fixture-approved-payer', 'purpose' => 'Programa BECA', 'approved_amount_cents' => 10000,
        'currency' => 'MXN', 'reason' => 'Aprobacion de prueba', 'valid_from' => now()->subDay()->toISOString(), 'expires_at' => now()->addMonth()->toISOString()];
    $r = app(InstitutionalBudgetService::class)->approve($this->raActor, $data, 'approve-'.Str::uuid());
    $this->raBudget = InstitutionalBonusBudget::where('public_id', $r['operation']->budget_id)->firstOrFail();
});
afterEach(function () {
    InstitutionalBudgetOperation::flushEventListeners(); BonusBudgetCommitment::flushEventListeners(); BonusLedgerEntry::flushEventListeners();
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});
function raIssuePayload($test): array
{
    return ['award_reference' => 'fixture-award-'.Str::uuid(), 'beneficiary_id' => (string) $test->raRecipient->getKey(), 'type' => 'BECA', 'amount_cents' => 3000,
        'valid_from' => now()->subMinute()->toISOString(), 'expires_at' => now()->addDay()->toISOString(),
        'combinable' => true, 'allows_partial_use' => true, 'reason' => 'Beca de prueba con presupuesto',
        'external_reference' => 'test-budget-issue-'.Str::uuid(), 'restrictions' => []];
}
function raIssue($test, ?array $data = null, ?string $key = null, int $version = 1): array
{
    return $test->raService->issue($test->raActor, $test->raBudget->public_id, $version,
        $data ?? raIssuePayload($test), $key ?? 'issue-'.Str::uuid());
}
function raState($test): array
{
    return [$test->raBudget->fresh()->toArray(), Bonus::count(), BonusLedgerEntry::count(), BonusAdministrativeOperation::count(),
        BonusBudgetCommitment::count(), InstitutionalBudgetOperation::count(),
        Wallet::orderBy('id')->get(['public_id','available_balance_cents','held_balance_cents'])->toArray()];
}


function raRelease($test, string $id, ?string $key = null, bool $expiry = false, int $version = 2, string $reason = 'Liberacion de prueba'): array
{
    $service = app(\App\Domains\Financial\Services\InstitutionalBecaReleaseService::class);
    return $service->{$expiry ? 'expire' : 'cancel'}($test->raActor, $test->raBudget->public_id, $id, $version, $reason, $key ?? 'release-'.Str::uuid());
}

function raSnapshot():array {
    $out=[];foreach(['institutional_bonus_budgets','institutional_budget_operations','bonuses','bonus_ledger_entries','bonus_budget_commitments','bonus_administrative_operations','wallets','financial_transactions','ledger_entries']as$t)
        $out[$t]=DB::connection('sqlsrv')->table($t)->orderBy('id')->get()->toArray();return $out;
}
function raOperation($t,string $action):array {
    $version=$t->raBudget->fresh()->version;$key='revalidate-'.Str::uuid();
    if(in_array($action,['cancel','expire'],true)){
        $issued=raIssue($t,null,null,$version);$version=$t->raBudget->fresh()->version;$id=$issued['bonus_id'];
        if($action==='expire')$t->travelTo(Bonus::where('public_id',$id)->firstOrFail()->expires_at);
        return [fn()=>app(\App\Domains\Financial\Services\InstitutionalBecaReleaseService::class)->{$action}($t->raActor,$t->raBudget->public_id,$id,$version,'Revision de autoridad',$key),$action==='expire'?'budget.expire':'budget.release'];
    }
    if($action==='resume')$t->raBudget->update(['status'=>'SUSPENDED']);
    $data=['institution_reference'=>'fixture-revalidation','approval_reference'=>'approval-'.Str::uuid(),'payer_reference'=>'fixture-payer',
        'purpose'=>'BECA','approved_amount_cents'=>10000,'currency'=>'MXN','reason'=>'Prueba de revalidacion',
        'valid_from'=>now()->subDay()->toISOString(),'expires_at'=>now()->addMonth()->toISOString()];
    $issue=raIssuePayload($t);
    $work=fn()=>match($action){
        'approve'=>app(InstitutionalBudgetService::class)->approve($t->raActor,$data,$key),
        'adjust'=>app(InstitutionalBudgetService::class)->setApprovedAmount($t->raActor,$t->raBudget->public_id,$version,11000,'Prueba de revalidacion',$key),
        'suspend','resume'=>app(InstitutionalBudgetService::class)->setSuspended($t->raActor,$t->raBudget->public_id,$version,$action==='suspend','Prueba de revalidacion',$key),
        'issue'=>app(InstitutionalBecaIssuanceService::class)->issue($t->raActor,$t->raBudget->public_id,$version,$issue,$key),
    };
    return [$work,match($action){'issue'=>'budget.commit',default=>'budget.'.$action}];
}
function raChangingAuthority($t,string $permission,bool $unavailable=false){
    $authority=new class($permission,$unavailable) implements InstitutionalBudgetAuthorization{
        public int $calls=0;public array $depths=[];
        public function __construct(private string $permission,private bool $unavailable){}
        public function allows(string $actorId,string $action,?string $budgetId=null):bool{
            if($action!==$this->permission)return true;
            $this->depths[]=DB::connection('sqlsrv')->transactionLevel();++$this->calls;
            if($this->calls>1&&$this->unavailable)throw new RuntimeException('fixture-authority-unavailable');
            return $this->calls===1;
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class,$authority);return $authority;
}
test('institutional writer revalidates current budget permission inside its transaction before mutation or replay',function(string $action,bool $replay){
    [$work,$permission]=raOperation($this,$action);if($replay)$work();$before=raSnapshot();$authority=raChangingAuthority($this,$permission);
    expect($work)->toThrow(AuthorizationException::class);expect(raSnapshot())->toEqual($before)
        ->and($authority->calls)->toBe(2)->and($authority->depths[1])->toBeGreaterThan($authority->depths[0]);Http::assertNothingSent();
})->with(['approve','adjust','suspend','resume','issue','cancel','expire'])->with([false,true]);
test('institutional revalidation dependency failure never commits a partial budget write',function(){
    [$work,$permission]=raOperation($this,'adjust');$before=raSnapshot();raChangingAuthority($this,$permission,true);
    expect($work)->toThrow(RuntimeException::class);expect(raSnapshot())->toEqual($before);
});
test('institutional issue and cancellation recheck the separate financial action after obtaining locks',function(string $action,bool $replay){
    [$work,$permission]=raOperation($this,$action);if($replay)$work();$before=raSnapshot();
    $rights=new class($action==='issue'?'bonus.issue':'bonus.cancel') implements FinancialWebAuthorizer{
        public int $calls=0;public array $depths=[];
        public function __construct(private string $target){}
        public function allows(string $userId,string $action,?string $walletId=null):bool{
            if($action!==$this->target)return true;$this->depths[]=DB::connection('sqlsrv')->transactionLevel();return ++$this->calls===1;
        }
    };app()->instance(FinancialWebAuthorizer::class,$rights);
    expect($work)->toThrow(AuthorizationException::class);expect(raSnapshot())->toEqual($before)->and($rights->calls)->toBe(2)
        ->and($rights->depths[1])->toBeGreaterThan($rights->depths[0]);
})->with(['issue','cancel'])->with([false,true]);
