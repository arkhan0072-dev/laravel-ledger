<?php

namespace Arkhan\Ledger;

use Arkhan\Ledger\Exceptions\InvalidEntry;
use Arkhan\Ledger\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

class Ledger
{
    /**
     * Record a journal entry. It is stored only if it balances, all in one transaction.
     *
     * @param  list<Line>  $lines
     */
    public function post(string $description, array $lines): JournalEntry
    {
        $currency = $this->validate($description, $lines);

        return DB::transaction(function () use ($description, $lines, $currency) {
            $entry = JournalEntry::create(['description' => trim($description), 'currency' => $currency]);

            $entry->lines()->createMany(array_map(fn (Line $line) => [
                'account_id' => $line->account->getKey(),
                'debit' => $line->debit,
                'credit' => $line->credit,
            ], $lines));

            return $entry->load('lines');
        });
    }

    /** @return string the entry's currency */
    private function validate(string $description, array $lines): string
    {
        if (trim($description) === '') {
            throw new InvalidEntry('An entry needs a description.');
        }
        if (count($lines) < 2) {
            throw new InvalidEntry('An entry needs at least two lines.');
        }

        $debits = $credits = 0;
        $currency = null;

        foreach ($lines as $line) {
            if (! $line instanceof Line) {
                throw new InvalidEntry('Every line must be an instance of '.Line::class.'.');
            }
            $currency ??= $line->account->currency;
            if ($line->account->currency !== $currency) {
                throw new InvalidEntry("All lines must use the same currency, got {$currency} and {$line->account->currency}.");
            }
            $debits += $line->debit;
            $credits += $line->credit;
        }

        if ($debits !== $credits) {
            throw new InvalidEntry("Debits ({$debits}) must equal credits ({$credits}).");
        }

        return $currency;
    }
}
