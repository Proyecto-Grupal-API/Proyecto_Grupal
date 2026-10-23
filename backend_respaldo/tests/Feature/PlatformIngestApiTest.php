<?php

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithPointsCollections;
use Tests\TestCase;

class PlatformIngestApiTest extends TestCase
{
    use InteractsWithPointsCollections;

    private function tokenFor(string $role): string
    {
        $u = User::create(['email' => "$role@campus.edu", 'name' => $role, 'role' => $role,
            'password' => Hash::make('x'), 'status' => 'active']);
        return $u->createToken('t')->plainTextToken;
    }

    protected function setUp(): void
    {
        parent::setUp();
        User::query()->delete();
        AnalyticsEvent::query()->delete();
        $this->cleanPointsCollections();
    }

    private function envelope(array $o = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(), 'event_name' => 'services.loan.created.v1',
            'source_domain' => 'services', 'aggregate_id' => 'loan-1',
            'occurred_at' => now()->toIso8601String(), 'payload' => ['student_id' => 's1'],
        ], $o);
    }

    public function test_duplicate_event_is_counted_once(): void
    {
        $token = $this->tokenFor(User::ROLE_SYSTEM);
        $body = $this->envelope();

        $this->withToken($token)->postJson('/api/v1/plataforma/eventos', $body)->assertStatus(202);
        $this->withToken($token)->postJson('/api/v1/plataforma/eventos', $body)->assertStatus(200);

        $this->assertSame(1, AnalyticsEvent::where('event_id', $body['event_id'])->count());
    }

    public function test_invalid_envelope_returns_422_with_error_shape(): void
    {
        $this->withToken($this->tokenFor(User::ROLE_SYSTEM))
            ->postJson('/api/v1/plataforma/eventos', $this->envelope(['payload' => ['email' => 'a@b.c']]))
            ->assertStatus(422)->assertJsonStructure(['codigo', 'mensaje', 'correlacion_id']);
    }

    public function test_student_cannot_post_events(): void
    {
        $this->withToken($this->tokenFor(User::ROLE_STUDENT))
            ->postJson('/api/v1/plataforma/eventos', $this->envelope())->assertStatus(403);
    }
}