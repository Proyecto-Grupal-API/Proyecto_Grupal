<?php

namespace Database\Seeders;

use App\Models\AuditCorrelation;
use App\Models\AuditLog;
use App\Models\Catalog;
use App\Models\FraudFlag;
use App\Models\PointsAccount;
use App\Models\PointsLedger;
use App\Models\PointsPolicy;
use App\Models\Redemption;
use App\Models\RedemptionEvent;
use App\Models\Reward;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PointsAndRedemptionsSeeder extends Seeder
{
    public function run(): void
    {
        $student = User::where('role', User::ROLE_STUDENT)->first();
        if (! $student) {
            return;
        }

        $studentId = (string) $student->getKey();

        // 1. Políticas de gobierno de puntos
        PointsPolicy::firstOrCreate(
            ['name' => 'Política General de Expiración Semestral'],
            [
                'type'                       => 'expiration',
                'expiration_days'            => 180,
                'expiration_batch_strategy'  => 'monthly_batch',
                'daily_cap_points'           => 1000,
                'max_redemptions_per_day'    => 5,
                'reversal_grace_period_days' => 7,
                'status'                     => 'active',
                'config'                     => ['notify_days_before' => 15],
            ]
        );

        // 2. Parámetros y configuraciones sensibles
        $settings = [
            ['key' => 'points.earn_ratio_default', 'value' => 1, 'type' => 'integer', 'description' => 'Puntos base por unidad de compra', 'is_sensitive' => false],
            ['key' => 'points.daily_cap_default', 'value' => 1000, 'type' => 'integer', 'description' => 'Tope máximo diario por estudiante', 'is_sensitive' => false],
            ['key' => 'fraud.max_shortfall_threshold', 'value' => 500, 'type' => 'integer', 'description' => 'Umbral de faltante para alerta de fraude alta', 'is_sensitive' => false],
            ['key' => 'security.api_webhook_secret', 'value' => 'whsec_campus_digital_internal_2026', 'type' => 'string', 'description' => 'Firma secreta para webhooks de ventas', 'is_sensitive' => true],
        ];

        foreach ($settings as $s) {
            Setting::firstOrCreate(['key' => $s['key']], $s);
        }

        // 3. Catálogos generales
        Catalog::firstOrCreate(
            ['code' => 'CATEGORIAS_RECOMPENSAS'],
            [
                'name'        => 'Categorías de Recompensas',
                'description' => 'Clasificación oficial del catálogo de recompensas',
                'items'       => [
                    ['code' => 'ACC', 'label' => 'Accesorios y Ropa', 'icon' => '🎒'],
                    ['code' => 'ALI', 'label' => 'Alimentos y Cafetería', 'icon' => '☕'],
                    ['code' => 'ACA', 'label' => 'Académico y Libros', 'icon' => '📚'],
                    ['code' => 'SER', 'label' => 'Servicios de Campus', 'icon' => '🖨'],
                ],
            ]
        );

        // 4. Movimientos iniciales de puntos para el estudiante
        $existingEntries = PointsLedger::where('student_id', $studentId)->count();
        if ($existingEntries === 0) {
            $e1 = PointsLedger::create([
                'student_id'       => $studentId,
                'idempotency_key'  => (string) Str::uuid(),
                'type'             => 'earn',
                'amount'           => 350,
                'source_domain'    => 'marketplace',
                'source_reference' => 'VENTA-2026-001',
                'business_id'      => 'biz-cafeteria-central',
                'description'      => 'Acumulación por consumo en Cafetería Central',
            ]);

            $e2 = PointsLedger::create([
                'student_id'       => $studentId,
                'idempotency_key'  => (string) Str::uuid(),
                'type'             => 'earn',
                'amount'           => 400,
                'source_domain'    => 'marketplace',
                'source_reference' => 'VENTA-2026-002',
                'business_id'      => 'biz-libreria',
                'description'      => 'Acumulación por compra de libros académicos (Feria del Libro x3)',
            ]);

            $account = PointsAccount::firstOrCreate(
                ['student_id' => $studentId],
                [
                    'balance'              => 750,
                    'pending_balance'      => 0,
                    'lifetime_earned'      => 750,
                    'lifetime_redeemed'    => 0,
                    'last_ledger_entry_id' => (string) $e2->_id,
                ]
            );

            // 5. Un canje de ejemplo ya confirmado
            $reward = Reward::where('points_required', '<=', 250)->first();
            if ($reward) {
                $code = 'CJ-TEST-7744';
                $redemption = Redemption::create([
                    'student_id'                  => $studentId,
                    'reward_id'                   => (string) $reward->_id,
                    'points_spent'                => $reward->points_required,
                    'status'                      => 'confirmed',
                    'redemption_code'             => $code,
                    'stock_reservation_reference' => (string) Str::uuid(),
                    'requested_at'                => now()->subDays(1),
                ]);

                $e3 = PointsLedger::create([
                    'student_id'       => $studentId,
                    'idempotency_key'  => (string) Str::uuid(),
                    'type'             => 'redeem',
                    'amount'           => -$reward->points_required,
                    'source_domain'    => 'rewards',
                    'source_reference' => (string) $redemption->_id,
                    'description'      => "Canje {$reward->name} ({$code})",
                ]);

                $redemption->update(['ledger_entry_id' => (string) $e3->_id]);

                RedemptionEvent::create([
                    'redemption_id' => (string) $redemption->_id,
                    'from_status'   => 'requested',
                    'to_status'     => 'confirmed',
                    'actor_id'      => $studentId,
                    'actor_role'    => 'student',
                    'note'          => 'Canje inicial de prueba listo para validación docente.',
                ]);

                $account->decrement('balance', $reward->points_required);
                $account->increment('lifetime_redeemed', $reward->points_required);
                $account->update(['last_ledger_entry_id' => (string) $e3->_id]);
            }

            // 6. Alerta de fraude de ejemplo para el panel de gobierno
            FraudFlag::create([
                'student_id'  => $studentId,
                'business_id' => 'biz-souvenirs',
                'reason'      => 'points_reversal_shortfall',
                'severity'    => FraudFlag::SEVERITY_MEDIUM,
                'status'      => FraudFlag::STATUS_OPEN,
                'notes'       => 'Alerta preventiva de demostración: reverso registrado con shortfall.',
            ]);

            // 7. Registro de auditoría inicial
            AuditLog::create([
                'actor_id'      => $studentId,
                'actor_role'    => 'student',
                'action'        => 'points.earned',
                'entity_type'   => 'PointsLedger',
                'entity_id'     => (string) $e1->_id,
                'after'         => ['amount' => 350, 'student_id' => $studentId],
                'source_domain' => 'marketplace',
            ]);
        }
    }
}
