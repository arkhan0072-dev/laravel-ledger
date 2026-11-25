<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Exceptions\ImmutableRecord;
use Arkhan\Ledger\Exceptions\InvalidEntry;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;
use Arkhan\Ledger\Models\EntryLine;

beforeEach(function () {
    $this->ledger = app(Ledger::class);
    $this->cash = Account::create(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset, 'currency' => 'USD']);
    $this->sales = Account::create(['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income, 'currency' => 'USD']);
    $this->entry = $this->ledger->post('Invoice #9', [Line::debit($this->cash, 4200), Line::credit($this->sales, 4200)]);
});

it('cancels an entry with an opposite entry', function () {
    $reversal = $this->ledger->reverse($this->entry);

    expect($reversal->reverses_id)->toBe($this->entry->id)
        ->and($reversal->description)->toBe('Reversal of entry #'.$this->entry->id)
        ->and($reversal->currency)->toBe('USD')
        ->and($reversal->lines->pluck('credit', 'account_id')->all())->toBe([$this->cash->id => 4200, $this->sales->id => 0])
        ->and($this->cash->balance())->toBe(0)
        ->and($this->sales->balance())->toBe(0);
});

it('accepts a reason for the reversal', function () {
    expect($this->ledger->reverse($this->entry, 'Customer cancelled')->description)->toBe('Customer cancelled');
});

it('refuses to reverse the same entry twice', function () {
    $this->ledger->reverse($this->entry);

    expect(fn () => $this->ledger->reverse($this->entry))
        ->toThrow(InvalidEntry::class, "Entry #{$this->entry->id} has already been reversed.");
});

it('never edits a posted entry', function () {
    $this->entry->update(['description' => 'Changed']);
})->throws(ImmutableRecord::class, 'Posted ledger records cannot be changed or deleted. Post a reversal instead.');

it('never deletes a posted entry', function () {
    $this->entry->delete();
})->throws(ImmutableRecord::class);

it('never edits a posted line', function () {
    EntryLine::first()->update(['debit' => 1]);
})->throws(ImmutableRecord::class);

it('never deletes a posted line', function () {
    EntryLine::first()->delete();
})->throws(ImmutableRecord::class);
