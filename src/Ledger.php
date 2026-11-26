<?php

namespace Arkhan\Ledger;

use Arkhan\Ledger\Events\EntryPosted;
use Arkhan\Ledger\Events\EntryReversed;
use Arkhan\Ledger\Exceptions\InvalidEntry;
use Arkhan\Ledger\Models\Account;
use Arkhan\Ledger\Models\EntryLine;
use Arkhan\Ledger\Models\JournalEntry;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * @phpstan-type Row array{account_id: int, debit: int, credit: int}
 */
class Ledger
{
    /**
     * Record a journal entry. It is stored only if it balances, all in one transaction.
     *
     * With an idempotency key, retrying the same posting returns the stored entry
     * instead of recording it twice. $postedAt is the accounting date (default: now).
     * $reference links the entry to the record it books, e.g. an order or payment.
     *
     * @param  list<Line>  $lines
     */
    public function post(string $description, array $lines, ?string $idempotencyKey = null, ?DateTimeInterface $postedAt = null, ?Model $reference = null): JournalEntry
    {
        $currency = $this->validate($description, $lines);
        $description = trim($description);
        $rows = array_map(fn (Line $line) => [
            'account_id' => $line->account->id,
            'debit' => $line->debit,
            'credit' => $line->credit,
        ], $lines);
        $hash = $this->hash($description, $rows, $postedAt, $reference);

        try {
            $entry = $this->write($rows, [
                'description' => $description,
                'currency' => $currency,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
                'posted_at' => $postedAt ?? now(),
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // The unique index decides races between concurrent retries.
            $existing = JournalEntry::where('idempotency_key', $idempotencyKey)->firstOrFail();
            if ($existing->request_hash !== $hash) {
                throw new InvalidEntry("Idempotency key \"{$idempotencyKey}\" was already used for a different entry.", previous: $e);
            }

            return $existing->load('lines');
        }

        event(new EntryPosted($entry));

        return $entry;
    }

    /**
     * Move $amount out of one account and into another, each on its own normal side,
     * e.g. wallet to wallet or cash to bank.
     */
    public function transfer(string $description, Account $from, Account $to, int $amount, ?string $idempotencyKey = null, ?DateTimeInterface $postedAt = null, ?Model $reference = null): JournalEntry
    {
        if ($from->type->isDebitNormal() !== $to->type->isDebitNormal()) {
            throw new InvalidEntry("Cannot transfer between {$from->type->value} and {$to->type->value} accounts; post the entry with explicit lines instead.");
        }
        $lines = $to->type->isDebitNormal()
            ? [Line::debit($to, $amount), Line::credit($from, $amount)]
            : [Line::debit($from, $amount), Line::credit($to, $amount)];

        return $this->post($description, $lines, $idempotencyKey, $postedAt, $reference);
    }

    /**
     * Exchange money between currencies: one balanced entry in each currency, both or neither.
     * Each side goes through that currency's "FX-<code>" exchange account, opened on first use,
     * whose balances are what fxGainLoss() values.
     *
     * @return array{JournalEntry, JournalEntry} the entry in $from's currency, then the one in $to's
     */
    public function convert(string $description, Account $from, int $fromAmount, Account $to, int $toAmount, ?string $idempotencyKey = null, ?DateTimeInterface $postedAt = null): array
    {
        if ($from->currency === $to->currency) {
            throw new InvalidEntry("A conversion needs two different currencies, got {$from->currency} and {$to->currency}.");
        }
        $sold = Line::credit($from, $fromAmount);
        $bought = Line::debit($to, $toAmount);

        return DB::transaction(fn () => [
            $this->post($description, [$sold, Line::debit($this->exchangeAccount($from->currency), $fromAmount)], $idempotencyKey === null ? null : "{$idempotencyKey}:sold", $postedAt),
            $this->post($description, [$bought, Line::credit($this->exchangeAccount($to->currency), $toAmount)], $idempotencyKey === null ? null : "{$idempotencyKey}:bought", $postedAt),
        ]);
    }

    /**
     * Gain (positive) or loss (negative) on all conversions, in $base minor units, at today's rates.
     * The exchange accounts hold what conversions gave (debits) and received (credits); valued in one
     * currency, their net is what the exchanges earned or cost.
     *
     * @param  array<string, mixed>  $rates  value of one unit of each currency in $base, e.g. ['EUR' => 1.08]
     */
    public function fxGainLoss(string $base, array $rates, ?DateTimeInterface $asOf = null): int
    {
        $base = strtoupper($base);
        $value = 0.0;

        foreach (Account::where('code', 'like', 'FX-%')->get() as $exchange) {
            $currency = $exchange->currency;
            $given = -$exchange->balance($asOf); // net debit, in $currency minor units
            if ($given === 0 || $exchange->code !== "FX-{$currency}") {
                continue;
            }
            $rate = $currency === $base ? 1 : ($rates[$currency] ?? throw new InvalidArgumentException("No {$base} rate for {$currency}."));
            if (! is_numeric($rate) || $rate <= 0) {
                throw new InvalidArgumentException("The {$currency} rate must be a positive number.");
            }
            $value += $given / 10 ** Currency::decimals($currency) * (float) $rate * 10 ** Currency::decimals($base);
        }

        // Rounded once, at the end, so per-currency rounding never adds up.
        return (int) -round($value);
    }

    /** Cancel an entry by posting its mirror image. Posted entries are never edited or deleted. */
    public function reverse(JournalEntry $entry, ?string $description = null, ?DateTimeInterface $postedAt = null): JournalEntry
    {
        $rows = $entry->lines->map(fn (EntryLine $line) => [
            'account_id' => $line->account_id,
            'debit' => $line->credit,
            'credit' => $line->debit,
        ])->all();

        try {
            $reversal = $this->write($rows, [
                'description' => $description ?? "Reversal of entry #{$entry->id}",
                'currency' => $entry->currency,
                'reverses_id' => $entry->id,
                'reference_type' => $entry->reference_type,
                'reference_id' => $entry->reference_id,
                'posted_at' => $postedAt ?? now(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            throw new InvalidEntry("Entry #{$entry->id} has already been reversed.", previous: $e);
        }

        event(new EntryReversed($reversal, $entry));

        return $reversal;
    }

    /**
     * Audit the stored data for anything the API would have refused, e.g. rows written with raw SQL.
     *
     * @return list<string> one message per problem; empty when the ledger is consistent
     */
    public function verify(): array
    {
        $problems = [];

        $entries = DB::table('ledger_journal_entries as e')
            ->leftJoin('ledger_entry_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->groupBy('e.id')
            ->orderBy('e.id')
            ->selectRaw('e.id, count(l.id) as line_count, coalesce(sum(l.debit), 0) as debits, coalesce(sum(l.credit), 0) as credits')
            ->havingRaw('count(l.id) < 2 or coalesce(sum(l.debit), 0) <> coalesce(sum(l.credit), 0)')
            ->get();

        foreach ($entries as $e) {
            /** @var object{id: int, line_count: int, debits: int|string, credits: int|string} $e */
            $problems[] = $e->line_count < 2
                ? "Entry #{$e->id} has {$e->line_count} line(s); at least two are required."
                : "Entry #{$e->id} does not balance: debits {$e->debits}, credits {$e->credits}.";
        }

        $lines = DB::table('ledger_entry_lines as l')
            ->join('ledger_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.account_id')
            ->where(fn (Builder $q) => $q->whereColumn('a.currency', '<>', 'e.currency')
                ->orWhereRaw('(l.debit > 0) = (l.credit > 0)'))
            ->orderBy('l.id')
            ->get(['l.id', 'l.journal_entry_id', 'l.debit', 'l.credit', 'a.currency as account_currency', 'e.currency']);

        foreach ($lines as $l) {
            /** @var object{id: int, journal_entry_id: int, debit: int, credit: int, account_currency: string, currency: string} $l */
            if (($l->debit > 0) === ($l->credit > 0)) {
                $problems[] = "Line #{$l->id} in entry #{$l->journal_entry_id} must have exactly one positive side.";
            }
            if ($l->account_currency !== $l->currency) {
                $problems[] = "Line #{$l->id} in entry #{$l->journal_entry_id} uses {$l->account_currency}, but the entry is in {$l->currency}.";
            }
        }

        return $problems;
    }

    /**
     * Net balance of every account in one currency, placed on the debit or credit side.
     * Total debits equal total credits whenever the stored data is consistent.
     *
     * @return array{rows: list<array{code: string, name: string, type: string, debit: int, credit: int}>, debits: int, credits: int}
     */
    public function trialBalance(string $currency, ?DateTimeInterface $asOf = null): array
    {
        $accounts = DB::table('ledger_accounts as a')
            ->join('ledger_entry_lines as l', 'l.account_id', '=', 'a.id')
            ->join('ledger_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('a.currency', strtoupper($currency))
            ->when($asOf, fn (Builder $q) => $q->where('e.posted_at', '<=', $asOf))
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->orderBy('a.code')
            ->selectRaw('a.code, a.name, a.type, sum(l.debit) - sum(l.credit) as net')
            ->get();

        $rows = [];
        $debits = $credits = 0;
        foreach ($accounts as $a) {
            /** @var object{code: string, name: string, type: string, net: int|string} $a */
            $net = (int) $a->net;
            if ($net === 0) {
                continue;
            }
            $rows[] = ['code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'debit' => max($net, 0), 'credit' => max(-$net, 0)];
            $debits += max($net, 0);
            $credits += max(-$net, 0);
        }

        return ['rows' => $rows, 'debits' => $debits, 'credits' => $credits];
    }

    /** Lock every date up to and including $through: nothing can be posted or reversed into it. */
    public function closePeriod(DateTimeInterface $through): void
    {
        $current = $this->closedThrough();
        if ($current !== null && Carbon::instance($through)->toDateString() < $current->toDateString()) {
            throw new InvalidArgumentException("The books are already closed through {$current->toDateString()}. Use reopenPeriod() to move the date back.");
        }

        $this->recordClose($through);
    }

    /** Move the closing date back to $through, or open every period when null. */
    public function reopenPeriod(?DateTimeInterface $through = null): void
    {
        $current = $this->closedThrough();
        if ($through !== null && ($current === null || Carbon::instance($through)->toDateString() > $current->toDateString())) {
            throw new InvalidArgumentException('Reopening must move the closing date back from '.($current?->toDateString() ?? 'an open ledger').'.');
        }

        $this->recordClose($through);
    }

    /** The last closed date, or null when every period is open. */
    public function closedThrough(): ?Carbon
    {
        $date = DB::table('ledger_period_closes')->orderByDesc('id')->value('closed_through');

        return is_string($date) ? Carbon::parse($date)->startOfDay() : null;
    }

    private function recordClose(?DateTimeInterface $through): void
    {
        DB::table('ledger_period_closes')->insert([
            'closed_through' => $through === null ? null : Carbon::instance($through)->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function exchangeAccount(string $currency): Account
    {
        // The unique code makes concurrent first conversions share one account.
        return Account::firstOrCreate(
            ['code' => "FX-{$currency}"],
            ['name' => "Currency exchange {$currency}", 'type' => AccountType::Equity, 'currency' => $currency],
        );
    }

    /**
     * @param  array<int, Row>  $rows
     * @param  array<string, mixed>  $attributes
     */
    private function write(array $rows, array $attributes): JournalEntry
    {
        return DB::transaction(function () use ($rows, $attributes) {
            // Lock accounts with a minimum before reading their balance, in id order so concurrent postings
            // queue instead of deadlocking; a second spend waits and then sees the first one's lines.
            $net = [];
            foreach ($rows as $row) {
                $net[$row['account_id']] = ($net[$row['account_id']] ?? 0) + $row['debit'] - $row['credit'];
            }
            $guarded = Account::whereKey(array_keys($net))->whereNotNull('min_balance')->orderBy('id')->lockForUpdate()->get()
                ->filter(fn (Account $a) => $a->type->isDebitNormal() ? $net[$a->id] < 0 : $net[$a->id] > 0);

            // Insert first: a retry of an entry stored before the close hits the unique key and gets it back.
            $entry = JournalEntry::create($attributes);
            // shortcut: a posting racing closePeriod() on another connection can still land in the period
            // being closed; lock the latest close row here if month-end runs alongside live postings.
            $closed = $this->closedThrough();
            if ($closed !== null && $entry->posted_at->toDateString() <= $closed->toDateString()) {
                throw new InvalidEntry("The books are closed through {$closed->toDateString()}; date this entry after that.");
            }
            $entry->lines()->createMany($rows);

            foreach ($guarded as $account) {
                $balance = $account->balance();
                if ($balance < (int) $account->min_balance) {
                    $format = fn (int $minor) => Currency::format($minor, $account->currency)." {$account->currency}";
                    throw new InvalidEntry("Insufficient funds in {$account->name}: the balance would be {$format($balance)}, below the minimum of {$format((int) $account->min_balance)}.");
                }
            }

            return $entry->load('lines');
        });
    }

    /**
     * Line order does not change an entry's meaning, so it does not change the hash.
     * The date counts only when the caller gave one; a default "now" would differ on every retry.
     * The reference counts only when given, so hashes stored before references existed still match.
     *
     * @param  array<int, Row>  $rows
     */
    private function hash(string $description, array $rows, ?DateTimeInterface $postedAt, ?Model $reference): string
    {
        usort($rows, fn ($a, $b) => [$a['account_id'], $a['debit'], $a['credit']] <=> [$b['account_id'], $b['debit'], $b['credit']]);

        $parts = [$description, $rows, $postedAt?->format(DATE_ATOM)];
        if ($reference !== null) {
            $parts[] = [$reference->getMorphClass(), $reference->getKey()];
        }

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<mixed>  $lines  checked at runtime: callers may pass anything
     * @return string the entry's currency
     */
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

        return (string) $currency; // non-null: there are at least two lines
    }
}
