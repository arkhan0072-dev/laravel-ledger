<?php

namespace Arkhan\Ledger\Models;

use Arkhan\Ledger\AccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $table = 'ledger_accounts';

    protected $fillable = ['code', 'name', 'type', 'currency'];

    protected $casts = ['type' => AccountType::class];

    public function lines(): HasMany
    {
        return $this->hasMany(EntryLine::class);
    }

    /** Balance in minor units, positive on the account type's normal side. */
    public function balance(): int
    {
        $net = (int) $this->lines()->sum('debit') - (int) $this->lines()->sum('credit');

        return $this->type->isDebitNormal() ? $net : -$net;
    }
}
