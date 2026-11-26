<?php

namespace Arkhan\Ledger\Models;

use Arkhan\Ledger\AccountType;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property string $currency ISO 4217 code, e.g. USD
 */
class Account extends Model
{
    protected $table = 'ledger_accounts';

    protected $fillable = ['code', 'name', 'type', 'currency'];

    protected $casts = ['type' => AccountType::class];

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
