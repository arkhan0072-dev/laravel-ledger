# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.4.0] - 2026-11-26

### Added

- `min_balance` on accounts: postings, reversals, transfers and captures that would take an account below it are refused. Money coming in is never refused. The accounts are locked `FOR UPDATE` before the check, so concurrent spends queue.
- Holds: `Ledger::hold()`, `capture()` (all or part) and `release()`, with optional expiry, and `Account::availableBalance()`, which excludes held money.
- `Ledger::transfer()`: move an amount between two accounts that grow on the same side, in one call.
- Entry references: `post()` and `transfer()` take a `reference` model (an order, invoice or payment); reversals keep it. It is part of the idempotency check when given.

### Upgrading

Run `php artisan migrate` (adds `min_balance`, `reference` and `ledger_holds`). No code changes are needed: existing accounts have no minimum, and stored idempotency hashes still match.

## [0.3.0] - 2026-11-26

### Added

- `Ledger::convert()`: exchange money between currencies as one balanced entry per currency, stored together or not at all, through per-currency `FX-<code>` exchange accounts opened on first use. An idempotency key covers both entries.
- `Ledger::fxGainLoss()`: the gain or loss on all conversions, valued in a base currency at the rates you pass, with each currency's decimal places, optionally as of a date.
- `Ledger::closePeriod()`, `reopenPeriod()` and `closedThrough()`: once a period is closed, postings, reversals and conversions dated in it are refused. Every close and reopen is kept in `ledger_period_closes`.

### Upgrading

Run `php artisan migrate` to create `ledger_period_closes`. No code changes are needed; nothing is locked until you call `closePeriod()`.

## [0.2.1] - 2026-11-26

### Fixed

- Posting on PostgreSQL no longer slows down as the ledger grows. Entry lines are now indexed by journal entry; the commit-time balance check and loading an entry's lines used to scan the whole lines table.
- The MySQL warning shown when triggers cannot be created now gives the exact command that installs them later. Re-running `php artisan migrate` does not, because the migration is already recorded.

### Changed

- Package metadata: clearer description, more keywords, support links, and the author's profile instead of an email address.

### Upgrading

Run `php artisan migrate` to add the index. No code changes are needed.

## [0.2.0] - 2026-11-26

### Added

- Laravel 13 support.
- `HasLedger` trait: any model can own ledger accounts, opened on first use with `ledgerAccount()`.
- `EntryPosted` and `EntryReversed` events, dispatched after the transaction commits.
- Database triggers on MySQL, PostgreSQL and SQLite that refuse `UPDATE` and `DELETE` on posted entries and lines.
- PostgreSQL: a deferred constraint trigger refuses to commit an entry that does not balance or has fewer than two lines.
- `Currency::decimals()` and `Currency::format()`: ISO 4217 decimal places (JPY 0, KWD 3) used by `ledger:trial-balance`.
- README recipes (wallet, marketplace payout, Stripe charge and refund), each run as a test.

### Changed

- Account currency codes are stored upper-case and must be three letters; anything else throws `InvalidArgumentException`.
- `ledger:trial-balance` prints amounts with each currency's own decimal places instead of always two.

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

[Unreleased]: https://github.com/arkhan0072-dev/laravel-ledger/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/arkhan0072-dev/laravel-ledger/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/arkhan0072-dev/laravel-ledger/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/arkhan0072-dev/laravel-ledger/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/arkhan0072-dev/laravel-ledger/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/arkhan0072-dev/laravel-ledger/releases/tag/v0.1.0
