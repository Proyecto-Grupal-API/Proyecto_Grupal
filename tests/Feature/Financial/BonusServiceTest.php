<?php

namespace Tests\Feature\Financial;

use App\Domains\Financial\Enums\BonusMovementType;
use App\Domains\Financial\Enums\BonusStatus;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Enums\BonusRestrictionType;
use App\Domains\Financial\Models\Bonus;
use App\Domains\Financial\Models\BonusLedgerEntry;
use App\Domains\Financial\Services\BonusService;
use App\Domains\Financial\Models\BonusRestriction;
use Tests\TestCase;

class BonusServiceTest extends TestCase
{
    protected function tearDown(): void
{
    $bonuses = Bonus::where(
        'external_reference',
        'TEST-BONUS-001'
    )->get();

    foreach ($bonuses as $bonus) {
        BonusLedgerEntry::where(
            'bonus_id',
            $bonus->public_id
        )->delete();

        BonusRestriction::where(
            'bonus_id',
            $bonus->public_id
        )->delete();

        $bonus->delete();
    }

    parent::tearDown();
}
    public function test_it_issues_a_bonus_with_its_initial_ledger_entry(): void
    {
        $service = app(BonusService::class);

        $validFrom = now();
        $expiresAt = $validFrom->copy()->addDays(30);

        $bonus = $service->issue(
            'STUDENT',
            'student-test-001',
            'ADMIN',
            'admin-test-001',
            BonusType::BECA,
            100000,
            $validFrom,
            $expiresAt,
            false,
            false,
            'TEST-BONUS-001'
        );

        $this->assertInstanceOf(
            Bonus::class,
            $bonus
        );

        $this->assertSame(
            100000,
            $bonus->original_amount_cents
        );

        $this->assertSame(
            100000,
            $bonus->remaining_amount_cents
        );

        $this->assertSame(
            BonusStatus::ACTIVO,
            $bonus->status
        );

        $this->assertSame(
            BonusType::BECA,
            $bonus->type
        );

        $this->assertFalse(
            $bonus->combinable
        );

        $entries = $bonus->ledgerEntries()->get();

        $this->assertCount(
            1,
            $entries
        );

        $entry = $entries->first();

        $this->assertInstanceOf(
            BonusLedgerEntry::class,
            $entry
        );

        $this->assertSame(
            BonusMovementType::EMISION,
            $entry->movement_type
        );

        $this->assertSame(
            100000,
            $entry->amount_cents
        );

        $this->assertSame(
            100000,
            $entry->remaining_after_cents
        );

        $this->assertSame(
            'BONUS_ISSUANCE',
            $entry->reference_type
        );

        $this->assertSame(
            'admin-test-001',
            $entry->actor_id
        );
        
        $this->assertFalse(
            $bonus->allows_partial_use
        );
    }
public function test_it_rejects_zero_or_negative_bonus_amounts(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDays(30);

    foreach ([0, -1000] as $amountCents) {
        try {
            $service->issue(
                'STUDENT',
                'student-test-001',
                'ADMIN',
                'admin-test-001',
                BonusType::BECA,
                $amountCents,
                $validFrom,
                $expiresAt,
                false,
                'TEST-BONUS-001'
            );

            $this->fail(
                'Se esperaba InvalidArgumentException.'
            );
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(
                'El monto del bono debe ser mayor que cero.',
                $exception->getMessage()
            );
        }
    }

    $this->assertSame(
        0,
        Bonus::where(
            'external_reference',
            'TEST-BONUS-001'
        )->count()
    );
}
public function test_it_rejects_an_invalid_validity_period(): void
{
    $service = app(BonusService::class);

    $validFrom = now();

    foreach ([
        $validFrom->copy(),
        $validFrom->copy()->subDay()
    ] as $expiresAt) {
        try {
            $service->issue(
                'STUDENT',
                'student-test-001',
                'ADMIN',
                'admin-test-001',
                BonusType::BECA,
                100000,
                $validFrom,
                $expiresAt,
                false,
                false,
                'TEST-BONUS-001'
            );

            $this->fail(
                'Se esperaba InvalidArgumentException.'
            );
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(
                'La fecha de vencimiento debe ser posterior al inicio de vigencia.',
                $exception->getMessage()
            );
        }
    }

    $this->assertSame(
        0,
        Bonus::where(
            'external_reference',
            'TEST-BONUS-001'
        )->count()
    );
}
public function test_it_issues_a_future_bonus_as_pending(): void
{
    $service = app(BonusService::class);

    $validFrom = now()->addDays(7);
    $expiresAt = $validFrom->copy()->addDays(30);

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        'TEST-BONUS-001'
    );

