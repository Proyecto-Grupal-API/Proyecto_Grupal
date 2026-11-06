<?php

namespace Tests\Unit;

use App\Domains\Financial\Contracts\BonusAuthorizationProvider;
use App\Domains\Financial\Services\BonusService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** No Laravel bootstrap or database; rejected batches may never reach expiration. */
final class BonusCombinedConsumptionInputTest extends TestCase
{
    private const A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    #[DataProvider('invalidBatches')]
    public function test_rejects_invalid_batches_before_any_expiration_or_database_access(array $batch): void
    {
        $provider = $this->createMock(BonusAuthorizationProvider::class);
        $provider->expects(self::never())->method('canIssueBonus');
        $service = $this->getMockBuilder(BonusService::class)->setConstructorArgs([$provider])
            ->onlyMethods(['expireIfNeeded'])->getMock();
        $service->expects(self::never())->method('expireIfNeeded');
        $this->expectException(\InvalidArgumentException::class);
        $service->consumeMultiple($batch);
    }

    public static function invalidBatches(): iterable
    {
        $valid = ['bonus_id' => self::A, 'amount_cents' => 1];
        $second = ['bonus_id' => self::B, 'amount_cents' => 1];
        yield 'empty' => [[]];
        yield 'one' => [[$valid]];
        foreach ([0, -1, 1.5, 1.0, '1', '1.5', true, false, null, [], INF, NAN] as $i => $amount) {
            yield 'amount '.$i => [[$valid, [...$second, 'amount_cents' => $amount]]];
        }
        foreach ([null, '', 'bad-id', 123, [], self::B.' ', ' '.self::B] as $i => $id) {
            yield 'id '.$i => [[$valid, [...$second, 'bonus_id' => $id]]];
        }
        yield 'missing amount' => [[$valid, ['bonus_id' => self::B]]];
        yield 'missing id' => [[$valid, ['amount_cents' => 1]]];
        yield 'non array item' => [[$valid, 'invalid']];
        yield 'duplicate' => [[$valid, $valid]];
        yield 'duplicate uppercase' => [[$valid, [...$valid, 'bonus_id' => strtoupper(self::A)]]];
        yield 'sparse list' => [[1 => $valid, 3 => $second]];
        yield 'named list' => [['first' => $valid, 'second' => $second]];
    }
}
