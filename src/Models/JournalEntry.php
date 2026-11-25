<?php

namespace Arkhan\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    protected $table = 'ledger_journal_entries';

    protected $fillable = ['description', 'currency'];

    public function lines(): HasMany
    {
        return $this->hasMany(EntryLine::class, 'journal_entry_id');
    }
}
