<?php

// Regresión de la validación funcional E1–E7 (fase H): con los módulos hablando por HTTP real dentro del mismo
// despliegue, Inventarios (E4) hace 4 consultas a Identidad (E1) por pantalla. El límite `throttle:30,1` se contaba por
// dirección IP del llamador —la propia aplicación, la misma para todos los usuarios y para todas las rutas de
// servicio—, así que a la octava pantalla del minuto E4 respondía 503 a todo el campus.

use App\Models\ServiceClient;
use App\Models\User;
use App\Services\OAuthTokenService;
use Database\Seeders\AuthorizationFoundationSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
    (require database_path('migrations/2026_10_06_000100_create_business_owner_provision_indexes.php'))->up();
    $this->seed(AuthorizationFoundationSeeder::class);
    $this->subject = (string) User::factory()->create()->getKey();
});

/** Token emitido sin pasar por HTTP, para no consumir ningún contador. */
function rateLimitServiceHeaders(string $clientId): array
{
    $scopes = ['identity:assignments:read', 'identity:authorization:check', 'identity:business-owner:provision'];
    ServiceClient::firstOrCreate(['client_id' => $clientId], ['name' => 'Rate limit testing',
        'secret_hash' => Hash::make('test-secret'), 'scopes' => $scopes, 'active' => true]);

    return ['Authorization' => 'Bearer '.app(OAuthTokenService::class)->issue($clientId, 'test-secret', $scopes)['access_token']];
}

function rateLimitAssignments($test, array $headers, string $subject)
{
    return $test->getJson('/api/v1/identity/assignments?'.http_build_query([
        'subject_id' => $subject, 'scope_type' => 'business', 'scope_id' => 'business-A']), $headers);
}

function rateLimitCheck($test, array $headers, string $subject)
{
    return $test->postJson('/api/v1/identity/authorization/check', ['subject_id' => $subject,
        'scope_type' => 'business', 'scope_id' => 'business-A', 'capability' => 'business.manage'], $headers);
}

test('a user can browse eight inventory screens within a minute without exhausting identity', function () {
    $headers = rateLimitServiceHeaders('svc_inventory');
    foreach (range(1, 8) as $screen) {
        rateLimitAssignments($this, $headers, $this->subject)->assertOk();
        foreach (range(1, 3) as $capability) {
            rateLimitCheck($this, $headers, $this->subject)->assertOk();
        }
    }
});

test('the identity read budget belongs to each service client and subject, not to the caller address', function () {
    $inventory = rateLimitServiceHeaders('svc_inventory');
    foreach (range(1, 120) as $call) {
        rateLimitCheck($this, $inventory, $this->subject)->assertOk();
    }
    rateLimitCheck($this, $inventory, $this->subject)->assertStatus(429)->assertHeader('Retry-After');
    rateLimitAssignments($this, $inventory, $this->subject)->assertStatus(429);

    $other = (string) User::factory()->create()->getKey();
    rateLimitCheck($this, $inventory, $other)->assertOk();
    rateLimitCheck($this, rateLimitServiceHeaders('svc_marketplace'), $this->subject)->assertOk();
});

test('other service endpoints called from the same address do not consume the identity budget', function () {
    foreach (range(1, 30) as $call) {
        $this->postJson('/api/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => 'unknown',
            'client_secret' => 'wrong', 'scope' => 'identity:assignments:read'])->assertStatus(401);
    }
    rateLimitCheck($this, rateLimitServiceHeaders('svc_inventory'), $this->subject)->assertOk();
});

test('owner provisioning keeps a strict budget per service client', function () {
    config(['oauth.business_owner_clients' => ['svc_marketplace', 'svc_other']]);
    $marketplace = rateLimitServiceHeaders('svc_marketplace');
    foreach (range(1, 30) as $call) {
        $this->postJson('/api/v1/identity/business-owner/provision', [], $marketplace)->assertStatus(422);
    }
    $this->postJson('/api/v1/identity/business-owner/provision', [], $marketplace)->assertStatus(429);
    $this->postJson('/api/v1/identity/business-owner/provision', [], rateLimitServiceHeaders('svc_other'))->assertStatus(422);
});
