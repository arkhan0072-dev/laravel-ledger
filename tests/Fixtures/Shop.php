<?php

namespace Arkhan\Ledger\Tests\Fixtures;

use Arkhan\Ledger\HasLedger;
use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    use HasLedger;

    protected $guarded = [];
}
