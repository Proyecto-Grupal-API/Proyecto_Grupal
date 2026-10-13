<?php

use App\Services\StudentServices\Payments\Money;
use MongoDB\BSON\Decimal128;

test('peso amounts become integer cents without float rounding', function (mixed $pesos, int $cents) {
    expect(Money::toCents($pesos))->toBe($cents);
})->with([
    'integer string' => ['150', 15000],
    'one decimal' => ['0.5', 50],
    'two decimals' => ['10.05', 1005],
    'float' => [10.1, 1010],
    'int' => [7, 700],
    'decimal128' => [new Decimal128('150.50'), 15050],
    'decimal128 exponent' => [new Decimal128('1.5E+2'), 15000],
    'decimal128 negative zero' => [new Decimal128('-0.00'), 0],
]);

test('invalid amounts are rejected', function (mixed $pesos) {
    Money::toCents($pesos);
})->with([
    'three decimals' => ['10.005'],
    'float with three decimals' => [1.005],
    'scientific text' => ['1e3'],
    'boolean' => [true],
    'negative' => ['-5'],
    'too large' => ['9999999999999999999'],
    'empty' => [''],
])->throws(InvalidArgumentException::class);

test('cents are shown in pesos with two decimals', function () {
    expect(Money::toPesos(15050))->toBe('150.50')
        ->and(Money::toPesos(5))->toBe('0.05')
        ->and(Money::toPesos(-1250))->toBe('-12.50')
        ->and(Money::toPesos(null))->toBeNull();
});
