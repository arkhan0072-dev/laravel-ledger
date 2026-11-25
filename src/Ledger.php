<?php

namespace Arkhan\Ledger;

use Arkhan\Ledger\Exceptions\InvalidEntry;
use Arkhan\Ledger\Models\JournalEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class Ledger
{
    /**
     * Record a journal entry. It is stored only if it balances, all in one transaction.
     *
     * With an idempotency key, retrying the same posting returns the stored entry
     * instead of recording it twice.
     *
     * @param  list<Line>  $lines
     */
    public function post(string $description, array $lines, ?string $idempotencyKey = null): JournalEntry
    {
        $currency = $this->validate($description, $lines);
        $description = trim($description);
        $rows = array_map(fn (Line $line) => [
            'account_id' => $line->account->getKey(),
            'debit' => $line->debit,
            'credit' => $line->credit,
        ], $lines);
        $hash = $this->hash($description, $rows);

        try {
            return $this->write($rows, [
                'description' => $description,
                'currency' => $currency,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // The unique index decides races between concurrent retries.
            $existing = JournalEntry::where('idempotency_key', $idempotencyKey)->firstOrFail();
            if ($existing->request_hash !== $hash) {
                throw new InvalidEntry("Idempotency key \"{$idempotencyKey}\" was already used for a different entry.", previous: $e);
            }

            return $existing->load('lines');
        }
    }

    /** Cancel an entry by posting its mirror image. Posted entries are never edited or deleted. */
    public function reverse(JournalEntry $entry, ?string $description = null): JournalEntry
    {
        $rows = $entry->lines->map(fn ($line) => [
            'account_id' => $line->account_id,
            'debit' => $line->credit,
            'credit' => $line->debit,
        ])->all();

        try {
            return $this->write($rows, [
                'description' => $description ?? "Reversal of entry #{$entry->id}",
                'currency' => $entry->currency,
                'reverses_id' => $entry->id,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            throw new InvalidEntry("Entry #{$entry->id} has already been reversed.", previous: $e);
        }
    }

    private function write(array $rows, array $attributes): JournalEntry
    {
        return DB::transaction(function () use ($rows, $attributes) {
            $entry = JournalEntry::create($attributes);
            $entry->lines()->createMany($rows);

            return $entry->load('lines');
        });
    }

    /** Line order does not change an entry's meaning, so it does not change the hash. */
    private function hash(string $description, array $rows): string
    {
        usort($rows, fn ($a, $b) => [$a['account_id'], $a['debit'], $a['credit']] <=> [$b['account_id'], $b['debit'], $b['credit']]);

        return hash('sha256', json_encode([$description, $rows]));
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
