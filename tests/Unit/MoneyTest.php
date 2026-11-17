<?php

use App\Support\Money;

it('parses decimal strings exactly', function (string $decimal, int $nanos) {
    expect(Money::fromDecimal($decimal)->nanos)->toBe($nanos);
})->with([
    ['0', 0],
    ['5.00', 5_000_000_000],
    ['12.50', 12_500_000_000],
    ['0.046125000', 46_125_000],
    ['0.000000001', 1],
    ['1234.5', 1_234_500_000_000],
]);

it('rejects anything that is not an exact dollar amount', function (string $invalid) {
    Money::fromDecimal($invalid);
})->with(['', 'abc', '-1.00', '1.', '.5', '1e3', '0.0000000001', '1,000.00'])
    ->throws(InvalidArgumentException::class);

it('prints nine decimal places like Pixelforge does', function (int $nanos, string $decimal) {
    expect(Money::fromNanos($nanos)->toDecimal())->toBe($decimal);
})->with([
    [0, '0.000000000'],
    [1, '0.000000001'],
    [373_875_000, '0.373875000'],
    [5_000_000_000, '5.000000000'],
    [-1_500_000_000, '-1.500000000'],
]);

it('survives a round trip through a decimal string', function () {
    expect(Money::fromDecimal('0.169080000')->toDecimal())->toBe('0.169080000');
});

it('adds and subtracts without drifting', function () {
    // The classic float failure: 0.1 + 0.2 !== 0.3.
    $sum = Money::fromDecimal('0.1')->plus(Money::fromDecimal('0.2'));

    expect($sum->toDecimal())->toBe('0.300000000')
        ->and($sum->minus(Money::fromDecimal('0.3'))->isZero())->toBeTrue();
});
