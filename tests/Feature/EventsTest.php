<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Events\EntryPosted;
use Arkhan\Ledger\Events\EntryReversed;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->ledger = app(Ledger::class);
    $this->cash = Account::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset, 'currency' => 'USD']);
    $this->sales = Account::create(['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income, 'currency' => 'USD']);
    $this->lines = fn (int $amount = 100) => [Line::debit($this->cash, $amount), Line::credit($this->sales, $amount)];

    $this->seen = [];
    Event::listen(EntryPosted::class, fn (EntryPosted $e) => $this->seen[] = ['posted', $e->entry->id]);
    Event::listen(EntryReversed::class, fn (EntryReversed $e) => $this->seen[] = ['reversed', $e->reversal->id, $e->original->id]);
});

it('announces a posted entry', function () {
    $entry = $this->ledger->post('Sale', ($this->lines)());

    expect($this->seen)->toBe([['posted', $entry->id]]);
});

it('announces a retried posting only once', function () {
    $entry = $this->ledger->post('Order 5', ($this->lines)(), 'order-5');
    $this->ledger->post('Order 5', ($this->lines)(), 'order-5');

    expect($this->seen)->toBe([['posted', $entry->id]]);
});

it('announces a reversal with the entry it cancels', function () {
    $entry = $this->ledger->post('Sale', ($this->lines)());
    $reversal = $this->ledger->reverse($entry);

    expect($this->seen)->toBe([['posted', $entry->id], ['reversed', $reversal->id, $entry->id]]);
});

it('stays silent when the surrounding transaction rolls back', function () {
    try {
        DB::transaction(function () {
            $this->ledger->post('Sale', ($this->lines)());

            throw new RuntimeException('order failed');
        });
    } catch (RuntimeException) {
    }

    expect($this->seen)->toBe([]);
});

it('waits for the surrounding transaction to commit', function () {
    DB::transaction(function () {
        $this->ledger->post('Sale', ($this->lines)());

        expect($this->seen)->toBe([]);
    });

    expect($this->seen)->toHaveCount(1);
});
