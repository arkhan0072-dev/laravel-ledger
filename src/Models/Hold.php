<?php

namespace Arkhan\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $account_id
 * @property int $amount minor units
 * @property string $description
 * @property string $status active, captured or released
 * @property Carbon|null $expires_at
 * @property int|null $journal_entry_id the capture's entry
 */
class Hold extends Model
{
    protected $table = 'ledger_holds';

    protected $fillable = ['account_id', 'amount', 'description', 'status', 'expires_at', 'journal_entry_id'];

    protected $casts = ['account_id' => 'integer', 'amount' => 'integer', 'expires_at' => 'datetime', 'journal_entry_id' => 'integer'];

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte(now());
    }
}
