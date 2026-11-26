<?php

use Arkhan\Ledger\Currency;

it('knows how many decimal places a currency has', function (string $code, int $decimals) {
    expect(Currency::decimals($code))->toBe($decimals);
})->with([
    ['USD', 2],
    ['EUR', 2],
    ['SAR', 2],
    ['PKR', 2],
    ['JPY', 0],
    ['KRW', 0],
    ['KWD', 3],
    ['BHD', 3],
    ['OMR', 3],
    ['jpy', 0],
]);

it('formats minor units for display', function (int $minor, string $code, string $expected) {
    expect(Currency::format($minor, $code))->toBe($expected);
})->with([
    [125050, 'USD', '1,250.50'],
    [5, 'USD', '0.05'],
    [-500, 'USD', '-5.00'],
    [1500, 'JPY', '1,500'],
    [1234, 'KWD', '1.234'],
    [0, 'EUR', '0.00'],
]);
