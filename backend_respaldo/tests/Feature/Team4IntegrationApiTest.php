<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PointsAccount;
use App\Models\PointsLedger;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\User;
use App\Services\PointsLedgerService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithPointsCollections;
use Tests\TestCase;

class Team4IntegrationApiTest extends TestCase
{
    use InteractsWithPointsCollections;

    protected string $secret = 'secreto_equipo4_eq3_2026';

    protected function setUp(): void
    {
        parent::setUp();
        User::query()->delete();
        Reward::query()->delete();
        Redemption::query()->delete();
        AuditLog::query()->delete();
        $this->cleanPointsCollections();
    }

    protected function generateHmacHeaders(string $method, string $path, array $body): array
    {
        $timestamp = (string) time();
        $bodyString = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $bodyHash = hash('sha256', $bodyString);
        $payload = $timestamp . '|' . strtoupper($method) . '|' . $path . '|' . $bodyHash;
        $signature = hash_hmac('sha256', $payload, $this->secret);

        return [
            'X-Team-Timestamp' => $timestamp,
            'X-Team-Signature' => $signature,
            'Accept'           => 'application/json',
            'Content-Type'     => 'application/json',
        ];
    }

    public function test_ingest_inventory_movement_with_valid_hmac_registers_and_audits(): void
    {
        $path = '/api/v1/integrations/ingest-event';
        $body = [
            'event_id'      => 'evt_test_inv_001',
            'event_type'    => 'inventory.movement_created',
            'source_domain' => 'inventory',
            'payload'       => [
                'business_id'       => 'BUS-CD-SOUV-001',
                'movement_type'     => 'IN',
                'product_reference' => 'SOUV-TAZA-001',
                'quantity'          => 25,
                'warehouse_id'      => 'wh_central',
                'reason'            => 'Recepción de lote nuevo',
                'actor_id'          => 'STAFF-001',
            ],
        ];

        $headers = $this->generateHmacHeaders('POST', $path, $body);

        $response = $this->withHeaders($headers)->postJson($path, $body);

        $response->assertStatus(201)
            ->assertJson([
                'success'    => true,
                'status'     => 'processed',
                'event_type' => 'inventory.movement_created',
            ]);

        // Verificar registro en Auditoría Transversal (Módulo 7.8)
        $audit = AuditLog::where('action', 'inventory.movement_created')->first();
        $this->assertNotNull($audit);
        $this->assertEquals('inventory', $audit->source_domain);
        $this->assertEquals('SOUV-TAZA-001', $audit->entity_id);
    }

