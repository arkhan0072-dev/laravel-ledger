<?php

namespace Arkhan\Ledger;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    /** Assets and expenses grow with debits; the others grow with credits. */
    public function isDebitNormal(): bool
    {
        return $this === self::Asset || $this === self::Expense;
    }
}
