<?php

namespace Arkhan\Ledger;

use Arkhan\Ledger\Exceptions\InvalidEntry;
use Arkhan\Ledger\Models\Account;

/** One side of a journal entry. Amounts are integer minor units (cents), never floats. */
final class Line
{
    private function __construct(
        public readonly Account $account,
        public readonly int $debit,
        public readonly int $credit,
    ) {}

    public static function debit(Account $account, int $amount): self
    {
        return new self($account, self::positive($amount), 0);
    }

    public static function credit(Account $account, int $amount): self
    {
        return new self($account, 0, self::positive($amount));
    }

    /** @internal also validates hold amounts */
    public static function positive(int $amount): int
    {
        if ($amount <= 0) {
            throw new InvalidEntry('Line amounts must be positive integers (minor units).');
        }

        return $amount;
    }
}