    public function test_ingest_event_rejects_invalid_hmac_signature(): void
    {
        $path = '/api/v1/integrations/ingest-event';
        $body = [
            'event_id'   => 'evt_bad_sig',
            'event_type' => 'inventory.movement_created',
        ];

        $headers = [
            'X-Team-Timestamp' => (string) time(),
            'X-Team-Signature' => 'signature_invalida_1234567890abcdef',
            'Accept'           => 'application/json',
        ];

        $response = $this->withHeaders($headers)->postJson($path, $body);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error'   => 'Firma HMAC de integración inválida.',
            ]);
    }

    public function test_inventory_reservation_expired_event_cancels_redemption_and_refunds_points(): void
    {
        // 1. Crear estudiante y asignarle saldo
        $student = User::create([
            'email'    => 'alumno.expira@campus.edu',
            'name'     => 'Alumno Reserva Expira',
            'role'     => User::ROLE_STUDENT,
            'password' => Hash::make('password123'),
            'status'   => 'active',
        ]);
        $studentId = (string) $student->getKey();

        $ledger = PointsLedger::create([
            'student_id'       => $studentId,
            'idempotency_key'  => (string) Str::uuid(),
            'type'             => 'earn',
            'amount'           => 300,
            'source_domain'    => 'marketplace',
            'source_reference' => 'INIT-001',
        ]);

        PointsAccount::create([
            'student_id'        => $studentId,
            'balance'           => 300,
            'pending_balance'   => 0,
            'lifetime_earned'   => 300,
            'lifetime_redeemed' => 0,
        ]);

        // 2. Simular un canje por 100 puntos que debitó del ledger
        $redeemLedger = PointsLedger::create([
            'student_id'       => $studentId,
            'idempotency_key'  => (string) Str::uuid(),
            'type'             => 'redeem',
            'amount'           => -100,
            'source_domain'    => 'rewards',
            'source_reference' => 'CJ-TEST-EXP1',
        ]);
        PointsAccount::where('student_id', $studentId)->decrement('balance', 100);

        $redemption = Redemption::create([
            'student_id'                  => $studentId,
            'reward_id'                   => (string) Str::uuid(),
            'points_spent'                => 100,
            'status'                      => 'confirmed',
            'redemption_code'             => 'CJ-TEST-EXP1',
            'stock_reservation_reference' => 'res_team4_98765',
            'requested_at'                => now(),
            'ledger_entry_id'             => (string) $redeemLedger->_id,
        ]);

        // 3. Enviar evento inventory.reservation.expired firmado con HMAC
        $path = '/api/v1/integrations/ingest-event';
        $body = [
            'event_id'      => 'evt_exp_001',
            'event_type'    => 'inventory.reservation.expired',
            'source_domain' => 'inventory',
            'payload'       => [
                'reservation_id'   => 'res_team4_98765',
                'origin_reference' => 'CJ-TEST-EXP1',
                'origin_type'      => 'REWARD',
            ],
        ];

        $headers = $this->generateHmacHeaders('POST', $path, $body);
        $response = $this->withHeaders($headers)->postJson($path, $body);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status'  => 'processed',
            ]);

        // 4. Comprobar que el canje pasó a cancelled y se revirtieron los puntos
        $redemptionFresh = $redemption->fresh();
        $this->assertEquals('cancelled', $redemptionFresh->status);
        $this->assertStringContainsString('expirada', $redemptionFresh->cancellation_reason);

        // El balance del estudiante debe volver a 300
        $ledgerService = app(PointsLedgerService::class);
        $balance = $ledgerService->getAvailableBalance($studentId);
        $this->assertEquals(300, $balance);
    }

    public function test_physical_reward_redemption_calls_team4_reservation(): void
    {
        // Fake de HTTP para simular la API de Equipo 4 respondiendo 201
        Http::fake([
            '*/api/v1/inventory/reservations' => Http::response([
                'reservation_id'   => '01J_RES_MOCK_SUCCESS',
                'status'           => 'reserved',
                'origin_reference' => 'CJ-TEST-1234',
            ], 201),
        ]);

        $student = User::create([
            'email'    => 'alumno.fisico@campus.edu',
            'name'     => 'Alumno Canje Fisico',
            'role'     => User::ROLE_STUDENT,
            'password' => Hash::make('password123'),
            'status'   => 'active',
        ]);
        $token = $student->createToken('test-token')->plainTextToken;
        $studentId = (string) $student->getKey();

        PointsLedger::create([
            'student_id'       => $studentId,
            'idempotency_key'  => (string) Str::uuid(),
            'type'             => 'earn',
            'amount'           => 500,
            'source_domain'    => 'marketplace',
            'source_reference' => 'INIT-002',
        ]);

        PointsAccount::create([
            'student_id'        => $studentId,
            'balance'           => 500,
            'pending_balance'   => 0,
            'lifetime_earned'   => 500,
            'lifetime_redeemed' => 0,
        ]);

        $physicalReward = Reward::create([
            'name'                     => 'Taza Conmemorativa Campus',
            'points_required'          => 200,
            'stock'                    => 50,
            'reserved_stock'           => 0,
            'is_physical'              => true,
            'linked_product_reference' => 'SOUV-TAZA-001',
            'status'                   => 'active',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/redemptions', [
                'reward_id' => (string) $physicalReward->_id,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
            ]);

        $redemptionId = $response->json('redemption._id');
        $redemption = Redemption::find($redemptionId);
        $this->assertNotNull($redemption);
        $this->assertEquals('01J_RES_MOCK_SUCCESS', $redemption->stock_reservation_reference);
    }
}
