<?php

namespace Tests\Unit;

use App\Domains\Financial\Models\CashReceipt;
use App\Domains\Financial\Services\ReceiptPdfService;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** No application bootstrap: any database connection request fails the test. */
final class CashReceiptImmutabilityTest extends TestCase
{
    private $previousResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousResolver = CashReceipt::getConnectionResolver();
        $resolver = $this->createMock(ConnectionResolverInterface::class);
        $resolver->expects(self::never())->method('connection');
        CashReceipt::setConnectionResolver($resolver);
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver) {
            CashReceipt::setConnectionResolver($this->previousResolver);
        } else {
            CashReceipt::unsetConnectionResolver();
        }
        parent::tearDown();
    }

    private function issuedReceipt(): CashReceipt
    {
        $receipt = new CashReceipt;
        $receipt->setDateFormat('Y-m-d H:i:s');
        $receipt->setRawAttributes([
            'id' => 1, 'public_id' => 'original-id', 'cash_movement_id' => 10,
            'folio' => 'CAJ-ORIGINAL', 'snapshot' => json_encode($this->snapshot()),
            'issued_at' => '2026-11-11 12:00:00',
            'created_at' => '2026-11-11 12:00:00', 'updated_at' => '2026-11-11 12:00:00',
        ], true);
        $receipt->exists = true;

        return $receipt;
    }

    private function snapshot(): array
    {
        return ['id' => 'original-id', 'folio' => 'CAJ-ORIGINAL',
            'issued_at' => '2026-11-11T12:00:00Z', 'amount_cents' => 500,
            'movement_type' => 'CASH_IN', 'currency' => 'MXN',
            'cash_register_name' => 'Caja original', 'association_id' => 'association'];
    }

    #[DataProvider('historicalAttributes')]
    public function test_rejects_changes_to_every_historical_attribute(string $attribute, mixed $value): void
    {
        $receipt = $this->issuedReceipt();
        $receipt->forceFill([$attribute => $value]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Los comprobantes de caja emitidos son inmutables.');
        $receipt->save();
    }

    public static function historicalAttributes(): iterable
    {
        yield ['id', 2];
        yield ['public_id', 'replacement'];
        yield ['cash_movement_id', 11];
        yield ['folio', 'CAJ-REPLACEMENT'];
        yield ['snapshot', ['amount_cents' => 999]];
        foreach (['issued_at', 'created_at', 'updated_at'] as $attribute) {
            yield [$attribute, '2026-11-12 12:00:00'];
        }
    }

    #[DataProvider('prohibitedOperations')]
    public function test_rejects_instance_writes_even_without_events(string $operation): void
    {
        $receipt = $this->issuedReceipt();
        $this->expectException(LogicException::class);
        match ($operation) {
            'save' => $receipt->save(),
            'saveQuietly' => $receipt->saveQuietly(),
            'update' => $receipt->update(['folio' => 'changed']),
            'updateQuietly' => $receipt->updateQuietly(['snapshot' => ['amount_cents' => 999]]),
            'withoutEvents' => CashReceipt::withoutEvents(fn () => $receipt->save()),
            'push' => $receipt->push(),
            'pushQuietly' => $receipt->pushQuietly(),
            'touch' => $receipt->touch(),
            'touchQuietly' => $receipt->touchQuietly(),
            'delete' => $receipt->delete(),
            'deleteQuietly' => $receipt->deleteQuietly(),
            'forceDelete' => $receipt->forceDelete(),
            'increment' => $receipt->increment('cash_movement_id'),
            'decrement' => $receipt->decrement('cash_movement_id'),
            // Protected quiet methods in Eloquent are exposed by this test wrapper.
            'incrementQuietly' => (function () { return $this->incrementQuietly('cash_movement_id'); })->call($receipt),
            'decrementQuietly' => (function () { return $this->decrementQuietly('cash_movement_id'); })->call($receipt),
        };
    }

    public static function prohibitedOperations(): iterable
    {
        foreach (['save', 'saveQuietly', 'update', 'updateQuietly', 'withoutEvents',
            'push', 'pushQuietly', 'touch', 'touchQuietly', 'delete', 'deleteQuietly',
            'forceDelete', 'increment', 'decrement', 'incrementQuietly', 'decrementQuietly'] as $operation) {
            yield $operation => [$operation];
        }
    }

    public function test_first_save_still_reaches_the_insert_path(): void
    {
        $receipt = $this->getMockBuilder(CashReceipt::class)
            ->onlyMethods(['newModelQuery', 'performInsert'])->getMock();
        $query = $this->createStub(Builder::class);
        $receipt->expects(self::once())->method('newModelQuery')->willReturn($query);
        $receipt->expects(self::once())->method('performInsert')->with($query)->willReturnCallback(
            function () use ($receipt) { $receipt->exists = true; return true; }
        );
        self::assertTrue($receipt->save());
        $this->expectException(LogicException::class);
        $receipt->save();
    }

    public function test_snapshot_reading_and_pdf_mapping_remain_available(): void
    {
        $receipt = $this->issuedReceipt();
        self::assertSame($this->snapshot(), $receipt->snapshot);
        self::assertSame('CAJ-ORIGINAL', $receipt->folio);
        $data = (new ReceiptPdfService)->cash($receipt->snapshot);
        self::assertSame(500, $data['entries'][0]['amount_cents']);
        self::assertSame('CAJ-ORIGINAL', $data['cash_receipts'][0]['folio']);
        self::assertFalse($receipt->isDirty());
    }
}
