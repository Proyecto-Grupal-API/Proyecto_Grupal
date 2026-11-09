<?php

namespace Tests\Unit;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserSession;
use App\Services\EffectiveRoleAssignments;
use App\Services\IdentityService;
use App\Services\PilotOperationAuthorization;
use App\Services\RecentTwoFactor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider as Provider;
use Laravel\Fortify\TwoFactorAuthenticationProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PragmaRX\Google2FA\Google2FA;

/** No application boot, database, Laravel TestCase, fixtures or external services. */
final class PilotIdentityExtensionsTest extends TestCase
{
    private Request $request;
    private User $user;
    private UserSession $session;
    private Encrypter $crypt;
    private Google2FA $engine;
    private Container $previous;
    private const WALLET = '12345678-1234-1234-1234-123456789abc';

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $container->instance('config', new Config);
        $translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en');
        $container->instance('validator', new \Illuminate\Validation\Factory($translator, $container));
        $container->instance('encrypter', $this->crypt = new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
        Carbon::setTestNow('2026-11-08 12:00:00 UTC');
        $this->engine = new Google2FA;
        $this->user = new User;
        $this->user->setRawAttributes(['_id' => '111111111111111111111111',
            'two_factor_secret' => $this->crypt->encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => '2026-11-01 00:00:00']);
        $this->user->exists = true;
        $this->session = new UserSession;
        $this->session->setRawAttributes(['_id' => '222222222222222222222222',
            'user_id' => (string) $this->user->getKey(), 'revoked_at' => null]);
        $this->session->exists = true;
        $this->request = Request::create('/seguridad/confirmar-segundo-factor', 'POST');
        $store = new Store('test', new ArraySessionHandler(10));
        $store->start();
        $store->put('cd_session_id', (string) $this->session->getKey());
        $this->request->setLaravelSession($store);
        $this->request->setUserResolver(fn () => $this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Facade::clearResolvedInstances();
        Container::setInstance($this->previous);
        Facade::setFacadeApplication($this->previous);
    }

    private function factor(?Provider $provider = null): MemoryRecentFactor
    {
        $provider ??= new TwoFactorAuthenticationProvider($this->engine, new Repository(new ArrayStore));
        return new MemoryRecentFactor(new IdentityService, $provider, $this->session);
    }

    private function confirm(MemoryRecentFactor $factor): void
    {
        $factor->confirm($this->request, $this->engine->getCurrentOtp('JBSWY3DPEHPK3PXP'));
    }

    public function test_real_fortify_totp_confirms_without_touching_password_evidence_or_leaking_codes(): void
    {
        $factor = $this->factor();
        $this->request->session()->put('reauth_at', 'untouched');
        $this->confirm($factor);
        self::assertTrue($factor->isRecent($this->request));
        self::assertSame('untouched', $this->request->session()->get('reauth_at'));
        self::assertSame([true], $factor->audits);
        self::assertArrayNotHasKey('recent_two_factor', $this->session->getFillable() ? array_flip($this->session->getFillable()) : []);
        self::assertContains('recent_two_factor', $this->session->getHidden());
        self::assertSame(['user_id', 'identity_session_id', 'laravel_session_hash', 'factor_hash', 'verified_at'], array_keys($this->session->recent_two_factor->getArrayCopy()));
    }

    public function test_invalid_totp_never_records_evidence(): void
    {
        $factor = $this->factor();
        $this->engine->setWindow(0);
        $wrong = $this->engine->getCurrentOtp('JBSWY3DPEHPK3PXP') === '000000' ? '111111' : '000000';
        try { $factor->confirm($this->request, $wrong); self::fail('accepted'); }
        catch (ValidationException) { self::assertNull($this->session->recent_two_factor); }
        self::assertSame([false], $factor->audits);
    }

    public static function ageCases(): iterable
    {
        yield 'fresh' => [0, true]; yield 'just before expiry' => [299, true];
        yield 'exact expiry' => [300, false]; yield 'expired' => [301, false]; yield 'future evidence' => [-1, false];
    }

    #[DataProvider('ageCases')]
    public function test_window_is_five_minutes_with_exclusive_expiry(int $age, bool $expected): void
    {
        $factor = $this->factor(); $this->confirm($factor);
        $v = $this->session->recent_two_factor->getArrayCopy(); $v['verified_at'] -= $age;
        $this->session->recent_two_factor = $v;
        self::assertSame($expected, $factor->isRecent($this->request));
    }

    public static function invalidBindings(): iterable
    {
        foreach (['revoked', 'different-user', 'different-e1-session', 'new-laravel-session', 'no-factor',
            'unconfirmed-factor', 'changed-factor', 'reconfirmed-factor', 'missing-evidence', 'invalid-evidence', 'browser-evidence'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidBindings')]
    public function test_rejects_unbound_or_unusable_evidence(string $case): void
    {
        $factor = $this->factor(); $this->confirm($factor);
        match ($case) {
            'revoked' => $this->session->revoked_at = Carbon::now(),
            'different-user' => $this->user->setRawAttributes([...$this->user->getAttributes(), '_id' => '333333333333333333333333']),
            'different-e1-session' => $this->request->session()->put('cd_session_id', '444444444444444444444444'),
            'new-laravel-session' => $this->request->session()->regenerate(),
            'no-factor' => $this->user->two_factor_secret = null,
            'unconfirmed-factor' => $this->user->two_factor_confirmed_at = null,
            'reconfirmed-factor' => $this->user->two_factor_confirmed_at = '2026-11-08 11:59:00',
            'changed-factor' => $this->user->two_factor_secret = $this->crypt->encrypt('ABCDEFGHIJKLMNOP'),
            'missing-evidence' => $this->session->recent_two_factor = null,
            'invalid-evidence' => $this->session->recent_two_factor = ['verified_at' => 'now'],
            'browser-evidence' => $this->session->recent_two_factor = null,
        };
        $this->request->merge(['recent_two_factor' => ['verified_at' => now()->timestamp], 'reauth_at' => now()]);
        self::assertFalse($factor->isRecent($this->request));
    }

    public function test_replay_is_rejected_by_real_fortify_provider(): void
    {
        $factor = $this->factor(); $this->confirm($factor);
        $this->expectException(ValidationException::class);
        $this->confirm($factor);
    }

    public function test_damaged_or_unexpected_encrypted_factor_cannot_record_evidence(): void
    {
        foreach (['damaged-ciphertext', $this->crypt->encrypt(['not-a-secret']), $this->crypt->encrypt('not-base32')] as $secret) {
            $this->user->two_factor_secret = $secret;
            $factor = $this->factor();
            try { $factor->confirm($this->request, '123456'); self::fail('invalid secret accepted'); }
            catch (ValidationException) { self::assertNull($this->session->recent_two_factor); }
        }
    }

    public function test_record_failure_and_revocation_during_challenge_deny_confirmation(): void
    {
        $factor = $this->factor(); $factor->writeAllowed = false;
        $this->expectException(AuthorizationException::class);
        $this->confirm($factor);
    }

    public function test_revocation_during_totp_never_persists(): void
    {
        $provider = $this->createMock(Provider::class);
        $provider->method('verify')->willReturnCallback(function () { $this->session->revoked_at = now(); return true; });
        $factor = $this->factor($provider);
        try { $factor->confirm($this->request, '123456'); self::fail('accepted'); }
        catch (AuthorizationException) { self::assertNull($this->session->recent_two_factor); }
    }

    public static function refundCases(): iterable
    {
        yield 'review approve' => ['financial_refund_reviewer', 'approve', true, true];
        yield 'review reject without step up' => ['financial_refund_reviewer', 'reject', false, true];
        yield 'approve without step up' => ['financial_refund_reviewer', 'approve', false, false];
        yield 'review cannot execute' => ['financial_refund_reviewer', 'execute', true, false];
        yield 'execution separate' => ['financial_refund_executor', 'execute', true, true];
        yield 'executor cannot review' => ['financial_refund_executor', 'approve', true, false];
        yield 'execution without step up' => ['financial_refund_executor', 'execute', false, false];
        yield 'admin is not reviewer' => ['admin', 'reject', true, false];
    }

    private function policy(MemoryRecentFactor $factor, string $role, ?string $type = 'wallet', ?string $id = self::WALLET): MemoryPilotPolicy
    {
        $policy = new MemoryPilotPolicy(new EffectiveRoleAssignments, $factor);
        $policy->assignment = ['role' => $role, 'type' => $type, 'id' => $id];
        return $policy;
    }

    #[DataProvider('refundCases')]
    public function test_refund_authority_and_freshness_are_independent(string $role, string $operation, bool $fresh, bool $expected): void
    {
        $factor = $this->factor(); if ($fresh) { $this->confirm($factor); }
        self::assertSame($expected, $this->policy($factor, $role)->allowsRefund($this->request, self::WALLET, $operation));
    }

    public function test_wallet_is_exact_and_global_or_business_grants_cannot_substitute(): void
    {
        $factor = $this->factor(); $this->confirm($factor);
        self::assertFalse($this->policy($factor, 'financial_refund_reviewer')->allowsRefund($this->request, '87654321-1234-1234-1234-123456789abc', 'approve'));
        self::assertFalse($this->policy($factor, 'financial_refund_reviewer', null, null)->allowsRefund($this->request, self::WALLET, 'approve'));
        self::assertFalse($this->policy($factor, 'financial_refund_reviewer', 'business')->allowsRefund($this->request, self::WALLET, 'approve'));
        self::assertFalse($this->policy($factor, 'financial_refund_reviewer')->allowsRefund($this->request, 'not-a-wallet', 'approve'));
        self::assertFalse($this->policy($factor, 'financial_refund_reviewer')->allowsRefund($this->request, self::WALLET, 'issue'));
    }

    public static function lifecycleCases(): iterable
    {
        yield 'active' => ['active', true, null, null, null, true];
        yield 'missing current' => ['active', false, null, null, null, false];
        yield 'suspended' => ['suspended', true, null, null, null, false];
        yield 'revoked' => ['revoked', false, null, null, '-1 second', false];
        yield 'revoked timestamp' => ['active', true, null, null, '-1 second', false];
        yield 'future' => ['active', true, '+1 second', null, null, false];
        yield 'start inclusive' => ['active', true, 'now', null, null, true];
        yield 'end exclusive' => ['active', true, null, 'now', null, false];
        yield 'expired' => ['active', true, null, '-1 second', null, false];
    }

    #[DataProvider('lifecycleCases')]
    public function test_existing_effective_lifecycle_filters_for_both_pilots(string $status, bool $current, ?string $start, ?string $end, ?string $revoked, bool $expected): void
    {
        $factor = $this->factor(); $this->confirm($factor);
        foreach ([['financial_refund_reviewer', 'wallet', self::WALLET], ['operador_servicios', null, null]] as [$role, $type, $id]) {
            $policy = $this->policy($factor, $role, $type, $id);
            $policy->lifecycle = ['status' => $status, 'is_current' => $current,
                'starts_at' => $start ? now()->modify($start) : null, 'ends_at' => $end ? now()->modify($end) : null,
                'revoked_at' => $revoked ? now()->modify($revoked) : null];
            self::assertSame($expected, $role === 'operador_servicios'
                ? $policy->allowsRentalDamage($this->request, 'rental-fixture', 'hold-fixture', 150)
                : $policy->allowsRefund($this->request, self::WALLET, 'approve'));
        }
    }

    public function test_damage_charge_requires_exact_operation_and_fresh_factor_and_audits_binding(): void
    {
        $factor = $this->factor(); $p = $this->policy($factor, 'operador_servicios', null, null);
        self::assertFalse($p->allowsRentalDamage($this->request, 'rental-fixture', 'hold-fixture', 150));
        $this->confirm($factor);
        self::assertTrue($p->allowsRentalDamage($this->request, 'rental-fixture', 'hold-fixture', 150));
        self::assertSame(['operation' => 'rentals.deposit.settle', 'rental_id' => 'rental-fixture', 'hold_id' => 'hold-fixture', 'damage_charge_cents' => 150], $p->audits[1][0]);
        self::assertFalse($p->allowsRentalDamage($this->request, 'rental-fixture', 'hold-fixture', 150, 'refund.execute'));
        foreach (['admin', 'cashier', 'financial_refund_executor', 'unknown'] as $role) {
            self::assertFalse($this->policy($factor, $role, null, null)->allowsRentalDamage($this->request, 'rental-fixture', 'hold-fixture', 150));
        }
    }

    public function test_zero_release_does_not_invoke_step_up_or_grant_financial_permission(): void
    {
        $p = $this->policy($this->factor(), 'operador_servicios', null, null);
        // False means this positive-charge authority cannot authorize a release; existing E5 counter policy applies.
        self::assertFalse($p->allowsRentalDamage($this->request, 'rental-fixture', 'hold-fixture', 0));
        self::assertSame([], $p->audits);
        self::assertFalse($p->allowsRentalDamage($this->request, '', 'hold-fixture', 100));
    }

    public function test_unconfigured_and_unconfirmed_factors_cannot_confirm(): void
    {
        $provider = $this->createMock(Provider::class);
        $provider->expects(self::never())->method('verify');
        $factor = $this->factor($provider);
        $this->user->two_factor_confirmed_at = null;
        try { $factor->confirm($this->request, '123456'); self::fail('unconfirmed accepted'); }
        catch (AuthorizationException) { self::assertNull($this->session->recent_two_factor); }
        $this->user->two_factor_confirmed_at = '2026-11-01 00:00:00';
        $this->user->two_factor_secret = null;
        try { $factor->confirm($this->request, '123456'); self::fail('missing accepted'); }
        catch (AuthorizationException) { self::assertNull($this->session->recent_two_factor); }
    }

    public function test_recovery_codes_are_rejected_without_flashing_inputs(): void
    {
        $response = $this->createMock(\Illuminate\Contracts\Routing\ResponseFactory::class);
        $response->method('json')->willReturnCallback(fn ($data, $status) => new \Illuminate\Http\JsonResponse($data, $status));
        Container::getInstance()->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, $response);
        $this->request->merge(['code' => '123456', 'recovery_code' => 'synthetic-recovery']);
        $factor = $this->createMock(RecentTwoFactor::class);
        $factor->expects(self::never())->method('confirm');
        $result = (new \App\Http\Controllers\Auth\RecentTwoFactorController)($this->request, $factor);
        self::assertSame(422, $result->getStatusCode());
        self::assertFalse($this->request->session()->has('_old_input'));
    }

    public function test_oauth_or_non_web_actor_cannot_replace_the_authenticated_human(): void
    {
        $this->request->setUserResolver(fn ($guard = null) => $guard === 'web' ? null : $this->user);
        self::assertFalse($this->factor()->hasActiveSession($this->request));
        self::assertFalse($this->policy($this->factor(), 'financial_refund_reviewer')->allowsRefund($this->request, self::WALLET, 'reject'));
    }

    public function test_real_session_gate_rejects_absence_before_building_a_driver(): void
    {
        $identity = $this->createMock(IdentityService::class);
        $identity->expects(self::once())->method('activeFinancialSession')->willReturn(null);
        $factor = new RecentTwoFactor($identity, $this->createMock(Provider::class));
        self::assertFalse($factor->hasActiveSession(Request::create('/')));
        self::assertFalse($factor->hasActiveSession($this->request));
    }

    public function test_damage_amount_is_not_coerced_from_browser_strings_or_floats(): void
    {
        $factor = $this->factor(); $this->confirm($factor);
        $policy = $this->policy($factor, 'operador_servicios', null, null);
        foreach (['150', 150.5, -1, true, null] as $amount) {
            self::assertFalse($policy->allowsRentalDamage($this->request, 'rental-fixture', 'hold-fixture', $amount));
        }
    }

    public function test_wallet_scope_rejects_legacy_roles_before_any_catalog_query(): void
    {
        $record = new RoleAssignment;
        $record->setRawAttributes(['role_key' => 'admin', 'scope_type' => 'wallet', 'scope_id' => self::WALLET]);
        $this->expectException(ValidationException::class);
        (new \ReflectionMethod(RoleAssignment::class, 'validateContext'))->invoke($record);
    }

    public function test_production_cannot_activate_or_transition_pilot_assignments(): void
    {
        Container::setInstance(new class extends Container { public function environment(...$environments): bool { return false; } });
        foreach (['active', 'suspended', 'revoked'] as $status) {
            $record = new RoleAssignment;
            $record->setRawAttributes(['role_key' => 'operador_servicios']);
            try { $record->transitionTo($status); self::fail('production transition accepted'); }
            catch (AuthorizationException) { self::assertTrue(true); }
        }
    }

    public function test_production_grants_block_before_any_database_access_and_legacy_catalogs_stay_closed(): void
    {
        $container = new class extends Container { public function environment(...$environments): bool { return false; } };
        Container::setInstance($container);
        self::assertNotContains('operador_servicios', Role::VALID_ROLES);
        self::assertNotContains('financial_refund_reviewer', Role::FINANCIAL_ROLES);
        self::assertSame(['refunds.review'], RolePermission::approvedPermissionsForRole('financial_refund_reviewer'));
        self::assertSame(['refunds.execute'], RolePermission::approvedPermissionsForRole('financial_refund_executor'));
        $this->expectException(AuthorizationException::class);
        RoleAssignment::assertPilotGrantEnvironment();
    }
}

/** Explicit storage doubles: tests above exercise the production TOTP provider/binding and policy. */
class MemoryRecentFactor extends RecentTwoFactor
{
    public array $audits = [];
    public bool $writeAllowed = true;
    public function __construct(IdentityService $identity, Provider $provider, private UserSession $document) { parent::__construct($identity, $provider); }
    protected function activeSession(Request $r): ?UserSession
    {
        return $r->user('web') instanceof User && $r->hasSession() && ! $this->document->isRevoked()
            && (string) $r->user('web')->getKey() === (string) $this->document->user_id
            && $r->session()->get('cd_session_id') === (string) $this->document->getKey() ? $this->document : null;
    }
    protected function record(UserSession $s, array $e): bool { if (! $this->writeAllowed) { return false; } $s->recent_two_factor = $e; return true; }
    protected function audit(Request $r, UserSession $s, bool $ok): void { $this->audits[] = $ok; }
}

class MemoryPilotPolicy extends PilotOperationAuthorization
{
    public array $assignment = [];
    public array $audits = [];
    public array $lifecycle = ['status' => 'active', 'is_current' => true, 'starts_at' => null, 'ends_at' => null, 'revoked_at' => null];
    protected function hasCapability(User $u, string $role, ?string $type, ?string $id): bool
    {
        if ($this->assignment !== ['role' => $role, 'type' => $type, 'id' => $id]) { return false; }
        $query = new MemoryLifecycleQuery($this->lifecycle);
        return (new EffectiveRoleAssignments)->activeCurrent($query)->whereNull('revoked_at')->exists();
    }
    protected function audit(Request $r, array $op, bool $allowed): void { $this->audits[] = [$op, $allowed]; }
}

/** Evaluates the unchanged E1 lifecycle query, including its nested AND/OR clauses. */
class MemoryLifecycleQuery
{
    private array $terms = [];
    public function __construct(private array $row) {}
    public function where($field, $op = null, $value = null): self
    {
        if ($field instanceof \Closure) { $q = new self($this->row); $field($q); $this->terms[] = ['and', $q->exists()]; }
        else { if (func_num_args() === 2) { $value = $op; $op = '='; } $actual = $this->row[$field] ?? null;
            $this->terms[] = ['and', match ($op) { '=' => $actual === $value, '<=' => $actual !== null && $actual <= $value, '>' => $actual !== null && $actual > $value }]; }
        return $this;
    }
    public function whereNull($f): self { $this->terms[] = ['and', ($this->row[$f] ?? null) === null]; return $this; }
    public function orWhere(...$args): self { $this->where(...$args); $this->terms[array_key_last($this->terms)][0] = 'or'; return $this; }
    public function exists(): bool { $r = true; foreach ($this->terms as [$op, $v]) { $r = $op === 'or' ? $r || $v : $r && $v; } return $r; }
}
