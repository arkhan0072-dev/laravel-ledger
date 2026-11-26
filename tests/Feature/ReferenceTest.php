<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Exceptions\InvalidEntry;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;
use Arkhan\Ledger\Models\JournalEntry;
use Arkhan\Ledger\Tests\Fixtures\Shop;

beforeEach(function () {
    $this->ledger = app(Ledger::class);
    $this->cash = Account::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset, 'currency' => 'USD']);
    $this->sales = Account::create(['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income, 'currency' => 'USD']);
    $this->lines = [Line::debit($this->cash, 100), Line::credit($this->sales, 100)];
    $this->order = Shop::create(['name' => 'Order 42']);
});

it('links an entry to the record it books', function () {
    $entry = $this->ledger->post('Order 42 paid', $this->lines, reference: $this->order);

    expect($entry->reference->is($this->order))->toBeTrue()
        ->and(JournalEntry::whereMorphedTo('reference', $this->order)->pluck('id')->all())->toBe([$entry->id]);
});

it('leaves the reference empty when none is given', function () {
    expect($this->ledger->post('Cash sale', $this->lines)->reference)->toBeNull();
});

it('gives a reversal the same reference as the entry it cancels', function () {
    $entry = $this->ledger->post('Order 42 paid', $this->lines, reference: $this->order);

    expect($this->ledger->reverse($entry)->reference->is($this->order))->toBeTrue();
});

it('treats a different reference under the same key as a different posting', function () {
    $this->ledger->post('Order paid', $this->lines, 'pay-1', reference: $this->order);
    $this->ledger->post('Order paid', $this->lines, 'pay-1', reference: Shop::create(['name' => 'Order 43']));
})->throws(InvalidEntry::class, 'already used for a different entry');

it('returns the stored entry to a retry with the same reference', function () {
    $entry = $this->ledger->post('Order paid', $this->lines, 'pay-1', reference: $this->order);

    expect($this->ledger->post('Order paid', $this->lines, 'pay-1', reference: $this->order)->id)->toBe($entry->id);
});
