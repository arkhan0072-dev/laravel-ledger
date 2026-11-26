# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [0.2.0] - 2026-11-26

### Added

- Laravel 13 support.
- `HasLedger` trait: any model can own ledger accounts, opened on first use with `ledgerAccount()`.
- `EntryPosted` and `EntryReversed` events, dispatched after the transaction commits.
- Database triggers on MySQL, PostgreSQL and SQLite that refuse `UPDATE` and `DELETE` on posted entries and lines.
- PostgreSQL: a deferred constraint trigger refuses to commit an entry that does not balance or has fewer than two lines.
- `Currency::decimals()` and `Currency::format()`: ISO 4217 decimal places (JPY 0, KWD 3) used by `ledger:trial-balance`.
- Account currency codes are stored upper-case and must be three letters.
- README recipes (wallet, marketplace payout, Stripe charge and refund), each run as a test.

### Upgrading

Run `php artisan migrate`. On MySQL with binary logging, set `log_bin_trust_function_creators = 1` first, or the triggers are skipped with a logged warning.

## [0.1.0] - 2026-11-26

First release.

### Added

- Accounts of five types (asset, liability, equity, income, expense) with balances on each type's normal side.
- `Ledger::post()`: journal entries that must balance, use one currency and positive integer amounts, written in one transaction.
- Idempotency keys: a retried posting returns the stored entry; reusing a key for a different posting is rejected.
- `Ledger::reverse()`: cancel an entry with its mirror image; posted entries and lines cannot be updated or deleted through Eloquent.
- Accounting dates (`posted_at`) and balances as of a past date.
- `Ledger::trialBalance()` and `php artisan ledger:trial-balance`.
- `Ledger::verify()` and `php artisan ledger:verify` to find data changed outside the package.
- Support for MySQL, PostgreSQL and SQLite on PHP 8.3+ and Laravel 12.

[0.1.0]: https://github.com/arkhan0072-dev/laravel-ledger/releases/tag/v0.1.0

[0.2.0]: https://github.com/arkhan0072-dev/laravel-ledger/releases/tag/v0.2.0
