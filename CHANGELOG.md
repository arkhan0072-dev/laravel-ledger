# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

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
