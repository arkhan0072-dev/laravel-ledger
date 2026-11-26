<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Exceptions\InvalidEntry;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Models\Account;
use Arkhan\Ledger\Models\JournalEntry;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->ledger = app(Ledger::class);
    $this->usd = Account::create(['code' => '1000', 'name' => 'Cash USD', 'type' => AccountType::Asset, 'currency' => 'USD']);
    $this->eur = Account::create(['code' => '1001', 'name' => 'Cash EUR', 'type' => AccountType::Asset, 'currency' => 'EUR']);
    $this->exchange = fn (string $currency) => Account::where('code', "FX-{$currency}")->firstOrFail();
});

it('books a conversion as one balanced entry per currency', function () {
    [$sold, $bought] = $this->ledger->convert('Buy EUR', $this->usd, 10_000, $this->eur, 9_200);

    expect($sold->currency)->toBe('USD')
        ->and($bought->currency)->toBe('EUR')
        ->and($this->usd->balance())->toBe(-10_000)
        ->and($this->eur->balance())->toBe(9_200)
        ->and(($this->exchange)('USD')->balance())->toBe(-10_000)
        ->and(($this->exchange)('EUR')->balance())->toBe(9_200)
        ->and($this->ledger->verify())->toBe([]);
});

it('opens one exchange account per currency and reuses it', function () {
    $this->ledger->convert('Buy EUR', $this->usd, 10_000, $this->eur, 9_200);
    $this->ledger->convert('Sell EUR', $this->eur, 4_600, $this->usd, 5_000);

    $exchange = ($this->exchange)('EUR');
    expect(Account::where('code', 'like', 'FX-%')->count())->toBe(2)
        ->and($exchange->type)->toBe(AccountType::Equity)
        ->and($exchange->name)->toBe('Currency exchange EUR')
        ->and($exchange->balance())->toBe(4_600);
});

it('books a retried conversion once', function () {
    $first = $this->ledger->convert('Buy EUR', $this->usd, 10_000, $this->eur, 9_200, 'fx-7');
    $retry = $this->ledger->convert('Buy EUR', $this->usd, 10_000, $this->eur, 9_200, 'fx-7');

    expect(array_map(fn ($e) => $e->id, $retry))->toBe(array_map(fn ($e) => $e->id, $first))
        ->and(JournalEntry::count())->toBe(2);
});

it('refuses a reused key for a different conversion', function () {
    $this->ledger->convert('Buy EUR', $this->usd, 10_000, $this->eur, 9_200, 'fx-7');
    $this->ledger->convert('Buy EUR', $this->usd, 10_000, $this->eur, 9_300, 'fx-7');
})->throws(InvalidEntry::class, 'already used for a different entry');

it('dates both entries', function () {
    $date = Carbon::parse('2026-10-31 12:00');

    $entries = $this->ledger->convert('Buy EUR', $this->usd, 10_000, $this->eur, 9_200, postedAt: $date);

    expect(array_map(fn ($e) => $e->posted_at->toDateString(), $entries))->toBe(['2026-10-31', '2026-10-31']);
});

it('refuses a conversion within one currency', function () {
    $savings = Account::create(['code' => '1002', 'name' => 'Savings USD', 'type' => AccountType::Asset, 'currency' => 'USD']);

    $this->ledger->convert('Move', $this->usd, 100, $savings, 100);
})->throws(InvalidEntry::class, 'A conversion needs two different currencies, got USD and USD.');

it('stores nothing when either amount is invalid', function () {
    try {
        $this->ledger->convert('Buy EUR', $this->usd, 10_000, $this->eur, 0);
    } catch (InvalidEntry) {
    }

    expect(JournalEntry::count())->toBe(0)->and(Account::count())->toBe(2);
});