    $this->assertSame(
        BonusStatus::PENDIENTE,
        $bonus->status
    );

    $this->assertSame(
        100000,
        $bonus->remaining_amount_cents
    );

    $this->assertCount(
        1,
        $bonus->ledgerEntries
    );

    $this->assertSame(
        BonusMovementType::EMISION,
        $bonus->ledgerEntries->first()->movement_type
    );
}
public function test_it_rejects_a_bonus_without_a_beneficiary(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDays(30);

    foreach ([
        ['', 'student-test-001'],
        ['STUDENT', '']
    ] as [$beneficiaryType, $beneficiaryId]) {
        try {
            $service->issue(
                $beneficiaryType,
                $beneficiaryId,
                'ADMIN',
                'admin-test-001',
                BonusType::BECA,
                100000,
                $validFrom,
                $expiresAt,
                false,
                'TEST-BONUS-001'
            );

            $this->fail(
                'Se esperaba InvalidArgumentException.'
            );
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(
                'El beneficiario del bono es obligatorio.',
                $exception->getMessage()
            );
        }
    }

    $this->assertSame(
        0,
        Bonus::where(
            'external_reference',
            'TEST-BONUS-001'
        )->count()
    );
}
public function test_it_rejects_a_bonus_without_an_issuer(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDays(30);

    foreach ([
        ['', 'admin-test-001'],
        ['ADMIN', '']
    ] as [$issuerType, $issuerId]) {
        try {
            $service->issue(
                'STUDENT',
                'student-test-001',
                $issuerType,
                $issuerId,
                BonusType::BECA,
                100000,
                $validFrom,
                $expiresAt,
                false,
                'TEST-BONUS-001'
            );

            $this->fail(
                'Se esperaba InvalidArgumentException.'
            );
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(
                'El emisor del bono es obligatorio.',
                $exception->getMessage()
            );
        }
    }

    $this->assertSame(
        0,
        Bonus::where(
            'external_reference',
            'TEST-BONUS-001'
        )->count()
    );
}
public function test_it_can_issue_a_bonus_that_allows_partial_use(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDays(30);

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $this->assertTrue(
        $bonus->allows_partial_use
    );

    $this->assertSame(
        100000,
        $bonus->remaining_amount_cents
    );
}
public function test_it_rejects_zero_or_negative_consumption_amounts(): void
{
    $service = app(BonusService::class);

    foreach ([0, -1000] as $amountCents) {
        try {
            $service->consume(
                '00000000-0000-0000-0000-000000000000',
                $amountCents
            );

            $this->fail(
                'Se esperaba InvalidArgumentException.'
            );
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(
                'El monto a consumir debe ser mayor que cero.',
                $exception->getMessage()
            );
        }
    }
}
public function test_it_rejects_consumption_for_a_nonexistent_bonus(): void
{
    $service = app(BonusService::class);

    try {
        $service->consume(
            '00000000-0000-0000-0000-000000000000',
            10000
        );

        $this->fail(
            'Se esperaba InvalidArgumentException.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'El bono solicitado no existe.',
            $exception->getMessage()
        );
    }
}
public function test_it_rejects_consumption_before_bonus_validity_starts(): void
{
    $service = app(BonusService::class);

    $validFrom = now()->addDays(7);
    $expiresAt = $validFrom->copy()->addDays(30);

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $this->assertSame(
        BonusStatus::PENDIENTE,
        $bonus->status
    );

    try {
        $service->consume(
            $bonus->public_id,
            10000
        );

        $this->fail(
            'Se esperaba InvalidArgumentException.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'El bono todavía no ha iniciado su vigencia.',
            $exception->getMessage()
        );
    }

    $bonus->refresh();

    $this->assertSame(
        100000,
        $bonus->remaining_amount_cents
    );

    $this->assertCount(
        1,
        $bonus->ledgerEntries
    );
}
public function test_it_rejects_consumption_of_an_expired_bonus(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $this->travelTo(
        $expiresAt->copy()->addSecond()
    );

    try {
        $service->consume(
            $bonus->public_id,
            10000
        );

        $this->fail(
            'Se esperaba InvalidArgumentException.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'El bono ha expirado.',
            $exception->getMessage()
        );
    }

    $bonus->refresh();

    $this->assertSame(
        BonusStatus::EXPIRADO,
        $bonus->status
    );

    $this->assertSame(
        0,
        $bonus->remaining_amount_cents
    );

    $entries = $bonus->ledgerEntries()
        ->orderBy('id')
        ->get();

    $this->assertCount(
        2,
        $entries
    );

    $expiration = $entries->last();

    $this->assertSame(
        BonusMovementType::EXPIRACION,
        $expiration->movement_type
    );

    $this->assertSame(
        -100000,
        $expiration->amount_cents
    );

    $this->assertSame(
        0,
        $expiration->remaining_after_cents
    );
}
public function test_it_expires_a_bonus_and_invalidates_its_remaining_balance(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $this->travelTo(
        $expiresAt->copy()->addSecond()
    );

    $expiredBonus = $service->expireIfNeeded(
        $bonus->public_id
    );

    $this->assertSame(
        BonusStatus::EXPIRADO,
        $expiredBonus->status
    );

    $this->assertSame(
        0,
        $expiredBonus->remaining_amount_cents
    );

    $entries = $expiredBonus->ledgerEntries()
        ->orderBy('id')
        ->get();

    $this->assertCount(
        2,
        $entries
    );

    $expiration = $entries->last();

    $this->assertSame(
        BonusMovementType::EXPIRACION,
        $expiration->movement_type
    );

    $this->assertSame(
        -100000,
        $expiration->amount_cents
    );

    $this->assertSame(
        0,
        $expiration->remaining_after_cents
    );
   
    $service->expireIfNeeded(
        $bonus->public_id
    );

    $expiredBonus->refresh();

    $this->assertSame(
        BonusStatus::EXPIRADO,
        $expiredBonus->status
    );

    $this->assertSame(
        0,
        $expiredBonus->remaining_amount_cents
    );

    $this->assertSame(
        1,
        $expiredBonus->ledgerEntries()
            ->where(
                'movement_type',
                BonusMovementType::EXPIRACION
            )
            ->count()
    );
}
public function test_it_rejects_consumption_greater_than_remaining_balance(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    try {
        $service->consume(
            $bonus->public_id,
            100001
        );

        $this->fail(
            'Se esperaba InvalidArgumentException.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'El monto solicitado supera el saldo disponible del bono.',
            $exception->getMessage()
        );
    }

    $bonus->refresh();

    $this->assertSame(
        100000,
        $bonus->remaining_amount_cents
    );

    $this->assertCount(
        1,
        $bonus->ledgerEntries
    );
}
public function test_it_rejects_partial_consumption_when_partial_use_is_not_allowed(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        false,
        'TEST-BONUS-001'
    );

    try {
        $service->consume(
            $bonus->public_id,
            30000
        );

        $this->fail(
            'Se esperaba InvalidArgumentException.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'El bono no permite consumos parciales.',
            $exception->getMessage()
        );
    }

    $bonus->refresh();

    $this->assertSame(
        100000,
        $bonus->remaining_amount_cents
    );

    $this->assertCount(
        1,
        $bonus->ledgerEntries
    );
}
public function test_it_consumes_part_of_a_bonus_and_records_the_movement(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $result = $service->consume(
        $bonus->public_id,
        30000
    );

    $this->assertSame(
        70000,
        $result->remaining_amount_cents
    );

    $this->assertSame(
        BonusStatus::ACTIVO,
        $result->status
    );

    $entries = $result->ledgerEntries()
        ->orderBy('id')
        ->get();

    $this->assertCount(
        2,
        $entries
    );

    $consumption = $entries->last();

    $this->assertSame(
        BonusMovementType::CONSUMO,
        $consumption->movement_type
    );

    $this->assertSame(
        -30000,
        $consumption->amount_cents
    );

    $this->assertSame(
        70000,
        $consumption->remaining_after_cents
    );
}
public function test_it_exhausts_a_bonus_when_all_remaining_balance_is_consumed(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        false,
        'TEST-BONUS-001'
    );

    $result = $service->consume(
        $bonus->public_id,
        100000
    );

    $this->assertSame(
        0,
        $result->remaining_amount_cents
    );

    $this->assertSame(
        BonusStatus::AGOTADO,
        $result->status
    );

    $entries = $result->ledgerEntries()
        ->orderBy('id')
        ->get();

    $this->assertCount(
        2,
        $entries
    );

    $consumption = $entries->last();

    $this->assertSame(
        BonusMovementType::CONSUMO,
        $consumption->movement_type
    );

    $this->assertSame(
        -100000,
        $consumption->amount_cents
    );

    $this->assertSame(
        0,
        $consumption->remaining_after_cents
    );
}
public function test_it_cancels_a_bonus_and_invalidates_its_remaining_balance(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $result = $service->cancel(
        $bonus->public_id,
        'admin-test-002',
        'Bono cancelado por prueba.'
    );

    $this->assertSame(
        BonusStatus::CANCELADO,
        $result->status
    );

    $this->assertSame(
        0,
        $result->remaining_amount_cents
    );

    $entries = $result->ledgerEntries()
        ->orderBy('id')
        ->get();

    $this->assertCount(
        2,
        $entries
    );

    $cancellation = $entries->last();

    $this->assertSame(
        BonusMovementType::CANCELACION,
        $cancellation->movement_type
    );

    $this->assertSame(
        -100000,
        $cancellation->amount_cents
    );

    $this->assertSame(
        0,
        $cancellation->remaining_after_cents
    );

    $this->assertSame(
        'admin-test-002',
        $cancellation->actor_id
    );

    $this->assertSame(
        'Bono cancelado por prueba.',
        $cancellation->reason
    );
}
public function test_it_rejects_cancelling_an_already_cancelled_bonus(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $service->cancel(
        $bonus->public_id,
        'admin-test-002',
        'Primera cancelación.'
    );

    try {
        $service->cancel(
            $bonus->public_id,
            'admin-test-003',
            'Segunda cancelación.'
        );

        $this->fail(
            'Se esperaba InvalidArgumentException.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'El bono ya se encuentra cancelado.',
            $exception->getMessage()
        );
    }

    $bonus->refresh();

    $this->assertSame(
        BonusStatus::CANCELADO,
        $bonus->status
    );

    $this->assertSame(
        1,
        $bonus->ledgerEntries()
            ->where(
                'movement_type',
                BonusMovementType::CANCELACION
            )
            ->count()
    );
}
public function test_it_rejects_cancelling_an_exhausted_bonus(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        false,
        'TEST-BONUS-001'
    );

    $service->consume(
        $bonus->public_id,
        100000
    );

    try {
        $service->cancel(
            $bonus->public_id,
            'admin-test-002',
            'Intento de cancelar un bono agotado.'
        );

        $this->fail(
            'Se esperaba InvalidArgumentException.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'No se puede cancelar un bono agotado.',
            $exception->getMessage()
        );
    }

    $bonus->refresh();

    $this->assertSame(
        BonusStatus::AGOTADO,
        $bonus->status
    );

    $this->assertSame(
        0,
        $bonus->remaining_amount_cents
    );

    $this->assertSame(
        0,
        $bonus->ledgerEntries()
            ->where(
                'movement_type',
                BonusMovementType::CANCELACION
            )
            ->count()
    );
}
public function test_it_rejects_cancelling_an_expired_bonus(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $this->travelTo(
        $expiresAt->copy()->addSecond()
    );

    $service->expireIfNeeded(
        $bonus->public_id
    );

    try {
        $service->cancel(
            $bonus->public_id,
            'admin-test-002',
            'Intento de cancelar un bono expirado.'
        );

        $this->fail(
            'Se esperaba InvalidArgumentException.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'No se puede cancelar un bono expirado.',
            $exception->getMessage()
        );
    }

    $bonus->refresh();

    $this->assertSame(
        BonusStatus::EXPIRADO,
        $bonus->status
    );

    $this->assertSame(
        0,
        $bonus->remaining_amount_cents
    );

    $this->assertSame(
        1,
        $bonus->ledgerEntries()
            ->where(
                'movement_type',
                BonusMovementType::EXPIRACION
            )
            ->count()
    );

    $this->assertSame(
        0,
        $bonus->ledgerEntries()
            ->where(
                'movement_type',
                BonusMovementType::CANCELACION
            )
            ->count()
    );
}
public function test_it_can_have_a_business_restriction(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $restriction = BonusRestriction::create([
        'public_id' => (string) \Illuminate\Support\Str::uuid(),
        'bonus_id' => $bonus->public_id,
        'restriction_type' => BonusRestrictionType::NEGOCIO,
        'target_id' => 'negocio-test-001',
    ]);

    $this->assertSame(
        $bonus->public_id,
        $restriction->bonus_id
    );

    $this->assertSame(
        BonusRestrictionType::NEGOCIO,
        $restriction->restriction_type
    );

    $this->assertSame(
        'negocio-test-001',
        $restriction->target_id
    );

    $this->assertTrue(
        $bonus->restrictions()
            ->where(
                'target_id',
                'negocio-test-001'
            )
            ->exists()
    );
}
public function test_it_can_add_a_restriction_through_the_service(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $restriction = $service->addRestriction(
        $bonus->public_id,
        BonusRestrictionType::NEGOCIO,
        'negocio-test-001'
    );

    $this->assertSame(
        $bonus->public_id,
        $restriction->bonus_id
    );

    $this->assertSame(
        BonusRestrictionType::NEGOCIO,
        $restriction->restriction_type
    );

    $this->assertSame(
        'negocio-test-001',
        $restriction->target_id
    );

    $this->assertSame(
        1,
        $bonus->restrictions()->count()
    );
}
public function test_it_rejects_a_duplicate_restriction(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $service->addRestriction(
        $bonus->public_id,
        BonusRestrictionType::NEGOCIO,
        'negocio-test-001'
    );

    try {
        $service->addRestriction(
            $bonus->public_id,
            BonusRestrictionType::NEGOCIO,
            'negocio-test-001'
        );

        $this->fail(
            'Se esperaba InvalidArgumentException.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'La restricción ya existe para este bono.',
            $exception->getMessage()
        );
    }

    $this->assertSame(
        1,
        $bonus->restrictions()
            ->where(
                'restriction_type',
                BonusRestrictionType::NEGOCIO->value
            )
            ->where(
                'target_id',
                'negocio-test-001'
            )
            ->count()
    );
}
public function test_it_rejects_a_restriction_without_a_target(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    try {
        $service->addRestriction(
            $bonus->public_id,
            BonusRestrictionType::NEGOCIO,
            '   '
        );

        $this->fail(
            'Se esperaba InvalidArgumentException.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'El objetivo de la restricción es obligatorio.',
            $exception->getMessage()
        );
    }

    $this->assertSame(
        0,
        $bonus->restrictions()->count()
    );
}
public function test_it_allows_consumption_in_an_allowed_business(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $service->addRestriction(
        $bonus->public_id,
        BonusRestrictionType::NEGOCIO,
        'negocio-test-001'
    );

    $result = $service->consume(
        $bonus->public_id,
        30000,
        'negocio-test-001'
    );

    $this->assertSame(
        70000,
        $result->remaining_amount_cents
    );

    $this->assertSame(
        BonusStatus::ACTIVO,
        $result->status
    );
}
public function test_it_rejects_consumption_in_a_non_allowed_business(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $service->addRestriction(
        $bonus->public_id,
        BonusRestrictionType::NEGOCIO,
        'negocio-test-001'
    );

    try {
        $service->consume(
            $bonus->public_id,
            30000,
            'negocio-no-autorizado'
        );

        $this->fail(
            'Se esperaba InvalidArgumentException.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'El bono no puede utilizarse en este negocio.',
            $exception->getMessage()
        );
    }

    $bonus->refresh();

    $this->assertSame(
        100000,
        $bonus->remaining_amount_cents
    );

    $this->assertSame(
        0,
        $bonus->ledgerEntries()
            ->where(
                'movement_type',
                BonusMovementType::CONSUMO
            )
            ->count()
    );
}
public function test_it_allows_consumption_in_an_allowed_category(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $service->addRestriction(
        $bonus->public_id,
        BonusRestrictionType::CATEGORIA,
        'alimentos'
    );

    $result = $service->consume(
        $bonus->public_id,
        30000,
        null,
        'alimentos'
    );

    $this->assertSame(
        70000,
        $result->remaining_amount_cents
    );

    $this->assertSame(
        BonusStatus::ACTIVO,
        $result->status
    );
}
public function test_it_rejects_consumption_in_a_non_allowed_category(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $service->addRestriction(
        $bonus->public_id,
        BonusRestrictionType::CATEGORIA,
        'alimentos'
    );

    try {
        $service->consume(
            $bonus->public_id,
            30000,
            null,
            'tecnologia'
        );

        $this->fail(
            'Se esperaba InvalidArgumentException.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'El bono no puede utilizarse en esta categoría.',
            $exception->getMessage()
        );
    }

    $bonus->refresh();

    $this->assertSame(
        100000,
        $bonus->remaining_amount_cents
    );

    $this->assertSame(
        0,
        $bonus->ledgerEntries()
            ->where(
                'movement_type',
                BonusMovementType::CONSUMO
            )
            ->count()
    );
}
public function test_it_allows_consumption_when_all_restrictions_match(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $service->addRestriction(
        $bonus->public_id,
        BonusRestrictionType::NEGOCIO,
        'cafeteria-campus'
    );

    $service->addRestriction(
        $bonus->public_id,
        BonusRestrictionType::CATEGORIA,
        'alimentos'
    );

    $result = $service->consume(
        $bonus->public_id,
        30000,
        'cafeteria-campus',
        'alimentos'
    );

    $this->assertSame(
        70000,
        $result->remaining_amount_cents
    );

    $this->assertSame(
        BonusStatus::ACTIVO,
        $result->status
    );

    $this->assertSame(
        1,
        $result->ledgerEntries()
            ->where(
                'movement_type',
                BonusMovementType::CONSUMO
            )
            ->count()
    );
}
public function test_it_rejects_consumption_when_category_does_not_match(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $service->addRestriction(
        $bonus->public_id,
        BonusRestrictionType::NEGOCIO,
        'cafeteria-campus'
    );

    $service->addRestriction(
        $bonus->public_id,
        BonusRestrictionType::CATEGORIA,
        'alimentos'
    );

    try {
        $service->consume(
            $bonus->public_id,
            30000,
            'cafeteria-campus',
            'tecnologia'
        );

        $this->fail(
            'Se esperaba InvalidArgumentException.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'El bono no puede utilizarse en esta categoría.',
            $exception->getMessage()
        );
    }

    $bonus->refresh();

    $this->assertSame(
        100000,
        $bonus->remaining_amount_cents
    );

    $this->assertSame(
        0,
        $bonus->ledgerEntries()
            ->where(
                'movement_type',
                BonusMovementType::CONSUMO
            )
            ->count()
    );
}
public function test_it_allows_any_business_in_the_allowed_business_set(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $service->addRestriction(
        $bonus->public_id,
        BonusRestrictionType::NEGOCIO,
        'cafeteria-campus'
    );

    $service->addRestriction(
        $bonus->public_id,
        BonusRestrictionType::NEGOCIO,
        'cafeteria-biblioteca'
    );

    $result = $service->consume(
        $bonus->public_id,
        30000,
        'cafeteria-biblioteca'
    );

    $this->assertSame(
        70000,
        $result->remaining_amount_cents
    );

    $this->assertSame(
        BonusStatus::ACTIVO,
        $result->status
    );

    $this->assertSame(
        1,
        $result->ledgerEntries()
            ->where(
                'movement_type',
                BonusMovementType::CONSUMO
            )
            ->count()
    );
}
public function test_it_activates_a_pending_bonus_when_validity_starts(): void
{
    $service = app(BonusService::class);

    $validFrom = now()->addHour();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $this->assertSame(
        BonusStatus::PENDIENTE,
        $bonus->status
    );

    $this->travelTo(
        $validFrom->copy()->addSecond()
    );

    $result = $service->consume(
        $bonus->public_id,
        30000
    );

    $this->assertSame(
        BonusStatus::ACTIVO,
        $result->status
    );

    $this->assertSame(
        70000,
        $result->remaining_amount_cents
    );
}
public function test_it_does_not_expire_an_already_cancelled_bonus(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addHour();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $cancelled = $service->cancel(
        $bonus->public_id,
        'admin-test-002',
        'Cancelación de prueba.'
    );

    $this->assertSame(
        BonusStatus::CANCELADO,
        $cancelled->status
    );

    $this->travelTo(
        $expiresAt->copy()->addSecond()
    );

    $result = $service->expireIfNeeded(
        $bonus->public_id
    );

    $this->assertSame(
        BonusStatus::CANCELADO,
        $result->status
    );

    $this->assertSame(
        0,
        $result->remaining_amount_cents
    );

    $this->assertSame(
        0,
        $result->ledgerEntries()
            ->where(
                'movement_type',
                BonusMovementType::EXPIRACION
            )
            ->count()
    );

    $this->assertSame(
        1,
        $result->ledgerEntries()
            ->where(
                'movement_type',
                BonusMovementType::CANCELACION
            )
            ->count()
    );
}
public function test_it_does_not_expire_an_already_exhausted_bonus(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addHour();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $exhausted = $service->consume(
        $bonus->public_id,
        100000
    );

    $this->assertSame(
        BonusStatus::AGOTADO,
        $exhausted->status
    );

    $this->assertSame(
        0,
        $exhausted->remaining_amount_cents
    );

    $this->travelTo(
        $expiresAt->copy()->addSecond()
    );

    $result = $service->expireIfNeeded(
        $bonus->public_id
    );

    $this->assertSame(
        BonusStatus::AGOTADO,
        $result->status
    );

    $this->assertSame(
        0,
        $result->remaining_amount_cents
    );

    $this->assertSame(
        0,
        $result->ledgerEntries()
            ->where(
                'movement_type',
                BonusMovementType::EXPIRACION
            )
            ->count()
    );

    $this->assertSame(
        1,
        $result->ledgerEntries()
            ->where(
                'movement_type',
                BonusMovementType::CONSUMO
            )
            ->count()
    );
}
public function test_it_rejects_cancelling_a_bonus_expired_by_time(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addHour();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $this->assertSame(
        BonusStatus::ACTIVO,
        $bonus->status
    );

    $this->travelTo(
        $expiresAt->copy()->addSecond()
    );

    try {
        $service->cancel(
            $bonus->public_id,
            'admin-test-002',
            'Intento de cancelar un bono vencido.'
        );

        $this->fail(
            'Se esperaba InvalidArgumentException.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'No se puede cancelar un bono expirado.',
            $exception->getMessage()
        );
    }

    $bonus->refresh();

    $this->assertSame(
        BonusStatus::EXPIRADO,
        $bonus->status
    );

    $this->assertSame(
        0,
        $bonus->remaining_amount_cents
    );

    $this->assertSame(
        1,
        $bonus->ledgerEntries()
            ->where(
                'movement_type',
                BonusMovementType::EXPIRACION
            )
            ->count()
    );

    $this->assertSame(
        0,
        $bonus->ledgerEntries()
            ->where(
                'movement_type',
                BonusMovementType::CANCELACION
            )
            ->count()
    );
}
public function test_it_rejects_consumption_of_a_cancelled_bonus(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $service->cancel(
        $bonus->public_id,
        'admin-test-002',
        'Cancelación de prueba.'
    );

    try {
        $service->consume(
            $bonus->public_id,
            30000
        );

        $this->fail(
            'Se esperaba InvalidArgumentException.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'No se puede consumir un bono cancelado.',
            $exception->getMessage()
        );
    }

    $bonus->refresh();

    $this->assertSame(
        BonusStatus::CANCELADO,
        $bonus->status
    );

    $this->assertSame(
        0,
        $bonus->remaining_amount_cents
    );

    $this->assertSame(
        0,
        $bonus->ledgerEntries()
            ->where(
                'movement_type',
                BonusMovementType::CONSUMO
            )
            ->count()
    );
}
public function test_it_rejects_consumption_of_an_exhausted_bonus(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $service->consume(
        $bonus->public_id,
        100000
    );

    try {
        $service->consume(
            $bonus->public_id,
            10000
        );

        $this->fail(
            'Se esperaba InvalidArgumentException.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'No se puede consumir un bono agotado.',
            $exception->getMessage()
        );
    }

    $bonus->refresh();

    $this->assertSame(
        BonusStatus::AGOTADO,
        $bonus->status
    );

    $this->assertSame(
        0,
        $bonus->remaining_amount_cents
    );

    $this->assertSame(
        1,
        $bonus->ledgerEntries()
            ->where(
                'movement_type',
                BonusMovementType::CONSUMO
            )
            ->count()
    );
}
public function test_it_rejects_consumption_when_required_business_context_is_missing(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $service->addRestriction(
        $bonus->public_id,
        BonusRestrictionType::NEGOCIO,
        'cafeteria-campus'
    );

    try {
        $service->consume(
            $bonus->public_id,
            30000
        );

        $this->fail(
            'Se esperaba InvalidArgumentException.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'El bono no puede utilizarse en este negocio.',
            $exception->getMessage()
        );
    }

    $bonus->refresh();

    $this->assertSame(
        100000,
        $bonus->remaining_amount_cents
    );

    $this->assertSame(
        0,
        $bonus->ledgerEntries()
            ->where(
                'movement_type',
                BonusMovementType::CONSUMO
            )
            ->count()
    );
}
public function test_it_rejects_consumption_when_required_category_context_is_missing(): void
{
    $service = app(BonusService::class);

    $validFrom = now();
    $expiresAt = $validFrom->copy()->addDay();

    $bonus = $service->issue(
        'STUDENT',
        'student-test-001',
        'ADMIN',
        'admin-test-001',
        BonusType::BECA,
        100000,
        $validFrom,
        $expiresAt,
        false,
        true,
        'TEST-BONUS-001'
    );

    $service->addRestriction(
        $bonus->public_id,
        BonusRestrictionType::CATEGORIA,
        'alimentos'
    );

    try {
        $service->consume(
            $bonus->public_id,
            30000
        );

        $this->fail(
            'Se esperaba InvalidArgumentException.'
        );
    } catch (\InvalidArgumentException $exception) {
        $this->assertSame(
            'El bono no puede utilizarse en esta categoría.',
            $exception->getMessage()
        );
    }

    $bonus->refresh();

    $this->assertSame(
        100000,
        $bonus->remaining_amount_cents
    );

    $this->assertSame(
        0,
        $bonus->ledgerEntries()
            ->where(
                'movement_type',
                BonusMovementType::CONSUMO
            )
            ->count()
    );
}
}