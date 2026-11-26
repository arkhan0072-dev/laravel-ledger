<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-11-30 12:00:00');
    $this->ledger = app(Ledger::class);
    $this->cash = Account::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset, 'currency' => 'USD']);
    $this->sales = Account::create(['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income, 'currency' => 'USD']);
    $this->rent = Account::create(['code' => '5000', 'name' => 'Rent', 'type' => AccountType::Expense, 'currency' => 'USD']);
});

afterEach(fn () => Carbon::setTestNow());

it('dates an entry now unless told otherwise', function () {
    $entry = $this->ledger->post('Sale', [Line::debit($this->cash, 100), Line::credit($this->sales, 100)]);

    expect($entry->posted_at->toDateTimeString())->toBe('2026-11-30 12:00:00');
});

it('books an entry on an earlier date', function () {
    $entry = $this->ledger->post('October invoice', [Line::debit($this->cash, 100), Line::credit($this->sales, 100)],
        postedAt: Carbon::parse('2026-10-31'));

    expect($entry->posted_at->toDateString())->toBe('2026-10-31');
});

it('reports a balance as of any date', function () {
    $this->ledger->post('October sale', [Line::debit($this->cash, 500), Line::credit($this->sales, 500)], postedAt: Carbon::parse('2026-10-15'));
    $this->ledger->post('November sale', [Line::debit($this->cash, 300), Line::credit($this->sales, 300)], postedAt: Carbon::parse('2026-11-15'));

    expect($this->cash->balance(Carbon::parse('2026-09-30')))->toBe(0)
        ->and($this->cash->balance(Carbon::parse('2026-10-31')))->toBe(500)
        ->and($this->cash->balance(Carbon::parse('2026-11-15 00:00:00')))->toBe(800)
        ->and($this->cash->balance())->toBe(800);
});

it('dates a reversal separately from the entry it cancels', function () {
    $entry = $this->ledger->post('Sale', [Line::debit($this->cash, 200), Line::credit($this->sales, 200)], postedAt: Carbon::parse('2026-10-10'));

    $this->ledger->reverse($entry, postedAt: Carbon::parse('2026-11-05'));

    expect($this->cash->balance(Carbon::parse('2026-10-31')))->toBe(200)
        ->and($this->cash->balance(Carbon::parse('2026-11-30')))->toBe(0);
});

it('treats a retry with the same date as the same posting', function () {
    $lines = fn () => [Line::debit($this->cash, 100), Line::credit($this->sales, 100)];
    $date = Carbon::parse('2026-11-01');

    $first = $this->ledger->post('Order 9', $lines(), 'order-9', $date);
    $retry = $this->ledger->post('Order 9', $lines(), 'order-9', $date);

    expect($retry->id)->toBe($first->id);
});

it('produces a trial balance whose debits equal its credits', function () {
    $this->ledger->post('Sale', [Line::debit($this->cash, 1000), Line::credit($this->sales, 1000)]);
    $this->ledger->post('Rent', [Line::debit($this->rent, 400), Line::credit($this->cash, 400)]);

    $tb = $this->ledger->trialBalance('USD');

    expect($tb['rows'])->toBe([
        ['code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'debit' => 600, 'credit' => 0],
        ['code' => '4000', 'name' => 'Sales', 'type' => 'income', 'debit' => 0, 'credit' => 1000],
        ['code' => '5000', 'name' => 'Rent', 'type' => 'expense', 'debit' => 400, 'credit' => 0],
    ])->and($tb['debits'])->toBe(1000)
        ->and($tb['credits'])->toBe(1000);
});

it('leaves out accounts with no balance and other currencies', function () {
    $euros = Account::create(['code' => '1010', 'name' => 'Euro cash', 'type' => AccountType::Asset, 'currency' => 'EUR']);
    $euroSales = Account::create(['code' => '4010', 'name' => 'Euro sales', 'type' => AccountType::Income, 'currency' => 'EUR']);
    $this->ledger->post('EUR sale', [Line::debit($euros, 50), Line::credit($euroSales, 50)]);

    expect($this->ledger->trialBalance('USD'))->toBe(['rows' => [], 'debits' => 0, 'credits' => 0]);
});

it('produces a trial balance as of a past date', function () {
    $this->ledger->post('October sale', [Line::debit($this->cash, 500), Line::credit($this->sales, 500)], postedAt: Carbon::parse('2026-10-15'));
    $this->ledger->post('November sale', [Line::debit($this->cash, 300), Line::credit($this->sales, 300)]);

    expect($this->ledger->trialBalance('USD', Carbon::parse('2026-10-31'))['debits'])->toBe(500);
});

it('prints the trial balance from artisan', function () {
    $this->ledger->post('Sale', [Line::debit($this->cash, 125050), Line::credit($this->sales, 125050)], postedAt: Carbon::parse('2026-10-15'));
    $this->ledger->post('Later sale', [Line::debit($this->cash, 1), Line::credit($this->sales, 1)]);

    $this->artisan('ledger:trial-balance', ['currency' => 'usd', '--as-of' => '2026-10-31'])
        ->expectsTable(['Code', 'Account', 'Type', 'Debit', 'Credit'], [
            ['1000', 'Cash', 'asset', '1,250.50', ''],
            ['4000', 'Sales', 'income', '', '1,250.50'],
            ['', 'Total', '', '1,250.50', '1,250.50'],
        ])
        ->expectsOutput('Trial balance for USD as of 2026-10-31: debits equal credits.')
        ->assertExitCode(0);
});

it('fails the command when the books do not balance', function () {
    $entry = $this->ledger->post('Sale', [Line::debit($this->cash, 100), Line::credit($this->sales, 100)]);
    DB::table('ledger_entry_lines')->insert(['journal_entry_id' => $entry->id, 'account_id' => $this->cash->id, 'debit' => 5, 'credit' => 0]);

    $this->artisan('ledger:trial-balance', ['currency' => 'USD'])
        ->expectsOutput('Trial balance for USD as of 2026-11-30: debits 1.05 and credits 1.00 differ. Run ledger:verify.')
        ->assertExitCode(1);
});

it('leaves out accounts whose activity nets to zero', function () {
    $entry = $this->ledger->post('Sale', [Line::debit($this->cash, 700), Line::credit($this->sales, 700)]);
    $this->ledger->reverse($entry);

    expect($this->ledger->trialBalance('USD')['rows'])->toBe([]);
});

it('prints amounts with the currency\'s own decimal places', function () {
    $yen = Account::create(['code' => '1100', 'name' => 'Yen cash', 'type' => AccountType::Asset, 'currency' => 'JPY']);
    $yenSales = Account::create(['code' => '4100', 'name' => 'Yen sales', 'type' => AccountType::Income, 'currency' => 'JPY']);
    $this->ledger->post('Sale', [Line::debit($yen, 15000), Line::credit($yenSales, 15000)]);

    $this->artisan('ledger:trial-balance', ['currency' => 'JPY'])
        ->expectsTable(['Code', 'Account', 'Type', 'Debit', 'Credit'], [
            ['1100', 'Yen cash', 'asset', '15,000', ''],
            ['4100', 'Yen sales', 'income', '', '15,000'],
            ['', 'Total', '', '15,000', '15,000'],
        ])
        ->assertExitCode(0);
});
