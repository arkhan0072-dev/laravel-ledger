<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Exceptions\InvalidEntry;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;

beforeEach(function () {
    $this->account = new Account(['code' => '1000', 'name' => 'Cash', 'type' => AccountType::Asset, 'currency' => 'USD']);
});

it('builds debit and credit lines', function () {
    $debit = Line::debit($this->account, 150);
    $credit = Line::credit($this->account, 150);

    expect([$debit->debit, $debit->credit])->toBe([150, 0])
        ->and([$credit->debit, $credit->credit])->toBe([0, 150])
        ->and($debit->account)->toBe($this->account);
});

it('rejects zero and negative amounts', function (int $amount) {
    Line::debit($this->account, $amount);
})->with([0, -1, -500])->throws(InvalidEntry::class, 'Line amounts must be positive integers (minor units).');
