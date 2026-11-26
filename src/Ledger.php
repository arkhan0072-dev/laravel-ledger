<?php

namespace Arkhan\Ledger;

use Arkhan\Ledger\Events\EntryPosted;
use Arkhan\Ledger\Events\EntryReversed;
use Arkhan\Ledger\Exceptions\InvalidEntry;
use Arkhan\Ledger\Models\Account;
use Arkhan\Ledger\Models\EntryLine;
use Arkhan\Ledger\Models\Hold;
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
 * @phpstan-type Report array{code: string|null, name: string, type: string, amount: int}
 */
class Ledger
{
    /** Idempotency key prefix of year-end closing entries. */
    private const YEAR_END = 'ledger-year-end:';

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

    /** Reserve money on an account without moving it, like a card authorization. */
    public function hold(Account $account, int $amount, string $description, ?DateTimeInterface $expiresAt = null): Hold
    {
        Line::positive($amount);

        return DB::transaction(function () use ($account, $amount, $description, $expiresAt) {
            // Same lock as postings, so a hold and a spend cannot both take the last of the money.
            $locked = Account::whereKey($account->id)->lockForUpdate()->firstOrFail();
            if ($locked->min_balance !== null) {
                $free = $locked->availableBalance() - $locked->min_balance;
                if ($amount > $free) {
                    $format = fn (int $minor) => Currency::format($minor, $locked->currency)." {$locked->currency}";
                    throw new InvalidEntry("Insufficient funds in {$locked->name}: {$format($free)} available, {$format($amount)} requested.");
                }
            }

            return Hold::create(['account_id' => $locked->id, 'status' => 'active', 'amount' => $amount, 'description' => $description, 'expires_at' => $expiresAt]);
        });
    }

    /** Settle a hold by transferring all of it, or less, to $to; any rest is freed. */
    public function capture(Hold $hold, Account $to, ?int $amount = null, ?string $description = null): JournalEntry
    {
        return DB::transaction(function () use ($hold, $to, $amount, $description) {
            $locked = $this->settleable($hold);
            $account = Account::findOrFail($locked->account_id);
            $amount ??= $locked->amount;
            if ($amount > $locked->amount) {
                $currency = $account->currency;
                throw new InvalidEntry('Cannot capture '.Currency::format($amount, $currency)." {$currency} from a hold of ".Currency::format($locked->amount, $currency)." {$currency}.");
            }

            // Mark it first, so the transfer's funds check no longer counts this hold as reserved.
            $locked->update(['status' => 'captured']);
            $entry = $this->transfer($description ?? $locked->description, $account, $to, $amount);
            $locked->update(['journal_entry_id' => $entry->id]);

            return $entry;
        });
    }

    /** Free a hold's money without moving it. */
    public function release(Hold $hold): void
    {
        DB::transaction(fn () => $this->settleable($hold)->update(['status' => 'released']));
    }

