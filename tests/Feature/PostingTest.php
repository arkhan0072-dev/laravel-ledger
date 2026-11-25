<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Exceptions\InvalidEntry;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;
use Arkhan\Ledger\Models\JournalEntry;

function account(string $code, AccountType $type, string $currency = 'USD'): Account
{
    return Account::create(['code' => $code, 'name' => "Account $code", 'type' => $type, 'currency' => $currency]);
}

beforeEach(function () {
    $this->ledger = app(Ledger::class);
    $this->cash = account('1000', AccountType::Asset);
    $this->revenue = account('4000', AccountType::Income);
});

it('posts a balanced entry with its lines', function () {
    $entry = $this->ledger->post('Invoice #1 paid', [
        Line::debit($this->cash, 2500),
        Line::credit($this->revenue, 2500),
    ]);

    expect($entry)->toBeInstanceOf(JournalEntry::class)
        ->and($entry->description)->toBe('Invoice #1 paid')
        ->and($entry->currency)->toBe('USD')
        ->and($entry->lines)->toHaveCount(2)
        ->and($entry->lines->sum('debit'))->toBe(2500)
        ->and($entry->lines->sum('credit'))->toBe(2500);
});

it('splits one side across several accounts', function () {
    $tax = account('2100', AccountType::Liability);

    $this->ledger->post('Sale with tax', [
        Line::debit($this->cash, 1200),
        Line::credit($this->revenue, 1000),
        Line::credit($tax, 200),
    ]);

    expect($this->cash->balance())->toBe(1200)
        ->and($this->revenue->balance())->toBe(1000)
        ->and($tax->balance())->toBe(200);
});

it('rejects an unbalanced entry and stores nothing', function () {
    $this->ledger->post('Broken', [
        Line::debit($this->cash, 1000),
        Line::credit($this->revenue, 999),
    ]);
})->throws(InvalidEntry::class, 'Debits (1000) must equal credits (999).');

it('leaves the database untouched when an entry is rejected', function () {
    try {
        $this->ledger->post('Broken', [Line::debit($this->cash, 1), Line::credit($this->revenue, 2)]);
    } catch (InvalidEntry) {
    }

    expect(JournalEntry::count())->toBe(0);
});

it('rejects an entry with fewer than two lines', function () {
    $this->ledger->post('One line', [Line::debit($this->cash, 1000)]);
})->throws(InvalidEntry::class, 'An entry needs at least two lines.');

it('rejects lines in different currencies', function () {
    $euros = account('1010', AccountType::Asset, 'EUR');

    $this->ledger->post('Mixed', [Line::debit($euros, 1000), Line::credit($this->revenue, 1000)]);
})->throws(InvalidEntry::class, 'All lines must use the same currency, got EUR and USD.');

it('rejects an empty description', function () {
    $this->ledger->post('  ', [Line::debit($this->cash, 1), Line::credit($this->revenue, 1)]);
})->throws(InvalidEntry::class, 'An entry needs a description.');

it('rejects anything that is not a Line', function () {
    $this->ledger->post('Bad', [Line::debit($this->cash, 1), ['credit' => 1]]);
})->throws(InvalidEntry::class, 'Every line must be an instance of '.Line::class.'.');
