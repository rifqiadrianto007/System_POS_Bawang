<?php

namespace App\Support;

use Carbon\Carbon;

final class CashierTransactionWindow
{
    /** @return array{0: Carbon, 1: Carbon} */
    public static function bounds(): array
    {
        [$earliest] = BusinessDay::yesterday();
        [, $latestExclusive] = BusinessDay::today();

        return [$earliest, $latestExclusive];
    }

    public static function earliestDate(): string
    {
        return Carbon::now('Asia/Jakarta')->subDay()->toDateString();
    }

    public static function latestDate(): string
    {
        return Carbon::now('Asia/Jakarta')->toDateString();
    }
}
