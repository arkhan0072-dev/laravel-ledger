<?php

namespace Arkhan\Ledger;

use Arkhan\Ledger\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use InvalidArgumentException;
use LogicException;

/**
 * Give any Eloquent model (a user, a shop, a merchant) its own ledger accounts.
 *
 * @phpstan-require-extends Model
 */
trait HasLedger
{
    /** @return MorphMany<Account, $this> */
    public function ledgerAccounts(): MorphMany
    {
        return $this->morphMany(Account::class, 'owner');
    }

    /** The owner's account with this name and currency, opened on first use. */
    public function ledgerAccount(string $name, AccountType $type, string $currency): Account
    {
        $key = $this->getKey();
        if (! is_int($key) && ! is_string($key)) {
            throw new LogicException('Save the owner before opening ledger accounts for it.');
        }
        $currency = strtoupper(trim($currency));
        // The unique account code makes a concurrent first use return the same row.
        $account = $this->ledgerAccounts()->firstOrCreate(
            ['name' => $name, 'currency' => $currency],
            ['type' => $type, 'code' => "{$this->getMorphClass()}:{$key}:{$name}:{$currency}"],
        );

        if ($account->type !== $type) {
            throw new InvalidArgumentException("Account \"{$name}\" in {$currency} already exists as {$account->type->value}.");
        }

        return $account;
    }
}
