<?php

namespace Arkhan\Ledger\Models;

use Arkhan\Ledger\AccountType;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
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
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property-read Model|null $owner set when a model using HasLedger opened the account
 */
class Account extends Model
{
    protected $table = 'ledger_accounts';

    protected $fillable = ['code', 'name', 'type', 'currency', 'min_balance'];

    protected $casts = ['type' => AccountType::class, 'min_balance' => 'integer'];

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

    /** @return HasMany<EntryLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(EntryLine::class);
    }

    /** Balance in minor units, positive on the account type's normal side; optionally as of a date. */
    public function balance(?DateTimeInterface $asOf = null): int
    {
        $lines = $this->lines()->when($asOf, fn ($q) => $q->whereIn(
            'journal_entry_id',
            JournalEntry::select('id')->where('posted_at', '<=', $asOf),
        ));
        $net = (int) (clone $lines)->sum('debit') - (int) (clone $lines)->sum('credit');

        return $this->type->isDebitNormal() ? $net : -$net;
    }
}
