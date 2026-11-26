<?php

// The README recipes, run as written, so the docs cannot drift from the code.

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;
use Arkhan\Ledger\Tests\Fixtures\Shop;

beforeEach(function () {
    $this->ledger = app(Ledger::class);
    $this->stripe = Account::create(['code' => '1100', 'name' => 'Stripe balance', 'type' => AccountType::Asset, 'currency' => 'USD']);
    $this->sales = Account::create(['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income, 'currency' => 'USD']);
});

it('runs a prepaid wallet', function () {
    $customer = Shop::create(['name' => 'Customer 7']);
    $wallet = $customer->ledgerAccount('Wallet', AccountType::Liability, 'USD');

    $this->ledger->post('Wallet top-up', [Line::debit($this->stripe, 5_000), Line::credit($wallet, 5_000)], idempotencyKey: 'topup-81');
    $this->ledger->post('Order #12 paid from wallet', [Line::debit($wallet, 1_800), Line::credit($this->sales, 1_800)]);

    expect($wallet->balance())->toBe(3_200)
        ->and($this->stripe->balance())->toBe(5_000)
        ->and($this->sales->balance())->toBe(1_800);
});

it('splits a marketplace sale between seller, platform and card fees, then pays the seller', function () {
    $seller = Shop::create(['name' => 'Seller 3']);
    $payable = $seller->ledgerAccount('Payable', AccountType::Liability, 'USD');
    $commission = Account::create(['code' => '4100', 'name' => 'Commission', 'type' => AccountType::Income, 'currency' => 'USD']);
    $fees = Account::create(['code' => '6100', 'name' => 'Card fees', 'type' => AccountType::Expense, 'currency' => 'USD']);
    $bank = Account::create(['code' => '1000', 'name' => 'Bank', 'type' => AccountType::Asset, 'currency' => 'USD']);

    // Customer pays $100.00; Stripe keeps $3.20; the platform keeps 10%.
    $this->ledger->post('Order #55', [
        Line::debit($this->stripe, 9_680),
        Line::debit($fees, 320),
        Line::credit($payable, 9_000),
        Line::credit($commission, 1_000),
    ]);
    $this->ledger->post('Payout to seller 3', [Line::debit($payable, 9_000), Line::credit($bank, 9_000)]);

    expect($payable->balance())->toBe(0)
        ->and($commission->balance())->toBe(1_000)
        ->and($fees->balance())->toBe(320)
        ->and($bank->balance())->toBe(-9_000)
        ->and($this->stripe->balance())->toBe(9_680);
});

it('books a Stripe charge once, however often the webhook arrives, and a partial refund', function () {
    $charge = fn () => $this->ledger->post('Stripe charge ch_3Pq', [Line::debit($this->stripe, 2_500), Line::credit($this->sales, 2_500)], idempotencyKey: 'ch_3Pq');

    $first = $charge();
    $retry = $charge();
    $this->ledger->post('Stripe refund re_9Lm', [Line::debit($this->sales, 1_000), Line::credit($this->stripe, 1_000)], idempotencyKey: 're_9Lm');

    expect($retry->id)->toBe($first->id)
        ->and($this->stripe->balance())->toBe(1_500)
        ->and($this->sales->balance())->toBe(1_500);
});

it('refunds a whole charge by reversing it', function () {
    $charge = $this->ledger->post('Stripe charge ch_4Zx', [Line::debit($this->stripe, 2_500), Line::credit($this->sales, 2_500)], idempotencyKey: 'ch_4Zx');

    $this->ledger->reverse($charge, 'Refund of ch_4Zx');

    expect($this->stripe->balance())->toBe(0)->and($this->sales->balance())->toBe(0);
});

it('authorises a ride, then charges the final fare', function () {
    $rider = Shop::create(['name' => 'Rider 4']);
    $wallet = $rider->ledgerAccount('Wallet', AccountType::Liability, 'USD');
    $wallet->update(['min_balance' => 0]);
    $driver = Shop::create(['name' => 'Driver 2'])->ledgerAccount('Payable', AccountType::Liability, 'USD');
    $this->ledger->post('Top-up', [Line::debit($this->stripe, 3_000), Line::credit($wallet, 3_000)]);

    $hold = $this->ledger->hold($wallet, 2_500, 'Ride 881', now()->addHour());
    $spendable = $wallet->availableBalance();
    $entry = $this->ledger->capture($hold, $driver, 1_840, 'Ride 881, final fare');

    expect($spendable)->toBe(500)
        ->and($wallet->balance())->toBe(1_160)
        ->and($wallet->availableBalance())->toBe(1_160)
        ->and($driver->balance())->toBe(1_840)
        ->and($entry->description)->toBe('Ride 881, final fare');
});
