<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Exceptions\InvalidEntry;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Models\Account;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->ledger = app(Ledger::class);
    $usd = Account::create(['code' => '1000', 'name' => 'Cash USD', 'type' => AccountType::Asset, 'currency' => 'USD']);
    $eur = Account::create(['code' => '1001', 'name' => 'Cash EUR', 'type' => AccountType::Asset, 'currency' => 'EUR']);
    $this->fx = Account::create(['code' => '4900', 'name' => 'FX gain/loss', 'type' => AccountType::Income, 'currency' => 'USD']);
    // Paid $100.00 for 92.00 EUR.
    $this->ledger->convert('Buy EUR', $usd, 10_000, $eur, 9_200, postedAt: Carbon::parse('2026-10-15'));
});

it('books a gain and leaves nothing unbooked', function () {
    $entry = $this->ledger->bookFxGainLoss('USD', ['EUR' => 1.10], $this->fx, Carbon::parse('2026-10-31'));

    expect($entry->posted_at->toDateString())->toBe('2026-10-31')
        ->and($entry->description)->toBe('FX revaluation 2026-10-31')
        ->and($this->fx->balance())->toBe(120)
        ->and($this->ledger->fxGainLoss('USD', ['EUR' => 1.10]))->toBe(0)
        ->and($this->ledger->incomeStatement('USD')['net'])->toBe(120);
});

it('books a loss', function () {
    $this->ledger->bookFxGainLoss('USD', ['EUR' => 1.05], $this->fx);

    expect($this->fx->balance())->toBe(-340);
});

it('books only the movement since the last booking', function () {
    $this->ledger->bookFxGainLoss('USD', ['EUR' => 1.10], $this->fx, Carbon::parse('2026-10-31'));
    // 92.00 EUR at 1.12 is $103.04: $3.04 in all, $1.20 of it booked in October.
    $this->ledger->bookFxGainLoss('USD', ['EUR' => 1.12], $this->fx, Carbon::parse('2026-11-30'));

    expect($this->fx->balance())->toBe(304);
});

it('books nothing when there is nothing to book', function () {
    expect($this->ledger->bookFxGainLoss('USD', ['EUR' => 10_000 / 9_200], $this->fx))->toBeNull();
});

it('needs an income or expense account in the base currency', function (string $type, string $currency, string $message) {
    $account = Account::create(['code' => '4999', 'name' => 'Target', 'type' => AccountType::from($type), 'currency' => $currency]);

    expect(fn () => $this->ledger->bookFxGainLoss('USD', ['EUR' => 1.10], $account))->toThrow(InvalidEntry::class, $message);
})->with([
    ['asset', 'USD', 'The FX gain/loss account must be an income or expense account, got asset.'],
    ['income', 'EUR', 'The FX gain/loss account must be in USD, got EUR.'],
]);
