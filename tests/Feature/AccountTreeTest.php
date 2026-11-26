<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->ledger = app(Ledger::class);
    $make = fn (string $code, string $name, ?Account $parent = null, AccountType $type = AccountType::Asset, string $currency = 'USD') => Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'currency' => $currency, 'parent_id' => $parent?->id]);
    $this->make = $make;
    $this->cash = $make('1000', 'Cash');
    $this->bankA = $make('1010', 'Bank A', $this->cash);
    $this->bankB = $make('1020', 'Bank B', $this->cash);
    $this->savings = $make('1021', 'Bank B savings', $this->bankB);
    $this->sales = $make('4000', 'Sales', null, AccountType::Income);

    $in = fn (Account $a, int $amount, string $date) => $this->ledger->post('Sale', [Line::debit($a, $amount), Line::credit($this->sales, $amount)], postedAt: Carbon::parse($date));
    $in($this->cash, 100, '2026-10-01');
    $in($this->bankA, 2_000, '2026-10-02');
    $in($this->bankB, 300, '2026-10-03');
    $in($this->savings, 40, '2026-11-04');
});

it('rolls children and grandchildren into a parent\'s total', function () {
    expect($this->cash->totalBalance())->toBe(2_440)
        ->and($this->cash->balance())->toBe(100)
        ->and($this->bankB->totalBalance())->toBe(340)
        ->and($this->bankA->totalBalance())->toBe(2_000);
});

it('rolls up as of a date', function () {
    expect($this->cash->totalBalance(Carbon::parse('2026-10-31')))->toBe(2_400);
});

it('links parents and children', function () {
    expect($this->cash->children()->pluck('code')->all())->toBe(['1010', '1020'])
        ->and($this->savings->parent->is($this->bankB))->toBeTrue();
});

it('keeps a child in its parent\'s currency and type', function (string $type, string $currency, string $message) {
    expect(fn () => ($this->make)('1030', 'Odd', $this->cash, AccountType::from($type), $currency))->toThrow(InvalidArgumentException::class, $message);
})->with([
    ['asset', 'EUR', 'Account 1030 must use its parent\'s currency USD, got EUR.'],
    ['liability', 'USD', 'Account 1030 must have its parent\'s type asset, got liability.'],
]);

it('refuses a parent that would create a loop', function () {
    $this->cash->update(['parent_id' => $this->savings->id]);
})->throws(InvalidArgumentException::class, 'Account 1000 cannot be placed under its own descendant 1021.');

it('refuses an account as its own parent', function () {
    $this->bankA->update(['parent_id' => $this->bankA->id]);
})->throws(InvalidArgumentException::class, 'Account 1010 cannot be placed under its own descendant 1010.');
