<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->ledger = app(Ledger::class);
    $make = fn (string $code, string $name, AccountType $type, string $currency = 'USD') => Account::create(compact('code', 'name', 'type', 'currency'));
    $cash = $make('1000', 'Cash', AccountType::Asset);
    $loan = $make('2000', 'Bank loan', AccountType::Liability);
    $capital = $make('3000', 'Owner capital', AccountType::Equity);
    $sales = $make('4000', 'Sales', AccountType::Income);
    $rent = $make('5000', 'Rent', AccountType::Expense);
    $make('5100', 'Unused', AccountType::Expense);
    $on = fn (string $date) => Carbon::parse($date);

    $this->ledger->post('Investment', [Line::debit($cash, 10_000), Line::credit($capital, 10_000)], postedAt: $on('2026-09-01'));
    $this->ledger->post('Loan', [Line::debit($cash, 5_000), Line::credit($loan, 5_000)], postedAt: $on('2026-09-02'));
    $this->ledger->post('September sales', [Line::debit($cash, 3_000), Line::credit($sales, 3_000)], postedAt: $on('2026-09-15'));
    $this->ledger->post('October rent', [Line::debit($rent, 2_000), Line::credit($cash, 2_000)], postedAt: $on('2026-10-01'));
    $this->ledger->post('November sales', [Line::debit($cash, 1_500), Line::credit($sales, 1_500)], postedAt: $on('2026-11-10'));

    // Another currency never mixes into a USD report.
    $eurCash = $make('1001', 'Cash EUR', AccountType::Asset, 'EUR');
    $eurSales = $make('4001', 'Sales EUR', AccountType::Income, 'EUR');
    $this->ledger->post('EUR sale', [Line::debit($eurCash, 900), Line::credit($eurSales, 900)], postedAt: $on('2026-10-05'));
});

$names = fn (array $rows) => array_map(fn ($r) => [$r['name'], $r['amount']], $rows);

it('reports profit and loss for a period', function () use ($names) {
    $pl = $this->ledger->incomeStatement('USD', Carbon::parse('2026-10-01'), Carbon::parse('2026-11-30'));

    expect($names($pl['income']))->toBe([['Sales', 1_500]])
        ->and($names($pl['expenses']))->toBe([['Rent', 2_000]])
        ->and([$pl['total_income'], $pl['total_expenses'], $pl['net']])->toBe([1_500, 2_000, -500]);
});

it('reports profit and loss over all time by default', function () {
    $pl = app(Ledger::class)->incomeStatement('usd');

    expect([$pl['total_income'], $pl['total_expenses'], $pl['net']])->toBe([4_500, 2_000, 2_500]);
});

it('reports a balance sheet that balances', function () use ($names) {
    $bs = $this->ledger->balanceSheet('USD', Carbon::parse('2026-11-30'));

    expect($names($bs['assets']))->toBe([['Cash', 17_500]])
        ->and($names($bs['liabilities']))->toBe([['Bank loan', 5_000]])
        ->and($names($bs['equity']))->toBe([['Owner capital', 10_000], ['Earnings not yet closed', 2_500]])
        ->and([$bs['total_assets'], $bs['total_liabilities'], $bs['total_equity']])->toBe([17_500, 5_000, 12_500])
        ->and($bs['total_assets'])->toBe($bs['total_liabilities'] + $bs['total_equity']);
});

it('reports a balance sheet as of an earlier date', function () use ($names) {
    $bs = $this->ledger->balanceSheet('USD', Carbon::parse('2026-09-30'));

    expect($names($bs['assets']))->toBe([['Cash', 18_000]])
        ->and($names($bs['equity']))->toBe([['Owner capital', 10_000], ['Earnings not yet closed', 3_000]])
        ->and($bs['total_assets'])->toBe($bs['total_liabilities'] + $bs['total_equity']);
});

it('leaves out the earnings line when there is nothing to close', function () use ($names) {
    $bs = $this->ledger->balanceSheet('USD', Carbon::parse('2026-09-05'));

    expect($names($bs['equity']))->toBe([['Owner capital', 10_000]])->and($bs['total_assets'])->toBe(15_000);
});

it('includes the account code and type on each row', function () {
    $row = $this->ledger->balanceSheet('USD')['assets'][0];

    expect($row)->toBe(['code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'amount' => 17_500]);
});
