<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;
use Illuminate\Support\Facades\DB;

// The corruption below is written with raw SQL on purpose: it is exactly what
// the package's own API refuses to do, and what ledger:verify exists to catch.

beforeEach(function () {
    $this->cash = Account::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset, 'currency' => 'USD']);
    $this->sales = Account::create(['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income, 'currency' => 'USD']);
    $this->entry = app(Ledger::class)->post('Sale', [Line::debit($this->cash, 1000), Line::credit($this->sales, 1000)]);
});

function line(int $entry, int $account, int $debit, int $credit): int
{
    return DB::table('ledger_entry_lines')->insertGetId(
        ['journal_entry_id' => $entry, 'account_id' => $account, 'debit' => $debit, 'credit' => $credit]
    );
}

it('passes a healthy ledger', function () {
    app(Ledger::class)->reverse($this->entry);

    $this->artisan('ledger:verify')
        ->expectsOutput('Ledger OK: 2 entries checked.')
        ->assertExitCode(0);
});

it('reports an entry that no longer balances', function () {
    line($this->entry->id, $this->cash->id, 500, 0);

    $this->artisan('ledger:verify')
        ->expectsOutput("Entry #{$this->entry->id} does not balance: debits 1500, credits 1000.")
        ->expectsOutput('Ledger has 1 problem(s).')
        ->assertExitCode(1);
});

it('reports an entry with too few lines', function () {
    $id = DB::table('ledger_journal_entries')->insertGetId(['description' => 'Empty', 'currency' => 'USD']);

    $this->artisan('ledger:verify')
        ->expectsOutput("Entry #{$id} has 0 line(s); at least two are required.")
        ->assertExitCode(1);
});

it('reports a line whose account uses another currency', function () {
    $euros = Account::create(['code' => '1010', 'name' => 'Euro cash', 'type' => AccountType::Asset, 'currency' => 'EUR']);
    $a = line($this->entry->id, $euros->id, 70, 0);
    line($this->entry->id, $this->sales->id, 0, 70);

    $this->artisan('ledger:verify')
        ->expectsOutput("Line #{$a} in entry #{$this->entry->id} uses EUR, but the entry is in USD.")
        ->assertExitCode(1);
});

it('reports a line that is not exactly one debit or one credit', function () {
    $zero = line($this->entry->id, $this->cash->id, 0, 0);
    $both = line($this->entry->id, $this->cash->id, 5, 5);

    $this->artisan('ledger:verify')
        ->expectsOutput("Line #{$zero} in entry #{$this->entry->id} must have exactly one positive side.")
        ->expectsOutput("Line #{$both} in entry #{$this->entry->id} must have exactly one positive side.")
        ->expectsOutput('Ledger has 2 problem(s).')
        ->assertExitCode(1);
});

it('returns the problems to code as a list', function () {
    expect(app(Ledger::class)->verify())->toBe([]);
});
