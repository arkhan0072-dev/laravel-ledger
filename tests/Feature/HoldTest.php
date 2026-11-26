<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Exceptions\InvalidEntry;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;
use Arkhan\Ledger\Models\Hold;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-11-26 10:00');
    $this->ledger = app(Ledger::class);
    $cash = Account::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset, 'currency' => 'USD']);
    $this->wallet = Account::create(['code' => '2000', 'name' => 'Wallet', 'type' => AccountType::Liability, 'currency' => 'USD', 'min_balance' => 0]);
    $this->merchant = Account::create(['code' => '2100', 'name' => 'Merchant payable', 'type' => AccountType::Liability, 'currency' => 'USD']);
    $this->ledger->post('Top-up', [Line::debit($cash, 5_000), Line::credit($this->wallet, 5_000)]);
});

afterEach(fn () => Carbon::setTestNow());

it('reserves money without moving it', function () {
    $hold = $this->ledger->hold($this->wallet, 2_000, 'Ride #77');

    expect($hold)->toBeInstanceOf(Hold::class)
        ->and($hold->status)->toBe('active')
        ->and($this->wallet->balance())->toBe(5_000)
        ->and($this->wallet->availableBalance())->toBe(3_000);
});

it('refuses a hold larger than the available balance', function () {
    $this->ledger->hold($this->wallet, 2_000, 'Ride #77');

    $this->ledger->hold($this->wallet, 3_001, 'Ride #78');
})->throws(InvalidEntry::class, 'Insufficient funds in Wallet: 30.00 USD available, 30.01 USD requested.');

it('keeps held money from being spent elsewhere', function () {
    $this->ledger->hold($this->wallet, 4_000, 'Ride #77');

    $this->ledger->transfer('Other purchase', $this->wallet, $this->merchant, 1_001);
})->throws(InvalidEntry::class, 'Insufficient funds in Wallet');

it('captures the full amount as a transfer', function () {
    $hold = $this->ledger->hold($this->wallet, 2_000, 'Ride #77');

    $entry = $this->ledger->capture($hold, $this->merchant);

    expect($entry->description)->toBe('Ride #77')
        ->and($hold->fresh()->status)->toBe('captured')
        ->and($hold->fresh()->journal_entry_id)->toBe($entry->id)
        ->and($this->wallet->balance())->toBe(3_000)
        ->and($this->wallet->availableBalance())->toBe(3_000)
        ->and($this->merchant->balance())->toBe(2_000);
});

it('captures less than held and frees the rest', function () {
    $hold = $this->ledger->hold($this->wallet, 2_000, 'Ride #77');

    $this->ledger->capture($hold, $this->merchant, 1_450, 'Ride #77, final fare');

    expect($this->wallet->balance())->toBe(3_550)->and($this->wallet->availableBalance())->toBe(3_550);
});

it('refuses to capture more than held', function () {
    $hold = $this->ledger->hold($this->wallet, 2_000, 'Ride #77');

    $this->ledger->capture($hold, $this->merchant, 2_001);
})->throws(InvalidEntry::class, 'Cannot capture 20.01 USD from a hold of 20.00 USD.');

it('releases a hold', function () {
    $hold = $this->ledger->hold($this->wallet, 2_000, 'Ride #77');

    $this->ledger->release($hold);

    expect($hold->fresh()->status)->toBe('released')->and($this->wallet->availableBalance())->toBe(5_000);
});

it('settles a hold only once', function (string $first, string $second, string $message) {
    $hold = $this->ledger->hold($this->wallet, 2_000, 'Ride #77');
    $settle = fn (string $how) => $how === 'capture' ? $this->ledger->capture($hold, $this->merchant) : $this->ledger->release($hold);

    $settle($first);
    expect(fn () => $settle($second))->toThrow(InvalidEntry::class, $message);
})->with([
    ['capture', 'capture', 'was already captured'],
    ['capture', 'release', 'was already captured'],
    ['release', 'capture', 'was already released'],
    ['release', 'release', 'was already released'],
]);

it('stops counting and refuses capture once expired', function () {
    $hold = $this->ledger->hold($this->wallet, 2_000, 'Ride #77', Carbon::parse('2026-11-27 10:00'));

    Carbon::setTestNow('2026-11-27 10:00');

    expect($this->wallet->availableBalance())->toBe(5_000)
        ->and(fn () => $this->ledger->capture($hold, $this->merchant))->toThrow(InvalidEntry::class, 'expired at 2026-11-27 10:00');
});

it('allows holds on accounts without a minimum', function () {
    $this->ledger->hold($this->merchant, 99_999, 'Pre-authorised refund');

    expect($this->merchant->availableBalance())->toBe(-99_999);
});

it('refuses a hold that is not a positive amount', function () {
    $this->ledger->hold($this->wallet, 0, 'Nothing');
})->throws(InvalidEntry::class, 'Line amounts must be positive integers (minor units).');
