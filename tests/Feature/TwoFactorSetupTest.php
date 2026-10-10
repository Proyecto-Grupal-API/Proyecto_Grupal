<?php
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Inertia\Testing\AssertableInertia as Assert;
function twoFactorSetupUser(): User {
 $u=User::factory()->create();$u->forceFill(['email_verified_at'=>now(),'account_activation_pending'=>false,'must_change_password'=>false])->save();return $u;
}
beforeEach(function(){
 if(!app()->environment('testing') || !str_ends_with((string)config('database.connections.mongodb.database'),'testing'))throw new RuntimeException('Dedicated testing environment required.');
 $this->withoutMiddleware([PreventRequestForgery::class,\App\Http\Middleware\EnsureSessionIsActive::class,\App\Http\Middleware\TrackDeviceSession::class]);$this->withoutVite();
});
test('two factor setup routes require authentication and password confirmation',function(){
 $this->postJson('/user/two-factor-authentication')->assertUnauthorized();
 $this->actingAs(twoFactorSetupUser())->postJson('/user/two-factor-authentication')->assertStatus(423);
});
test('two factor setup distinguishes a provisioned secret from confirmed authentication',function(){
 $u=twoFactorSetupUser();$this->actingAs($u)->withSession(['auth.password_confirmed_at'=>time()])->postJson('/user/two-factor-authentication')->assertSuccessful();
 $fresh=$u->fresh();expect($fresh->two_factor_secret)->not->toBeNull()->and($fresh->two_factor_confirmed_at)->toBeNull();
 $this->get('/roles')->assertOk()->assertInertia(fn(Assert $page)=>$page->component('Roles/Index')->where('twoFactorEnabled',true)->where('twoFactorConfirmed',false));
 $this->getJson('/user/two-factor-qr-code')->assertOk()->assertJsonStructure(['svg','url']);
 $this->getJson('/user/two-factor-recovery-codes')->assertOk();
});
test('two factor confirmation rejects an invalid code and accepts a real generated TOTP',function(){
 $u=twoFactorSetupUser();$this->actingAs($u)->withSession(['auth.password_confirmed_at'=>time()])->postJson('/user/two-factor-authentication')->assertSuccessful();
 $this->postJson('/user/confirmed-two-factor-authentication',['code'=>'invalid'])->assertUnprocessable();
 expect($u->fresh()->two_factor_confirmed_at)->toBeNull();
 $secret=decrypt($u->fresh()->two_factor_secret);$totp=(new \PragmaRX\Google2FA\Google2FA())->getCurrentOtp($secret);
 $this->postJson('/user/confirmed-two-factor-authentication',['code'=>$totp])->assertSuccessful();
 expect($u->fresh()->two_factor_confirmed_at)->not->toBeNull();
 $this->get('/roles')->assertOk()->assertInertia(fn(Assert $page)=>$page->where('twoFactorConfirmed',true));
 $this->deleteJson('/user/two-factor-authentication')->assertSuccessful();
 expect($u->fresh()->two_factor_secret)->toBeNull()->and($u->fresh()->two_factor_confirmed_at)->toBeNull();
});

test('confirmed second factor requires a real challenge on the next login',function(){
 $u=twoFactorSetupUser();$google=new \PragmaRX\Google2FA\Google2FA();$secret=$google->generateSecretKey();
 $u->forceFill(['two_factor_secret'=>encrypt($secret),'two_factor_confirmed_at'=>now(),'two_factor_recovery_codes'=>encrypt(json_encode(['fixture-recovery-code']))])->save();
 $this->postJson('/login',['email'=>$u->email,'password'=>'password'])->assertRedirect(route('two-factor.login'))->assertSessionHas('login.id',(string)$u->getKey());
 $this->assertGuest();
 $this->postJson('/two-factor-challenge',['code'=>$google->getCurrentOtp($secret)])->assertRedirect(route('dashboard', absolute: false));
 $this->assertAuthenticatedAs($u);
});
