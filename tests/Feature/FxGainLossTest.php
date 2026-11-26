<?php

use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->ledger = app(Ledger::class);
    $this->usd = Account::create(['code' => '1000', 'name' => 'Cash USD', 'type' => AccountType::Asset, 'currency' => 'USD']);
    $this->eur = Account::create(['code' => '1001', 'name' => 'Cash EUR', 'type' => AccountType::Asset, 'currency' => 'EUR']);
    // Paid $100.00 for 92.00 EUR.
    $this->ledger->convert('Buy EUR', $this->usd, 10_000, $this->eur, 9_200, postedAt: Carbon::parse('2026-10-15'));
});

it('is zero while rates have not moved', function () {
    expect($this->ledger->fxGainLoss('USD', ['EUR' => 10_000 / 9_200]))->toBe(0);
});

it('reports a gain when the bought currency rose', function () {
    // 92.00 EUR at 1.10 is now $101.20.
    expect($this->ledger->fxGainLoss('USD', ['EUR' => 1.10]))->toBe(120);
});

it('reports a loss when the bought currency fell', function () {
    // 92.00 EUR at 1.05 is now $96.60.
    expect($this->ledger->fxGainLoss('USD', ['EUR' => '1.05']))->toBe(-340);
});

it('values in any base currency', function () {
    // The $100.00 paid is now 90.00 EUR, for the 92.00 EUR received.
    expect($this->ledger->fxGainLoss('EUR', ['USD' => 0.9]))->toBe(200);
});

it('respects each currency\'s decimal places', function () {
    $jpy = Account::create(['code' => '1002', 'name' => 'Cash JPY', 'type' => AccountType::Asset, 'currency' => 'JPY']);
    $this->ledger->convert('Buy JPY', $this->usd, 10_000, $jpy, 15_000);

    // 15,000 JPY at 0.0065 is $97.50 for $100.00 paid; the EUR purchase is flat at 100/92.
    expect($this->ledger->fxGainLoss('USD', ['EUR' => 10_000 / 9_200, 'JPY' => 0.0065]))->toBe(-250);
});

it('nets conversions in both directions', function () {
    // Sold the 92.00 EUR back for $101.20: the gain is now realised in cash, and nothing is left to revalue.
    $this->ledger->convert('Sell EUR', $this->eur, 9_200, $this->usd, 10_120);

    expect($this->ledger->fxGainLoss('USD', ['EUR' => 1.50]))->toBe(120)
        ->and($this->usd->balance())->toBe(120);
});

it('values the books as of a date', function () {
    $this->ledger->convert('Buy more EUR', $this->usd, 10_000, $this->eur, 9_000, postedAt: Carbon::parse('2026-11-10'));

    expect($this->ledger->fxGainLoss('USD', ['EUR' => 1.10], Carbon::parse('2026-10-31')))->toBe(120);
});

it('needs a rate for every currency it holds', function () {
    $this->ledger->fxGainLoss('USD', []);
})->throws(InvalidArgumentException::class, 'No USD rate for EUR.');

it('refuses a rate that is not a positive number', function (mixed $rate) {
    $this->ledger->fxGainLoss('USD', ['EUR' => $rate]);
})->with(['abc', 0, -1.1])->throws(InvalidArgumentException::class, 'The EUR rate must be a positive number.');

it('ignores your own accounts that happen to start with FX-', function () {
    $fees = Account::create(['code' => 'FX-FEES', 'name' => 'FX fees', 'type' => AccountType::Expense, 'currency' => 'USD']);
    $this->ledger->post('Bank FX fee', [Line::debit($fees, 300), Line::credit($this->usd, 300)]);

    expect($this->ledger->fxGainLoss('USD', ['EUR' => 1.10]))->toBe(120);
});
