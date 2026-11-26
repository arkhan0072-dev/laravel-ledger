<?php

namespace Arkhan\Ledger;

/** ISO 4217 minor units: how many decimal places an amount in each currency has. */
final class Currency
{
    /** Currencies that do not have two decimal places. Everything else has two. */
    private const DECIMALS = [
        // No minor unit
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0,
        'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        // Three decimal places
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
        // Four decimal places
        'CLF' => 4, 'UYW' => 4,
    ];

    public static function decimals(string $code): int
    {
        return self::DECIMALS[strtoupper($code)] ?? 2;
    }

    /** Minor units (as stored) to a display string, e.g. 125050 USD -> "1,250.50", 1500 JPY -> "1,500". */
    public static function format(int $minor, string $code): string
    {
        $decimals = self::decimals($code);

        return number_format($minor / 10 ** $decimals, $decimals);
    }
}
