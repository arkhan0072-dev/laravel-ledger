# Laravel Ledger

Double-entry accounting for Laravel. Every journal entry must balance (debits = credits) or it is rejected before anything touches the database. Money is stored as integer minor units, never floats.

> Work in progress. Implemented so far: accounts, balanced journal entries, account balances.

## Usage

```php
use Arkhan\Ledger\AccountType;
use Arkhan\Ledger\Ledger;
use Arkhan\Ledger\Line;
use Arkhan\Ledger\Models\Account;

$cash  = Account::create(['code' => '1000', 'name' => 'Cash',  'type' => AccountType::Asset,     'currency' => 'USD']);
$sales = Account::create(['code' => '4000', 'name' => 'Sales', 'type' => AccountType::Income,    'currency' => 'USD']);
$tax   = Account::create(['code' => '2100', 'name' => 'VAT',   'type' => AccountType::Liability, 'currency' => 'USD']);

app(Ledger::class)->post('Invoice #1042 paid', [
    Line::debit($cash, 12_000),   // $120.00
    Line::credit($sales, 10_000),
    Line::credit($tax, 2_000),
]);

$cash->balance();  // 12000
$sales->balance(); // 10000
```

An entry is rejected with `Arkhan\Ledger\Exceptions\InvalidEntry` when:

- debits and credits differ,
- it has fewer than two lines,
- its lines use different currencies,
- an amount is zero or negative,
- the description is empty.

## Design decisions

- **Integer minor units.** Floats cannot represent most decimal amounts exactly, so `0.1 + 0.2` style errors never reach the books.
- **Validate first, then write in one transaction.** A rejected entry leaves no rows behind, and a database error mid-write rolls the whole entry back.
- **Balances on the normal side.** Assets and expenses grow with debits; liabilities, equity and income grow with credits. `balance()` returns a positive number on each account's normal side, so the accounting equation reads naturally in code.
- **One currency per entry.** Currency conversion needs exchange-rate accounts and is out of scope for a single entry.

## Testing

```bash
composer install
vendor/bin/pest
```

CI runs the tests on PHP 8.2-8.4 and Laravel 12, against both SQLite and PostgreSQL, and fails below 100% line coverage.

## License

MIT
