<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SecuritySessionFlowTest extends TestCase
{
    private function signIn(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/seguridad/dispositivos')->assertOk();

        return $user;
    }

    public function test_authentication_has_its_own_protected_view(): void
    {
        $this->get('/profile/authentication')->assertRedirect('/login');
        $this->signIn();
        $this->get('/profile/authentication')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Profile/Authentication')->where('twoFactorEnabled', false)->missing('auth.user.two_factor_secret'));
    }

    public function test_revoking_the_current_session_returns_json_and_logs_out(): void
    {
        $user = $this->signIn();
        $id = session('cd_session_id');
        $this->withSession(['reauth_at' => now()])->postJson('/seguridad/sesiones/'.$id.'/revocar')
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('redirect', route('login'));
        $this->assertGuest();
        $this->assertNotNull(UserSession::find($id)->revoked_at);
    }

    public function test_deleting_the_current_device_does_not_recreate_it_on_a_redirect(): void
    {
        $user = $this->signIn();
        $session = UserSession::find(session('cd_session_id'));
        $this->withSession(['reauth_at' => now()])->deleteJson('/seguridad/dispositivos/'.$session->device_id)
            ->assertOk()->assertJsonPath('redirect', route('login'));
        $this->assertGuest();
        $this->assertNull(Device::find($session->device_id));
        $this->get('/seguridad/dispositivos')->assertRedirect('/login');
    }

    public function test_remote_revocation_keeps_the_current_session_active(): void
    {
        $user = $this->signIn();
        $current = session('cd_session_id');
        $remote = UserSession::create(['user_id' => (string) $user->id, 'device_id' => UserSession::find($current)->device_id]);
        $this->withSession(['reauth_at' => now()])->postJson('/seguridad/sesiones/'.$remote->id.'/revocar')
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('redirect', null);
        $this->assertAuthenticated();
        $this->assertNull(UserSession::find($current)->revoked_at);
        $this->assertNotNull($remote->fresh()->revoked_at);
    }

    public function test_expired_password_confirmation_requires_reauthentication(): void
    {
        $this->signIn();
        $id = session('cd_session_id');
        $this->withSession(['reauth_at' => now()->subMinutes(10)])->postJson('/seguridad/sesiones/'.$id.'/revocar')->assertStatus(428);
        $this->assertNull(UserSession::find($id)->revoked_at);
    }

    public function test_cannot_close_someone_elses_session(): void
    {
        $this->signIn();
        $other = UserSession::create(['user_id' => (string) User::factory()->create()->id]);
        $this->withSession(['reauth_at' => now()])->postJson('/seguridad/sesiones/'.$other->id.'/revocar')->assertNotFound();
        $this->assertNull($other->fresh()->revoked_at);
    }

    public function test_remote_revocation_returns_401_to_ajax_requests(): void
    {
        $this->signIn();
        UserSession::find(session('cd_session_id'))->update(['revoked_at' => now()]);
        $this->getJson('/seguridad/latido')->assertUnauthorized()->assertJsonPath('redirect', route('login'));
        $this->assertGuest();
    }

    public function test_file_cache_for_tests_is_separate_from_the_running_app(): void
    {
        $this->assertSame(storage_path('framework/cache/testing'), config('cache.stores.file.path'));
        $this->assertTrue(Cache::store('file')->add('security-cache-probe', 'ok', 5));
        $this->assertSame('ok', Cache::store('file')->pull('security-cache-probe'));
    }
}
