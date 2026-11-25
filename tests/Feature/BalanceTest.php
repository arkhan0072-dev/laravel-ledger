<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;

function make(string $code, AccountType $type): Account
{
    return Account::create(['code' => $code, 'name' => $code, 'type' => $type, 'currency' => 'USD']);
}

it('starts every account at zero', function () {
    expect(make('1000', AccountType::Asset)->balance())->toBe(0);
});

it('reports balances on each account type\'s normal side', function () {
    $ledger = app(Ledger::class);
    $cash = make('1000', AccountType::Asset);
    $loan = make('2000', AccountType::Liability);
    $capital = make('3000', AccountType::Equity);
    $sales = make('4000', AccountType::Income);
    $rent = make('5000', AccountType::Expense);

    $ledger->post('Owner investment', [Line::debit($cash, 10_000), Line::credit($capital, 10_000)]);
    $ledger->post('Bank loan', [Line::debit($cash, 5_000), Line::credit($loan, 5_000)]);
    $ledger->post('Sales', [Line::debit($cash, 3_000), Line::credit($sales, 3_000)]);
    $ledger->post('Rent', [Line::debit($rent, 2_000), Line::credit($cash, 2_000)]);

    expect($cash->balance())->toBe(16_000)
        ->and($loan->balance())->toBe(5_000)
        ->and($capital->balance())->toBe(10_000)
        ->and($sales->balance())->toBe(3_000)
        ->and($rent->balance())->toBe(2_000);

    // Assets = Liabilities + Equity + (Income - Expenses)
    expect($cash->balance())->toBe(
        $loan->balance() + $capital->balance() + $sales->balance() - $rent->balance()
    );
});

it('goes negative when an account is overdrawn', function () {
    $cash = make('1000', AccountType::Asset);
    $rent = make('5000', AccountType::Expense);

    app(Ledger::class)->post('Rent', [Line::debit($rent, 700), Line::credit($cash, 700)]);

    expect($cash->balance())->toBe(-700);
});

it('knows which side increases each account type', function (AccountType $type, bool $debitNormal) {
    expect($type->isDebitNormal())->toBe($debitNormal);
})->with([
    [AccountType::Asset, true],
    [AccountType::Expense, true],
    [AccountType::Liability, false],
    [AccountType::Equity, false],
    [AccountType::Income, false],
]);
