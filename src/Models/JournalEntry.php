<?php

namespace Arkhan\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $description
 * @property string $currency
 * @property string|null $idempotency_key
 * @property string|null $request_hash
 * @property int|null $reverses_id
 * @property Carbon $posted_at the accounting date, which can differ from created_at
 * @property string|null $reference_type
 * @property int|string|null $reference_id
 * @property-read Model|null $reference the record this entry books, e.g. an order
 */
class JournalEntry extends Model
{
    use Immutable;

    protected $table = 'ledger_journal_entries';

    protected $fillable = ['description', 'currency', 'idempotency_key', 'request_hash', 'reverses_id', 'posted_at', 'reference_type', 'reference_id'];

    protected $casts = ['reverses_id' => 'integer', 'posted_at' => 'datetime'];

    /** @return HasMany<EntryLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(EntryLine::class, 'journal_entry_id');
    }

    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
