<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserSession;
use App\Services\PilotOperationAuthorization;
use App\Services\RecentTwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Fortify;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\E1TestTarget;
use Tests\TestCase;

/** Persistence requires the existing attested E1 testing profile. */
class PilotOperationAuthorizationTest extends TestCase
{
    private const WALLET = '12345678-1234-1234-1234-123456789abc';

    private function requestForSyntheticUser(): Request
    {
        E1TestTarget::assertApplication();
        foreach (Role::PILOT_ROLE_SCOPES as $role => $scope) {
            Role::create(['name' => $role, 'display_name' => $role]);
            $key = RolePermission::PILOT_MAPPINGS[$role][0];
            Permission::create(['key' => $key, 'display_name' => $key,
                'domain' => Permission::TECHNICAL_CATALOG[$key][1], 'active' => true]);
            RolePermission::create(['role_key' => $role, 'permission_key' => $key]);
        }
        $user = User::factory()->create(['account_activation_pending' => false, 'must_change_password' => false,
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($user, 'web');
        $session = UserSession::create(['user_id' => (string) $user->getKey(), 'started_at' => now()]);
        $request = Request::create('/seguridad/confirmar-segundo-factor', 'POST');
        $store = app('session')->driver(); $store->start();
        $store->put('cd_session_id', (string) $session->getKey());
        $request->setLaravelSession($store);
        $request->setUserResolver(fn ($guard = null) => Auth::guard($guard ?? 'web')->user());

        return $request;
    }

    private function assign(Request $r, string $role, ?string $wallet = null): RoleAssignment
    {
        E1TestTarget::assertApplication();
        return RoleAssignment::create(['user_id' => (string) $r->user('web')->getKey(), 'role_key' => $role,
            'scope_type' => Role::PILOT_ROLE_SCOPES[$role], 'scope_id' => $wallet, 'status' => 'active',
            'origin' => 'synthetic_e1_e2_e5_pilot', 'reason' => 'Synthetic isolated pilot fixture']);
    }

    public function test_persisted_totp_is_bound_to_the_real_session_and_remote_revocation(): void
    {
        $r = $this->requestForSyntheticUser(); $factor = app(RecentTwoFactor::class);
        self::assertFalse($factor->isRecent($r));
        $factor->confirm($r, (new Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP'));
        self::assertTrue($factor->isRecent($r));
        $document = UserSession::findOrFail($r->session()->get('cd_session_id'));
        app(\App\Services\IdentityService::class)->revokeSession($r->user('web'), $document, 'synthetic_test');
        self::assertFalse($factor->isRecent($r));
        self::assertNull($document->fresh()->recent_two_factor);
    }

    public static function ineffectiveAssignments(): iterable
    {
        yield 'missing' => ['missing']; yield 'suspended' => ['suspended']; yield 'revoked' => ['revoked'];
        yield 'future' => ['future']; yield 'expired' => ['expired'];
    }

    #[DataProvider('ineffectiveAssignments')]
    public function test_persisted_ineffective_review_assignments_never_authorize(string $state): void
    {
        $r = $this->requestForSyntheticUser();
        if ($state !== 'missing') {
            $a = $this->assign($r, 'financial_refund_reviewer', self::WALLET);
            if (in_array($state, ['suspended', 'revoked'], true)) { $a->transitionTo($state); }
            else {
                // Raw fixture mutation only, after the exact target guard; production writers remain blocked.
                E1TestTarget::assertApplication();
                RoleAssignment::whereKey($a->getKey())->update($state === 'future'
                    ? ['starts_at' => now()->addMinute()] : ['ends_at' => now()]);
            }
        }
        self::assertFalse(app(PilotOperationAuthorization::class)->allowsRefund($r, self::WALLET, 'reject'));
    }

    public function test_real_wallet_review_does_not_authorize_another_wallet_or_execution(): void
    {
        $r = $this->requestForSyntheticUser(); $this->assign($r, 'financial_refund_reviewer', self::WALLET);
        $policy = app(PilotOperationAuthorization::class);
        self::assertTrue($policy->allowsRefund($r, self::WALLET, 'reject'));
        self::assertFalse($policy->allowsRefund($r, '87654321-1234-1234-1234-123456789abc', 'reject'));
        self::assertFalse($policy->allowsRefund($r, self::WALLET, 'approve'));
        app(RecentTwoFactor::class)->confirm($r, (new Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP'));
        self::assertTrue($policy->allowsRefund($r, self::WALLET, 'approve'));
        self::assertFalse($policy->allowsRefund($r, self::WALLET, 'execute'));
    }

    public function test_real_global_operator_is_limited_to_positive_rental_damage_with_step_up(): void
    {
        $r = $this->requestForSyntheticUser(); $this->assign($r, 'operador_servicios');
        $policy = app(PilotOperationAuthorization::class);
        self::assertFalse($policy->allowsRentalDamage($r, 'rental-synthetic', 'hold-synthetic', 100));
        app(RecentTwoFactor::class)->confirm($r, (new Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP'));
        self::assertTrue($policy->allowsRentalDamage($r, 'rental-synthetic', 'hold-synthetic', 100));
        self::assertFalse($policy->allowsRentalDamage($r, 'rental-synthetic', 'hold-synthetic', 100, 'wallet.charge'));
        self::assertFalse($policy->allowsRefund($r, self::WALLET, 'execute'));
    }
    public function test_persisted_evidence_expires_at_300_seconds_and_cannot_cross_a_laravel_session(): void
    {
        $r = $this->requestForSyntheticUser(); $factor = app(RecentTwoFactor::class);
        $factor->confirm($r, (new Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP'));
        try {
            $this->travel(299)->seconds();
            self::assertTrue($factor->isRecent($r));
            $this->travel(1)->seconds();
            self::assertFalse($factor->isRecent($r));
        } finally { $this->travelBack(); }
        self::assertTrue($factor->isRecent($r));
        $r->session()->regenerate();
        self::assertFalse($factor->isRecent($r));
    }

    public function test_independent_wallet_executor_requires_recent_totp_and_cannot_review(): void
    {
        $r = $this->requestForSyntheticUser(); $this->assign($r, 'financial_refund_executor', self::WALLET);
        $policy = app(PilotOperationAuthorization::class);
        self::assertFalse($policy->allowsRefund($r, self::WALLET, 'execute'));
        app(RecentTwoFactor::class)->confirm($r, (new Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP'));
        self::assertTrue($policy->allowsRefund($r, self::WALLET, 'execute'));
        self::assertFalse($policy->allowsRefund($r, self::WALLET, 'reject'));
    }

    public function test_real_laravel_environment_blocks_unapproved_pilot_grants_and_transitions(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        try {
            try { RoleAssignment::assertPilotGrantEnvironment(); self::fail('Production grant gate accepted.'); }
            catch (\Illuminate\Auth\Access\AuthorizationException) { self::assertTrue(true); }
            $assignment = new RoleAssignment;
            $assignment->role_key = 'operador_servicios';
            try { $assignment->transitionTo('active'); self::fail('Production activation accepted.'); }
            catch (\Illuminate\Auth\Access\AuthorizationException) { self::assertTrue(true); }
        } finally { $this->app->detectEnvironment(fn () => 'testing'); }
    }

    public function test_authenticated_http_confirmation_persists_totp_evidence(): void
    {
        $r = $this->requestForSyntheticUser();
        $this->withSession(['cd_session_id' => $r->session()->get('cd_session_id')])
            ->postJson('/seguridad/confirmar-segundo-factor', [
                'code' => (new Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP'),
            ])->assertOk()->assertJson(['ok' => true, 'valid_seconds' => 300]);
        $document = UserSession::findOrFail($r->session()->get('cd_session_id'));
        self::assertNotNull($document->recent_two_factor);
    }

}
