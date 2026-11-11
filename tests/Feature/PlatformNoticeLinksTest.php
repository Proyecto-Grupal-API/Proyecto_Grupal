<?php

namespace Tests\Feature;

use App\Models\PlatformModule\PointsLedger;
use App\Models\PlatformModule\Redemption;
use App\Models\PlatformModule\RedemptionEvent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use MongoDB\BSON\ObjectId;
use Tests\Support\E1TestTarget;
use Tests\TestCase;

/** Only GETs after fixtures; the protected integrated profile attests the platform alias. */
class PlatformNoticeLinksTest extends TestCase
{
    private User $student;
    private User $otherStudent;

    protected function setUp(): void
    {
        parent::setUp();
        E1TestTarget::requireIntegrated();
        (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
        $this->seed(RoleSeeder::class);
        $this->student = $this->student();
        $this->otherStudent = $this->student();
        $this->actingAs($this->student);
    }

    private function student(): User
    {
        $student = User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
        $student->assignRole(Role::ESTUDIANTE);

        return $student;
    }

    private function movement(User $student, array $attributes = []): PointsLedger
    {
        $movement = new PointsLedger;
        $movement->forceFill(array_replace(['student_id' => (string) $student->getKey(),
            'type' => 'earn', 'amount' => 35, 'idempotency_key' => 'notice-link:'.new ObjectId,
            'description' => 'Movimiento propio'], $attributes))->save();

        return $movement;
    }

    public function test_student_finds_own_historical_movement_before_pagination_without_writes(): void
    {
        $old = $this->movement($this->student, ['created_at' => now()->subYear()]);
        for ($i = 0; $i < 25; ++$i) {
            $this->movement($this->student);
        }
        $before = $old->fresh()->getRawOriginal();
        $url = '/api/v1/recompensas/points/my-history';
        $this->getJson($url)->assertOk()->assertJsonPath('total', 26)->assertJsonCount(20, 'data');
        $selected = $this->getJson($url.'?movement_id='.strtoupper((string) $old->id))
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('last_page', 1)->assertJsonCount(1, 'data');
        self::assertSame((string) $old->id, $selected->json('data.0.id') ?? $selected->json('data.0._id'));
        self::assertEquals($before, $old->fresh()->getRawOriginal());
        self::assertSame(26, PointsLedger::count());
    }

    public function test_foreign_movement_and_student_parameter_cannot_escape_authenticated_ownership(): void
    {
        $own = $this->movement($this->student);
        $foreign = $this->movement($this->otherStudent, ['description' => 'PRIVATE-FOREIGN-MOVEMENT']);
        $url = '/api/v1/recompensas/points/my-history?movement_id='.$foreign->id.'&student_id='.$this->otherStudent->id;
        $this->getJson($url)->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data')
            ->assertDontSee('PRIVATE-FOREIGN-MOVEMENT');
        $this->getJson('/api/v1/recompensas/points/my-history?movement_id='.$own->id.'&type=redeem')
            ->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
    }

    public function test_unknown_movement_returns_an_empty_page(): void
    {
        $this->movement($this->student);
        $this->getJson('/api/v1/recompensas/points/my-history?movement_id='.new ObjectId)
            ->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
    }

    public function test_invalid_movement_references_return_422(): void
    {
        $this->movement($this->student);
        foreach (['', 'bad-id', str_repeat('a', 23), str_repeat('a', 25), str_repeat('g', 24), '507f1f77bcf86cd799439%0A011',
            '%3Cscript%3E', '%7B%22%24ne%22%3Anull%7D'] as $invalid) {
            $this->getJson('/api/v1/recompensas/points/my-history?movement_id='.$invalid)->assertUnprocessable();
        }
        $this->getJson('/api/v1/recompensas/points/my-history?movement_id[]=507f1f77bcf86cd799439011')
            ->assertUnprocessable();
        self::assertSame(1, PointsLedger::count());
    }

    public function test_own_redemption_detail_keeps_real_status_and_event_history_without_writes(): void
    {
        $redemption = Redemption::create(['student_id' => (string) $this->student->id,
            'points_spent' => 25, 'status' => 'cancelled']);
        RedemptionEvent::create(['redemption_id' => (string) $redemption->id, 'from_status' => 'confirmed', 'to_status' => 'cancelled']);
        $before = $redemption->fresh()->getRawOriginal();
        $response = $this->getJson('/api/v1/recompensas/redemptions/'.$redemption->id)
            ->assertOk()->assertJsonPath('student_id', (string) $this->student->id)
            ->assertJsonPath('status', 'cancelled')->assertJsonPath('points_spent', 25)
            ->assertJsonCount(1, 'events')->assertJsonPath('events.0.to_status', 'cancelled');
        self::assertSame((string) $redemption->id, $response->json('id') ?? $response->json('_id'));
        self::assertEquals($before, $redemption->fresh()->getRawOriginal());
        self::assertSame(1, Redemption::count());
        self::assertSame(1, RedemptionEvent::count());
    }

    public function test_foreign_redemption_is_forbidden_without_disclosing_its_fields(): void
    {
        $foreign = Redemption::create(['student_id' => (string) $this->otherStudent->id,
            'redemption_code' => 'PRIVATE-FOREIGN-CODE', 'status' => 'delivered', 'points_spent' => 73]);
        $this->getJson('/api/v1/recompensas/redemptions/'.$foreign->id)->assertForbidden()
            ->assertDontSee('PRIVATE-FOREIGN-CODE')->assertJsonMissingPath('student_id')->assertJsonMissingPath('points_spent');
        self::assertSame('delivered', $foreign->fresh()->status);
    }

    public function test_unknown_redemption_is_not_found(): void
    {
        $this->getJson('/api/v1/recompensas/redemptions/'.new ObjectId)->assertNotFound();
    }
}
