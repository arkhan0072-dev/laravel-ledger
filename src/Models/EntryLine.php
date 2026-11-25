<?php

namespace Arkhan\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

class EntryLine extends Model
{
    use Immutable;

    protected $table = 'ledger_entry_lines';

    public $timestamps = false;

    protected $fillable = ['account_id', 'debit', 'credit'];

    protected $casts = ['account_id' => 'integer', 'debit' => 'integer', 'credit' => 'integer'];
}
