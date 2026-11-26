<?php

namespace Arkhan\Ledger\Models;

use Arkhan\Ledger\AccountType;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property string $currency ISO 4217 code, e.g. USD
 * @property int|null $min_balance lowest balance a posting may leave, in minor units; null for no limit
 * @property int|null $parent_id
 * @property-read Account|null $parent
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property-read Model|null $owner set when a model using HasLedger opened the account
 */
class Account extends Model
{
    protected $table = 'ledger_accounts';

    protected $fillable = ['code', 'name', 'type', 'currency', 'min_balance', 'parent_id'];

    protected $casts = ['type' => AccountType::class, 'min_balance' => 'integer', 'parent_id' => 'integer'];

    protected static function booted(): void
    {
        static::saving(function (Account $account) {
            if ($account->parent_id === null) {
                return;
            }
            $parent = Account::findOrFail($account->parent_id);
            // A parent's total adds its children's balances, which only makes sense in one currency and on one side.
            if ($parent->currency !== $account->currency) {
                throw new InvalidArgumentException("Account {$account->code} must use its parent's currency {$parent->currency}, got {$account->currency}.");
            }
            if ($parent->type !== $account->type) {
                throw new InvalidArgumentException("Account {$account->code} must have its parent's type {$parent->type->value}, got {$account->type->value}.");
            }
            for ($node = $parent; $node !== null; $node = $node->parent) {
                if ($account->exists && $node->id === $account->id) {
                    throw new InvalidArgumentException("Account {$account->code} cannot be placed under its own descendant {$parent->code}.");
                }
            }
        });
    }

    /** Currency codes are stored upper-case and must be three letters, so "usd" and "USD" never become two currencies. */
    public function setCurrencyAttribute(string $value): void
    {
        $code = strtoupper(trim($value));
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidArgumentException("Currency must be a three-letter ISO 4217 code, got \"{$value}\".");
        }

        $this->attributes['currency'] = $code;
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Account, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    /** @return HasMany<Account, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id')->orderBy('code');
    }

    /** @return HasMany<EntryLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(EntryLine::class);
    }

    /** Balance in minor units, positive on the account type's normal side; optionally as of a date. */
    public function balance(?DateTimeInterface $asOf = null): int
    {
        return $this->sumLines($this->lines()->getQuery(), $asOf);
    }

    /** Balance of this account plus all of its descendants, e.g. every bank account under Cash. */
    public function totalBalance(?DateTimeInterface $asOf = null): int
    {
        $ids = $level = [$this->id];
        while ($level !== []) {
            $level = Account::whereIn('parent_id', $level)->pluck('id')->all();
            $ids = [...$ids, ...$level];
        }

        return $this->sumLines(EntryLine::whereIn('account_id', $ids), $asOf);
    }

    /** @param  Builder<EntryLine>  $lines */
    private function sumLines(Builder $lines, ?DateTimeInterface $asOf): int
    {
        $lines->when($asOf, fn ($q) => $q->whereIn('journal_entry_id', JournalEntry::select('id')->where('posted_at', '<=', $asOf)));
        $net = (int) (clone $lines)->sum('debit') - (int) (clone $lines)->sum('credit');

        return $this->type->isDebitNormal() ? $net : -$net;
    }

    /** Balance minus money reserved by active, unexpired holds. */
    public function availableBalance(): int
    {
        $held = (int) $this->hasMany(Hold::class)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->sum('amount');

        return $this->balance() - $held;
    }
}
