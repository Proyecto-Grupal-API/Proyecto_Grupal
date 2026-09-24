<?php

use App\Events\StudentConsentChanged;
use App\Models\EventOutbox;
use App\Models\ServiceClient;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

it('issues and accepts an OAuth service token', function () {
    $client = ServiceClient::create([
        'name' => 'Payments service',
        'client_id' => 'svc_payments',
        'secret_hash' => Hash::make('secret'),
        'scopes' => ['students:read'],
        'active' => true,
    ]);

    $tokenResponse = $this->postJson('/api/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->client_id,
        'client_secret' => 'secret',
        'scope' => 'students:read',
    ]);

    $tokenResponse->assertOk()->assertJsonPath('token_type', 'Bearer');
    $token = $tokenResponse->json('access_token');
    $student = User::factory()->create();
    $profile = StudentProfile::create([
        'user_id' => (string) $student->getKey(),
        'enrollment_number' => 'INT-001',
        'academic_status' => 'active',
    ]);

    expect((string) $profile->getKey())->not->toBe((string) $student->getKey());

    $this->getJson("/api/v1/students/{$student->getKey()}/status", [
        'Authorization' => "Bearer {$token}",
    ])->assertOk()
        ->assertJsonPath('meta.api_version', 'v1')
        ->assertJsonPath('data.user_id', (string) $student->getKey())
        ->assertJsonPath('data.student_id', (string) $student->getKey())
        ->assertJsonMissingPath('data.profile_id');

    $this->getJson("/api/v1/students/{$student->getKey()}/status/history", [
        'Authorization' => "Bearer {$token}",
    ])->assertOk()
        ->assertJsonPath('meta.api_version', 'v1')
        ->assertJsonPath('data.student_id', (string) $student->getKey());

    $this->getJson("/api/v1/students/{$profile->getKey()}/status", [
        'Authorization' => "Bearer {$token}",
    ])->assertNotFound();

    $this->getJson("/api/v1/students/{$profile->getKey()}/status/history", [
        'Authorization' => "Bearer {$token}",
    ])->assertNotFound();
});

it('rejects API requests without a service token', function () {
    $this->getJson('/api/v1/students/student-1/status')->assertUnauthorized();
});

it('rejects a valid token without students read on every protected student status route', function () {
    $client = ServiceClient::create([
        'name' => 'Limited service',
        'client_id' => 'svc_limited',
        'secret_hash' => Hash::make('secret'),
        'scopes' => ['students:read'],
        'active' => true,
    ]);

    $token = $this->postJson('/api/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->client_id,
        'client_secret' => 'secret',
    ])->assertOk()->assertJsonPath('scope', '')->json('access_token');

    $this->getJson('/api/v1/students/student-1/status', [
        'Authorization' => "Bearer {$token}",
    ])->assertForbidden();

    $this->getJson('/api/v1/students/student-1/status/history', [
        'Authorization' => "Bearer {$token}",
    ])->assertForbidden();
});

it('rejects an OAuth token request containing only an unauthorized scope', function () {
    $client = ServiceClient::create([
        'name' => 'Students service',
        'client_id' => 'svc_students_unknown_scope',
        'secret_hash' => Hash::make('secret'),
        'scopes' => ['students:read'],
        'active' => true,
    ]);

    $this->postJson('/api/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->client_id,
        'client_secret' => 'secret',
        'scope' => 'unknown:scope',
    ])->assertStatus(400)
        ->assertJsonPath('error', 'invalid_scope')
        ->assertJsonMissing(['access_token']);
});

it('rejects an OAuth token request that mixes authorized and unauthorized scopes', function () {
    $client = ServiceClient::create([
        'name' => 'Students service',
        'client_id' => 'svc_students_mixed_scopes',
        'secret_hash' => Hash::make('secret'),
        'scopes' => ['students:read'],
        'active' => true,
    ]);

    $this->postJson('/api/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->client_id,
        'client_secret' => 'secret',
        'scope' => 'students:read unknown:scope',
    ])->assertStatus(400)
        ->assertJsonPath('error', 'invalid_scope')
        ->assertJsonMissing(['access_token']);
});

it('stores versioned domain events in the outbox', function () {
    StudentConsentChanged::dispatch('student-1', 'privacy', 'accepted', '2026.1');

    expect(EventOutbox::where('event_name', 'student.consent.changed.v1')->count())->toBe(1);
    expect(EventOutbox::first()->payload['consent_id'])->toBe('privacy');
});

it('publishes pending events and marks them as delivered', function () {
    config(['events.sink_url' => 'https://events.test/v1/events']);
    Http::fake(['https://events.test/*' => Http::response(['accepted' => true], 202)]);
    StudentConsentChanged::dispatch('student-1', 'privacy', 'accepted', '2026.1');

    $this->artisan('events:publish')->assertSuccessful();

    expect(EventOutbox::first()->published_at)->not->toBeNull();
    Http::assertSentCount(1);
});
