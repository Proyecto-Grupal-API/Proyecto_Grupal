<?php

use App\Models\NfcCard;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;

beforeEach(function () {
    Role::firstOrCreate(['name' => Role::ADMIN], ['name' => Role::ADMIN, 'display_name' => 'Admin']);
});

test('a regular authenticated user cannot register an NFC card', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    $response = $this->actingAs($user)->post('/nfc-cards', [
        'user_id' => (string) $target->getKey(),
        'uid' => 'UID-12345',
    ]);

    $response->assertForbidden();
    expect(NfcCard::where('uid', 'UID-12345')->exists())->toBeFalse();
});

test('a regular user cannot change the status of an NFC card', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();

    $card = NfcCard::create([
        'user_id' => (string) $owner->getKey(),
        'uid' => 'UID-99999',
        'registered_by' => (string) $owner->getKey(),
        'status' => 'active',
        'registered_at' => now(),
    ]);

    $response = $this->actingAs($user)->patch("/nfc-cards/{$card->getKey()}/status", [
        'status' => 'blocked',
        'reason' => 'intento no autorizado',
    ]);

    $response->assertForbidden();
    expect($card->fresh()->status)->toBe('active');
});

test('an admin can register and manage NFC cards', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);
    $target = User::factory()->create();

    StudentProfile::create([
        'user_id' => (string) $target->getKey(),
        'enrollment_number' => 'NFC-ADMIN-1',
        'academic_status' => 'active',
    ]);

    $response = $this->actingAs($admin)->post('/nfc-cards', [
        'user_id' => (string) $target->getKey(),
        'uid' => 'UID-ADMIN-1',
    ]);

    $response->assertRedirect();
    expect(NfcCard::where('uid', 'UID-ADMIN-1')->exists())->toBeTrue();
});

test('a student only sees their own card in the index, an admin sees all', function () {
    $studentA = User::factory()->create();
    $studentB = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);

    NfcCard::create([
        'user_id' => (string) $studentA->getKey(),
        'uid' => 'UID-A',
        'registered_by' => (string) $admin->getKey(),
        'status' => 'active',
        'registered_at' => now(),
    ]);

    NfcCard::create([
        'user_id' => (string) $studentB->getKey(),
        'uid' => 'UID-B',
        'registered_by' => (string) $admin->getKey(),
        'status' => 'active',
        'registered_at' => now(),
    ]);

    $this->actingAs($studentA)
        ->get('/nfc-cards')
        ->assertInertia(fn ($page) => $page->has('cards', 1));

    $this->actingAs($admin)
        ->get('/nfc-cards')
        ->assertInertia(fn ($page) => $page->has('cards', 2));
});
