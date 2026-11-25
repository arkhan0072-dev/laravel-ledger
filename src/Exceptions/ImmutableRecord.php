<?php

namespace Arkhan\Ledger\Exceptions;

use LogicException;

class ImmutableRecord extends LogicException
{
    public function __construct()
    {
        parent::__construct('Posted ledger records cannot be changed or deleted. Post a reversal instead.');
    }
}
