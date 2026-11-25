<?php

namespace Arkhan\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $journal_entry_id
 * @property int $account_id
 * @property int $debit
 * @property int $credit
 */
class EntryLine extends Model
{
    use Immutable;

    protected $table = 'ledger_entry_lines';

    public $timestamps = false;

    protected $fillable = ['account_id', 'debit', 'credit'];

    protected $casts = ['account_id' => 'integer', 'debit' => 'integer', 'credit' => 'integer'];
}
