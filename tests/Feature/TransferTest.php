<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Exceptions\InvalidEntry;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;
use Arkhan\Ledger\Tests\Fixtures\Shop;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->ledger = app(Ledger::class);
    $this->alice = Shop::create(['name' => 'Alice'])->ledgerAccount('Wallet', AccountType::Liability, 'USD');
    $this->bob = Shop::create(['name' => 'Bob'])->ledgerAccount('Wallet', AccountType::Liability, 'USD');
    $this->cash = Account::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset, 'currency' => 'USD']);
    $this->ledger->post('Top-up', [Line::debit($this->cash, 5_000), Line::credit($this->alice, 5_000)]);
});

it('moves money between two wallets', function () {
    $this->ledger->transfer('Alice pays Bob', $this->alice, $this->bob, 1_200);

    expect($this->alice->balance())->toBe(3_800)->and($this->bob->balance())->toBe(1_200);
});

it('moves money between two asset accounts', function () {
    $bank = Account::create(['code' => '1100', 'name' => 'Bank', 'type' => AccountType::Asset, 'currency' => 'USD']);

    $this->ledger->transfer('Deposit cash', $this->cash, $bank, 2_000);

    expect($this->cash->balance())->toBe(3_000)->and($bank->balance())->toBe(2_000);
});

it('passes the key, date and reference through', function () {
    $order = Shop::create(['name' => 'Order 9']);

    $entry = $this->ledger->transfer('Alice pays Bob', $this->alice, $this->bob, 1_200, 'tr-1', Carbon::parse('2026-11-20'), $order);
    $retry = $this->ledger->transfer('Alice pays Bob', $this->alice, $this->bob, 1_200, 'tr-1', Carbon::parse('2026-11-20'), $order);

    expect($retry->id)->toBe($entry->id)
        ->and($entry->posted_at->toDateString())->toBe('2026-11-20')
        ->and($entry->reference->is($order))->toBeTrue()
        ->and($this->bob->balance())->toBe(1_200);
});

it('refuses accounts that grow on different sides', function () {
    $this->ledger->transfer('Odd', $this->cash, $this->alice, 100);
})->throws(InvalidEntry::class, 'Cannot transfer between asset and liability accounts; post the entry with explicit lines instead.');

it('refuses accounts in different currencies', function () {
    $euro = Shop::create(['name' => 'Eve'])->ledgerAccount('Wallet', AccountType::Liability, 'EUR');

    $this->ledger->transfer('Cross', $this->alice, $euro, 100);
})->throws(InvalidEntry::class, 'All lines must use the same currency');
