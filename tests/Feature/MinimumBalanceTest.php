<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Exceptions\InvalidEntry;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;
use Arkhan\Ledger\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->ledger = app(Ledger::class);
    $this->cash = Account::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset, 'currency' => 'USD']);
    $this->sales = Account::create(['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income, 'currency' => 'USD']);
    $this->wallet = Account::create(['code' => '2000', 'name' => 'Wallet', 'type' => AccountType::Liability, 'currency' => 'USD', 'min_balance' => 0]);
    $this->topUp = $this->ledger->post('Top-up', [Line::debit($this->cash, 5_000), Line::credit($this->wallet, 5_000)]);
    $this->spend = fn (int $amount) => $this->ledger->post('Purchase', [Line::debit($this->wallet, $amount), Line::credit($this->sales, $amount)]);
});

it('refuses spending more than the balance', function () {
    ($this->spend)(5_001);
})->throws(InvalidEntry::class, 'Insufficient funds in Wallet: the available balance would be -0.01 USD, below the minimum of 0.00 USD.');

it('stores nothing for a refused spend', function () {
    expect(fn () => ($this->spend)(9_000))->toThrow(InvalidEntry::class);

    expect(JournalEntry::count())->toBe(1)->and($this->wallet->balance())->toBe(5_000);
});

it('allows spending down to exactly the minimum', function () {
    ($this->spend)(5_000);

    expect($this->wallet->balance())->toBe(0);
});

it('allows an overdraft down to a negative minimum', function () {
    $credit = Account::create(['code' => '2001', 'name' => 'Credit line', 'type' => AccountType::Liability, 'currency' => 'USD', 'min_balance' => -50_000]);

    $this->ledger->post('Buy on credit', [Line::debit($credit, 50_000), Line::credit($this->sales, 50_000)]);

    expect($credit->balance())->toBe(-50_000)
        ->and(fn () => $this->ledger->post('One more', [Line::debit($credit, 1), Line::credit($this->sales, 1)]))
        ->toThrow(InvalidEntry::class, 'below the minimum of -500.00 USD');
});

it('protects debit-normal accounts too', function () {
    $till = Account::create(['code' => '1001', 'name' => 'Till', 'type' => AccountType::Asset, 'currency' => 'USD', 'min_balance' => 0]);

    $this->ledger->post('Refund from till', [Line::debit($this->sales, 100), Line::credit($till, 100)]);
})->throws(InvalidEntry::class, 'Insufficient funds in Till');

it('never refuses money coming in, even below the minimum', function () {
    DB::table('ledger_accounts')->where('id', $this->wallet->id)->update(['min_balance' => 10_000]);

    $this->ledger->post('Top-up', [Line::debit($this->cash, 1_000), Line::credit($this->wallet, 1_000)]);

    expect($this->wallet->balance())->toBe(6_000);
});

it('leaves accounts without a minimum free to go negative', function () {
    $this->ledger->post('Payout', [Line::debit($this->sales, 100_000), Line::credit($this->cash, 100_000)]);

    expect($this->cash->balance())->toBe(-95_000);
});

it('checks reversals and transfers the same way', function () {
    ($this->spend)(4_000);

    expect(fn () => $this->ledger->reverse($this->topUp))->toThrow(InvalidEntry::class, 'Insufficient funds in Wallet')
        ->and(fn () => $this->ledger->transfer('Send', $this->wallet, Account::create(['code' => '2002', 'name' => 'Other', 'type' => AccountType::Liability, 'currency' => 'USD']), 1_001))
        ->toThrow(InvalidEntry::class, 'Insufficient funds in Wallet');
});

it('locks the protected account while checking, so concurrent spends cannot both pass', function () {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite allows one writer at a time and has no row locks.');
    }
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = strtolower($query->sql);
    });

    ($this->spend)(100);

    expect(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'ledger_accounts') && str_contains($sql, 'for update')))->toBeTrue();
});
