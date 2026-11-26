<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;
use Arkhan\Ledger\Tests\Fixtures\Shop;

beforeEach(function () {
    $this->shop = Shop::create(['name' => 'Corner Shop']);
});

it('opens an account for its owner the first time and reuses it after', function () {
    $wallet = $this->shop->ledgerAccount('Wallet', AccountType::Liability, 'USD');

    expect($this->shop->ledgerAccount('Wallet', AccountType::Liability, 'USD')->id)->toBe($wallet->id)
        ->and($wallet->name)->toBe('Wallet')
        ->and($wallet->type)->toBe(AccountType::Liability)
        ->and($wallet->currency)->toBe('USD')
        ->and(Account::count())->toBe(1);
});

it('keeps one account per name and currency', function () {
    $usd = $this->shop->ledgerAccount('Wallet', AccountType::Liability, 'USD');
    $eur = $this->shop->ledgerAccount('Wallet', AccountType::Liability, 'EUR');

    expect($eur->id)->not->toBe($usd->id)
        ->and($this->shop->ledgerAccount('Wallet', AccountType::Liability, 'usd')->id)->toBe($usd->id);
});

it('gives each owner its own accounts', function () {
    $other = Shop::create(['name' => 'Other Shop']);

    expect($other->ledgerAccount('Wallet', AccountType::Liability, 'USD')->id)
        ->not->toBe($this->shop->ledgerAccount('Wallet', AccountType::Liability, 'USD')->id);
});

it('links accounts and owners both ways', function () {
    $wallet = $this->shop->ledgerAccount('Wallet', AccountType::Liability, 'USD');
    $this->shop->ledgerAccount('Fees', AccountType::Expense, 'USD');
    Account::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset, 'currency' => 'USD']);

    expect($wallet->owner)->toBeInstanceOf(Shop::class)
        ->and($wallet->owner->is($this->shop))->toBeTrue()
        ->and($this->shop->ledgerAccounts()->pluck('name')->sort()->values()->all())->toBe(['Fees', 'Wallet']);
});

it('refuses to reopen an account under a different type', function () {
    $this->shop->ledgerAccount('Wallet', AccountType::Liability, 'USD');
    $this->shop->ledgerAccount('Wallet', AccountType::Asset, 'USD');
})->throws(InvalidArgumentException::class, 'already exists as liability');

it('posts to owned accounts like any other', function () {
    $cash = Account::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset, 'currency' => 'USD']);
    $wallet = $this->shop->ledgerAccount('Wallet', AccountType::Liability, 'USD');

    app(Ledger::class)->post('Top-up', [Line::debit($cash, 2_500), Line::credit($wallet, 2_500)]);

    expect($this->shop->ledgerAccount('Wallet', AccountType::Liability, 'USD')->balance())->toBe(2_500);
});

it('refuses to open an account for an owner that is not saved yet', function () {
    (new Shop(['name' => 'Draft']))->ledgerAccount('Wallet', AccountType::Liability, 'USD');
})->throws(LogicException::class, 'Save the owner before opening ledger accounts for it.');
