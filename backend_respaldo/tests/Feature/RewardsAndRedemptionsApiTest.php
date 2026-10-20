<?php

namespace Tests\Feature;

use App\Models\EarningRule;
use App\Models\PointsAccount;
use App\Models\PointsLedger;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithPointsCollections;
use Tests\TestCase;

class RewardsAndRedemptionsApiTest extends TestCase
{
    use InteractsWithPointsCollections;

    protected function setUp(): void
    {
        parent::setUp();
        User::query()->delete();
        Reward::query()->delete();
        Redemption::query()->delete();
        $this->cleanPointsCollections();
    }

    public function test_full_redemption_lifecycle(): void
    {
        // 1. Estudiante con saldo inicial de 500 puntos
        $student = User::create([
            'email'    => 'student@campus.edu',
            'name'     => 'Estudiante Canjeador',
            'role'     => User::ROLE_STUDENT,
            'password' => Hash::make('password123'),
            'status'   => 'active',
        ]);
        $studentId = (string) $student->getKey();

        PointsLedger::create([
            'student_id'       => $studentId,
            'idempotency_key'  => (string) Str::uuid(),
            'type'             => 'earn',
            'amount'           => 500,
            'source_domain'    => 'marketplace',
            'source_reference' => 'TEST-001',
            'description'      => 'Carga inicial',
        ]);

        PointsAccount::create([
            'student_id'        => $studentId,
            'balance'           => 500,
            'pending_balance'   => 0,
            'lifetime_earned'   => 500,
            'lifetime_redeemed' => 0,
        ]);

        // 2. Recompensa disponible de 200 puntos con stock
        $reward = Reward::create([
            'name'            => 'Termo de Acero',
            'description'     => 'Termo 500ml',
            'points_required' => 200,
            'stock'           => 10,
            'reserved_stock'  => 0,
            'status'          => 'active',
        ]);

        // 3. Estudiante solicita el canje
        $studentToken = $student->createToken('student_token')->plainTextToken;

        $redemptionRes = $this->withHeader('Authorization', "Bearer {$studentToken}")
            ->postJson('/api/v1/redemptions', [
                'reward_id' => (string) $reward->_id,
            ]);

        $redemptionRes->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('redemption.status', 'confirmed');

        $rawId = $redemptionRes->json('redemption._id') ?? $redemptionRes->json('redemption.id');
        $redemptionId = is_array($rawId) ? ($rawId['$oid'] ?? reset($rawId)) : (string) $rawId;
        $code = $redemptionRes->json('redemption.redemption_code');

        $this->assertNotEmpty($code);
        $this->assertNotEmpty($redemptionId);

        // Saldo del estudiante debe haber bajado de 500 a 300
        $balanceRes = $this->withHeader('Authorization', "Bearer {$studentToken}")
            ->getJson('/api/v1/points/my-balance');
        $balanceRes->assertJson(['balance' => 300]);

        // 4. Profesor valida el código de canje
        $professor = User::create([
            'email'    => 'prof@campus.edu',
            'name'     => 'Profesor Validador',
            'role'     => User::ROLE_PROFESSOR,
            'password' => Hash::make('password123'),
            'status'   => 'active',
        ]);
        $profToken = $professor->createToken('prof_token')->plainTextToken;
        $this->app['auth']->forgetGuards();

        $validateRes = $this->withHeader('Authorization', "Bearer {$profToken}")
            ->postJson('/api/v1/redemptions/validate-code', [
                'code' => $code,
            ]);

        $validateRes->assertStatus(200)
            ->assertJson([
                'success'     => true,
                'can_deliver' => true,
            ]);

        // 5. Profesor realiza la entrega
        $this->app['auth']->forgetGuards();
        $deliverRes = $this->withHeader('Authorization', "Bearer {$profToken}")
            ->postJson("/api/v1/redemptions/{$redemptionId}/deliver", [
                'note' => 'Entregado en cubículo A-102',
            ]);

        $deliverRes->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonPath('redemption.status', 'delivered');

        // El stock de la recompensa se descontó
        $reward->refresh();
        $this->assertSame(9, $reward->stock);
    }

    public function test_redemption_fails_when_insufficient_points(): void
    {
        $student = User::create([
            'email'    => 'poor.student@campus.edu',
            'name'     => 'Estudiante Sin Puntos',
            'role'     => User::ROLE_STUDENT,
            'password' => Hash::make('password123'),
            'status'   => 'active',
        ]);

        $reward = Reward::create([
            'name'            => 'Laptop Campus',
            'points_required' => 5000,
            'stock'           => 1,
            'status'          => 'active',
        ]);

        $token = $student->createToken('token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/redemptions', [
                'reward_id' => (string) $reward->_id,
            ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }
}
