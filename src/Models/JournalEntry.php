<?php

namespace Arkhan\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use Immutable;

    protected $table = 'ledger_journal_entries';

    protected $fillable = ['description', 'currency', 'idempotency_key', 'request_hash', 'reverses_id'];

    protected $casts = ['reverses_id' => 'integer'];

    public function lines(): HasMany
    {
        return $this->hasMany(EntryLine::class, 'journal_entry_id');
    }
}
