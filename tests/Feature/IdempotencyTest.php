<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Exceptions\InvalidEntry;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;
use Arkhan\Ledger\Models\JournalEntry;

beforeEach(function () {
    $this->ledger = app(Ledger::class);
    $this->cash = Account::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset, 'currency' => 'USD']);
    $this->sales = Account::create(['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income, 'currency' => 'USD']);
    $this->lines = fn (int $amount) => [Line::debit($this->cash, $amount), Line::credit($this->sales, $amount)];
});

it('stores a retried posting only once', function () {
    $first = $this->ledger->post('Order 77 paid', ($this->lines)(500), idempotencyKey: 'order-77');
    $retry = $this->ledger->post('Order 77 paid', ($this->lines)(500), idempotencyKey: 'order-77');

    expect($retry->id)->toBe($first->id)
        ->and($retry->lines)->toHaveCount(2)
        ->and(JournalEntry::count())->toBe(1)
        ->and($this->cash->balance())->toBe(500);
});

it('rejects a reused key with a different entry', function () {
    $this->ledger->post('Order 77 paid', ($this->lines)(500), idempotencyKey: 'order-77');
    $this->ledger->post('Order 77 paid', ($this->lines)(900), idempotencyKey: 'order-77');
})->throws(InvalidEntry::class, 'Idempotency key "order-77" was already used for a different entry.');

it('treats the same lines in another order as the same entry', function () {
    $first = $this->ledger->post('Order 78', ($this->lines)(300), idempotencyKey: 'order-78');
    $retry = $this->ledger->post('Order 78', array_reverse(($this->lines)(300)), idempotencyKey: 'order-78');

    expect($retry->id)->toBe($first->id);
});

it('stores postings without a key every time', function () {
    $this->ledger->post('Cash sale', ($this->lines)(100));
    $this->ledger->post('Cash sale', ($this->lines)(100));

    expect(JournalEntry::count())->toBe(2)
        ->and($this->cash->balance())->toBe(200);
});
