<?php

namespace Arkhan\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $description
 * @property string $currency
 * @property string|null $idempotency_key
 * @property string|null $request_hash
 * @property int|null $reverses_id
 */
class JournalEntry extends Model
{
    use Immutable;

    protected $table = 'ledger_journal_entries';

    protected $fillable = ['description', 'currency', 'idempotency_key', 'request_hash', 'reverses_id'];

    protected $casts = ['reverses_id' => 'integer'];

    /** @return HasMany<EntryLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(EntryLine::class, 'journal_entry_id');
    }
}
