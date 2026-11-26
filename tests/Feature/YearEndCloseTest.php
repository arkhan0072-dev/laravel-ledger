<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Exceptions\InvalidEntry;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->ledger = app(Ledger::class);
    $make = fn (string $code, string $name, AccountType $type, string $currency = 'USD') => Account::create(compact('code', 'name', 'type', 'currency'));
    $this->cash = $make('1000', 'Cash', AccountType::Asset);
    $this->retained = $make('3100', 'Retained earnings', AccountType::Equity);
    $this->sales = $make('4000', 'Sales', AccountType::Income);
    $this->rent = $make('5000', 'Rent', AccountType::Expense);
    $this->wages = $make('5100', 'Wages', AccountType::Expense);
    $this->yearEnd = Carbon::parse('2026-12-31');

    $this->ledger->post('Sales', [Line::debit($this->cash, 9_000), Line::credit($this->sales, 9_000)], postedAt: Carbon::parse('2026-06-01'));
    $this->ledger->post('Rent', [Line::debit($this->rent, 2_000), Line::credit($this->cash, 2_000)], postedAt: Carbon::parse('2026-07-01'));
    $this->ledger->post('Wages', [Line::debit($this->wages, 3_000), Line::credit($this->cash, 3_000)], postedAt: Carbon::parse('2026-12-31 18:00'));
});

it('moves the year\'s profit into retained earnings', function () {
    $entry = $this->ledger->closeYear('USD', $this->yearEnd, $this->retained);

    expect($entry->posted_at->toDateString())->toBe('2026-12-31')
        ->and($entry->description)->toBe('Year-end close 2026-12-31')
        ->and([$this->sales->balance(), $this->rent->balance(), $this->wages->balance()])->toBe([0, 0, 0])
        ->and($this->retained->balance())->toBe(4_000)
        ->and($this->ledger->verify())->toBe([]);
});

it('shows retained earnings on the balance sheet instead of unclosed earnings', function () {
    $this->ledger->closeYear('USD', $this->yearEnd, $this->retained);

    $bs = $this->ledger->balanceSheet('USD', $this->yearEnd);
    expect(array_map(fn ($r) => [$r['name'], $r['amount']], $bs['equity']))->toBe([['Retained earnings', 4_000]])
        ->and($bs['total_assets'])->toBe($bs['total_liabilities'] + $bs['total_equity']);
});

it('keeps the closed year\'s profit and loss intact', function () {
    $this->ledger->closeYear('USD', $this->yearEnd, $this->retained);

    $pl = $this->ledger->incomeStatement('USD', Carbon::parse('2026-01-01'), $this->yearEnd);
    expect([$pl['total_income'], $pl['total_expenses'], $pl['net']])->toBe([9_000, 5_000, 4_000]);
});

it('starts the next year from zero', function () {
    $this->ledger->closeYear('USD', $this->yearEnd, $this->retained);
    $this->ledger->post('January sales', [Line::debit($this->cash, 700), Line::credit($this->sales, 700)], postedAt: Carbon::parse('2027-01-05'));

    expect($this->ledger->incomeStatement('USD', Carbon::parse('2027-01-01'), Carbon::parse('2027-12-31'))['net'])->toBe(700)
        ->and($this->sales->balance())->toBe(700);
});

it('debits retained earnings for a loss', function () {
    $this->ledger->post('Big rent', [Line::debit($this->rent, 10_000), Line::credit($this->cash, 10_000)], postedAt: Carbon::parse('2026-08-01'));

    $this->ledger->closeYear('USD', $this->yearEnd, $this->retained);

    expect($this->retained->balance())->toBe(-6_000);
});

it('closes a year only once', function () {
    $first = $this->ledger->closeYear('USD', $this->yearEnd, $this->retained);

    expect($this->ledger->closeYear('USD', $this->yearEnd, $this->retained)?->id)->toBe($first->id);
});

it('returns nothing when there is nothing to close', function () {
    $this->ledger->closeYear('USD', $this->yearEnd, $this->retained);

    expect($this->ledger->closeYear('USD', Carbon::parse('2027-12-31'), $this->retained))->toBeNull();
});

it('needs an equity account in the same currency', function (string $type, string $currency, string $message) {
    $account = Account::create(['code' => '3999', 'name' => 'Target', 'type' => AccountType::from($type), 'currency' => $currency]);

    expect(fn () => $this->ledger->closeYear('USD', $this->yearEnd, $account))->toThrow(InvalidEntry::class, $message);
})->with([
    ['asset', 'USD', 'Retained earnings must be an equity account, got asset.'],
    ['equity', 'EUR', 'Retained earnings must be in USD, got EUR.'],
]);

it('closes a break-even year without touching retained earnings', function () {
    $this->ledger->post('More rent', [Line::debit($this->rent, 4_000), Line::credit($this->cash, 4_000)], postedAt: Carbon::parse('2026-09-01'));

    $entry = $this->ledger->closeYear('USD', $this->yearEnd, $this->retained);

    expect($entry->lines)->toHaveCount(3)->and($this->retained->balance())->toBe(0)->and($this->sales->balance())->toBe(0);
});