    /** The hold, locked, if it can still be captured or released. */
    private function settleable(Hold $hold): Hold
    {
        $locked = Hold::whereKey($hold->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'active') {
            throw new InvalidEntry("Hold #{$locked->id} was already {$locked->status}.");
        }
        if ($locked->isExpired()) {
            throw new InvalidEntry("Hold #{$locked->id} expired at {$locked->expires_at?->format('Y-m-d H:i')}.");
        }

        return $locked;
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
     * Every line on one account between two dates (whole days, both included), with the
     * balance before, after each line and at the end, on the account's normal side.
     *
     * @return array{opening: int, rows: list<array{date: string, entry_id: int, description: string, debit: int, credit: int, balance: int}>, closing: int}
     */
    public function statement(Account $account, ?DateTimeInterface $from = null, ?DateTimeInterface $to = null): array
    {
        $sign = $account->type->isDebitNormal() ? 1 : -1;
        $lines = fn () => DB::table('ledger_entry_lines as l')
            ->join('ledger_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $account->id);
        $start = $from === null ? null : Carbon::instance($from)->startOfDay();

        // Separate sums: MySQL refuses debit - credit per row when the columns are unsigned and the result is negative.
        $opening = $start === null ? 0 : $sign * ((int) $lines()->where('e.posted_at', '<', $start)->sum('l.debit') - (int) $lines()->where('e.posted_at', '<', $start)->sum('l.credit'));

        $balance = $opening;
        $rows = [];
        $found = $lines()
            ->when($start, fn (Builder $q) => $q->where('e.posted_at', '>=', $start))
            ->when($to, fn (Builder $q, DateTimeInterface $to) => $q->where('e.posted_at', '<=', Carbon::instance($to)->endOfDay()))
            ->orderBy('e.posted_at')->orderBy('e.id')->orderBy('l.id')
            ->get(['e.posted_at', 'e.id as entry_id', 'e.description', 'l.debit', 'l.credit']);
        foreach ($found as $line) {
            /** @var object{posted_at: string, entry_id: int|string, description: string, debit: int|string, credit: int|string} $line */
            $balance += $sign * ((int) $line->debit - (int) $line->credit);
            $rows[] = [
                'date' => Carbon::parse($line->posted_at)->toDateString(),
                'entry_id' => (int) $line->entry_id,
                'description' => $line->description,
                'debit' => (int) $line->debit,
                'credit' => (int) $line->credit,
                'balance' => $balance,
            ];
        }

        return ['opening' => $opening, 'rows' => $rows, 'closing' => $balance];
    }

    /**
     * Net balance of every account in one currency, placed on the debit or credit side.
     * Total debits equal total credits whenever the stored data is consistent.
     *
     * @return array{rows: list<array{code: string, name: string, type: string, debit: int, credit: int}>, debits: int, credits: int}
     */
    public function trialBalance(string $currency, ?DateTimeInterface $asOf = null): array
    {
        $rows = [];
        $debits = $credits = 0;
        foreach ($this->netByAccount($currency, null, $asOf) as $a) {
            $rows[] = ['code' => $a['code'], 'name' => $a['name'], 'type' => $a['type'], 'debit' => max($a['net'], 0), 'credit' => max(-$a['net'], 0)];
            $debits += max($a['net'], 0);
            $credits += max(-$a['net'], 0);
        }

        return ['rows' => $rows, 'debits' => $debits, 'credits' => $credits];
    }

    /**
     * Profit and loss in one currency between two dates (whole days, both included; default: all time).
     * Amounts are on each account's normal side.
     *
     * @return array{income: list<Report>, expenses: list<Report>, total_income: int, total_expenses: int, net: int}
     */
    public function incomeStatement(string $currency, ?DateTimeInterface $from = null, ?DateTimeInterface $to = null): array
    {
        // Closing entries move the year's result into equity; counting them would show every closed year as zero.
        $sections = $this->sections($currency, $from, $to === null ? null : Carbon::instance($to)->endOfDay(), withoutYearEnd: true);
        $income = array_sum(array_column($sections['income'], 'amount'));
        $expenses = array_sum(array_column($sections['expense'], 'amount'));

        return ['income' => $sections['income'], 'expenses' => $sections['expense'], 'total_income' => $income, 'total_expenses' => $expenses, 'net' => $income - $expenses];
    }

    /**
     * Assets, liabilities and equity in one currency at the end of $asOf (default: now).
     * Income and expenses not yet closed into equity appear as one equity line, so assets
     * always equal liabilities plus equity.
     *
     * @return array{assets: list<Report>, liabilities: list<Report>, equity: list<Report>, total_assets: int, total_liabilities: int, total_equity: int}
     */
    public function balanceSheet(string $currency, ?DateTimeInterface $asOf = null): array
    {
        $sections = $this->sections($currency, null, $asOf === null ? null : Carbon::instance($asOf)->endOfDay());
        $equity = $sections['equity'];
        $earnings = array_sum(array_column($sections['income'], 'amount')) - array_sum(array_column($sections['expense'], 'amount'));
        if ($earnings !== 0) {
            $equity[] = ['code' => null, 'name' => 'Earnings not yet closed', 'type' => 'equity', 'amount' => $earnings];
        }

        return [
            'assets' => $sections['asset'],
            'liabilities' => $sections['liability'],
            'equity' => $equity,
            'total_assets' => array_sum(array_column($sections['asset'], 'amount')),
            'total_liabilities' => array_sum(array_column($sections['liability'], 'amount')),
            'total_equity' => array_sum(array_column($equity, 'amount')),
        ];
    }

    /**
     * Close a year: one entry, dated $yearEnd, that brings every income and expense account in
     * $currency to zero and puts the net result in $retainedEarnings. Closing the same year again
     * returns that entry; with nothing to close it returns null. Lock the period separately.
     */
    public function closeYear(string $currency, DateTimeInterface $yearEnd, Account $retainedEarnings): ?JournalEntry
    {
        $currency = strtoupper($currency);
        if ($retainedEarnings->type !== AccountType::Equity) {
            throw new InvalidEntry("Retained earnings must be an equity account, got {$retainedEarnings->type->value}.");
        }
        if ($retainedEarnings->currency !== $currency) {
            throw new InvalidEntry("Retained earnings must be in {$currency}, got {$retainedEarnings->currency}.");
        }
        $end = Carbon::instance($yearEnd)->endOfDay();
        $key = self::YEAR_END."{$currency}:{$end->toDateString()}";
        $closed = JournalEntry::where('idempotency_key', $key)->first();
        if ($closed !== null) {
            return $closed->load('lines');
        }

        $results = array_filter($this->netByAccount($currency, null, $end), fn (array $a) => in_array($a['type'], ['income', 'expense'], true));
        if ($results === []) {
            return null;
        }
        $lines = [];
        $net = 0; // debits minus credits across income and expenses: positive is a loss
        foreach ($results as $a) {
            $account = Account::findOrFail($a['id']);
            $lines[] = $a['net'] > 0 ? Line::credit($account, $a['net']) : Line::debit($account, -$a['net']);
            $net += $a['net'];
        }
        if ($net !== 0) {
            $lines[] = $net > 0 ? Line::debit($retainedEarnings, $net) : Line::credit($retainedEarnings, -$net);
        }

        return $this->post("Year-end close {$end->toDateString()}", $lines, $key, $end);
    }

    /**
     * Accounts grouped by type, each amount on its type's normal side.
     *
     * @return array<string, list<Report>> keyed by AccountType value
     */
    private function sections(string $currency, ?DateTimeInterface $from, ?DateTimeInterface $to, bool $withoutYearEnd = false): array
    {
        $sections = array_fill_keys(array_map(fn (AccountType $t) => $t->value, AccountType::cases()), []);
        foreach ($this->netByAccount($currency, $from, $to, $withoutYearEnd) as $a) {
            $type = AccountType::from($a['type']);
            $sections[$a['type']][] = ['code' => $a['code'], 'name' => $a['name'], 'type' => $a['type'], 'amount' => $type->isDebitNormal() ? $a['net'] : -$a['net']];
        }

        return $sections;
    }

    /**
     * Debits minus credits per account in one currency, accounts with activity only, by code.
     *
     * @return list<array{id: int, code: string, name: string, type: string, net: int}>
     */
    private function netByAccount(string $currency, ?DateTimeInterface $from, ?DateTimeInterface $to, bool $withoutYearEnd = false): array
    {
        $accounts = DB::table('ledger_accounts as a')
            ->join('ledger_entry_lines as l', 'l.account_id', '=', 'a.id')
            ->join('ledger_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('a.currency', strtoupper($currency))
            ->when($from, fn (Builder $q, DateTimeInterface $from) => $q->where('e.posted_at', '>=', Carbon::instance($from)->startOfDay()))
            ->when($to, fn (Builder $q) => $q->where('e.posted_at', '<=', $to))
            ->when($withoutYearEnd, fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull('e.idempotency_key')->orWhere('e.idempotency_key', 'not like', self::YEAR_END.'%')))
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->orderBy('a.code')
            ->selectRaw('a.id, a.code, a.name, a.type, sum(l.debit) - sum(l.credit) as net')
            ->get();

        $rows = [];
        foreach ($accounts as $a) {
            /** @var object{id: int|string, code: string, name: string, type: string, net: int|string} $a */
            if ((int) $a->net !== 0) {
                $rows[] = ['id' => (int) $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'net' => (int) $a->net];
            }
        }

        return $rows;
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
                $available = $account->availableBalance(); // held money is already spoken for
                if ($available < (int) $account->min_balance) {
                    $format = fn (int $minor) => Currency::format($minor, $account->currency)." {$account->currency}";
                    throw new InvalidEntry("Insufficient funds in {$account->name}: the available balance would be {$format($available)}, below the minimum of {$format((int) $account->min_balance)}.");
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
