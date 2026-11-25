<?php

namespace Arkhan\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

class EntryLine extends Model
{
    protected $table = 'ledger_entry_lines';

    public $timestamps = false;

    protected $fillable = ['account_id', 'debit', 'credit'];

    protected $casts = ['debit' => 'integer', 'credit' => 'integer'];
}
