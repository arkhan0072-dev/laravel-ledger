<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->ledger = app(Ledger::class);
    $this->cash = Account::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset, 'currency' => 'USD']);
    $this->sales = Account::create(['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income, 'currency' => 'USD']);
    $this->rent = Account::create(['code' => '5000', 'name' => 'Rent', 'type' => AccountType::Expense, 'currency' => 'USD']);
    $on = fn (string $date) => Carbon::parse($date);

    $this->ledger->post('September sale', [Line::debit($this->cash, 1_000), Line::credit($this->sales, 1_000)], postedAt: $on('2026-09-20'));
    $this->ledger->post('October rent', [Line::debit($this->rent, 400), Line::credit($this->cash, 400)], postedAt: $on('2026-10-01'));
    $this->ledger->post('October sale', [Line::debit($this->cash, 250), Line::credit($this->sales, 250)], postedAt: $on('2026-10-15'));
    $this->ledger->post('November sale', [Line::debit($this->cash, 300), Line::credit($this->sales, 300)], postedAt: $on('2026-11-02'));
});

it('lists every line with a running balance', function () {
    $statement = $this->ledger->statement($this->cash);

    expect($statement['opening'])->toBe(0)
        ->and(array_map(fn ($r) => [$r['date'], $r['description'], $r['debit'], $r['credit'], $r['balance']], $statement['rows']))->toBe([
            ['2026-09-20', 'September sale', 1_000, 0, 1_000],
            ['2026-10-01', 'October rent', 0, 400, 600],
            ['2026-10-15', 'October sale', 250, 0, 850],
            ['2026-11-02', 'November sale', 300, 0, 1_150],
        ])
        ->and($statement['closing'])->toBe(1_150)
        ->and($statement['closing'])->toBe($this->cash->balance());
});

it('limits the lines to a period and carries the opening balance in', function () {
    $statement = $this->ledger->statement($this->cash, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

    expect($statement['opening'])->toBe(1_000)
        ->and(array_column($statement['rows'], 'balance'))->toBe([600, 850])
        ->and($statement['closing'])->toBe(850);
});

it('runs the balance on the account\'s normal side', function () {
    $statement = $this->ledger->statement($this->sales, Carbon::parse('2026-10-01'));

    expect($statement['opening'])->toBe(1_000)
        ->and(array_column($statement['rows'], 'balance'))->toBe([1_250, 1_550]);
});

it('includes the entry id for each line', function () {
    $entry = $this->ledger->post('Late sale', [Line::debit($this->cash, 5), Line::credit($this->sales, 5)], postedAt: Carbon::parse('2026-11-20'));

    expect(last($this->ledger->statement($this->cash)['rows'])['entry_id'])->toBe($entry->id);
});

it('is empty for a period with no activity', function () {
    $statement = $this->ledger->statement($this->cash, Carbon::parse('2027-01-01'), Carbon::parse('2027-01-31'));

    expect($statement['rows'])->toBe([])->and($statement['opening'])->toBe(1_150)->and($statement['closing'])->toBe(1_150);
});
