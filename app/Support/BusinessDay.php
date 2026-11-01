<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class BusinessDay
{
    private const TIMEZONE = 'Asia/Jakarta';

    /** SQL expression that converts a UTC timestamp column to its WIB calendar date. */
    public static function localDateExpression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "DATE(DATETIME({$column}, '+7 hours'))",
            'mysql', 'mariadb' => "DATE(DATE_ADD({$column}, INTERVAL 7 HOUR))",
            default => "CAST(({$column} + INTERVAL '7 hours') AS DATE)",
        };
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function today(): array
    {
        return self::forDate(Carbon::now(self::TIMEZONE)->toDateString());
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function yesterday(): array
    {
        return self::forDate(Carbon::now(self::TIMEZONE)->subDay()->toDateString());
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function forDate(string $ymd): array
    {
        $start = Carbon::createFromFormat('!Y-m-d', $ymd, self::TIMEZONE)->startOfDay();
        $end = $start->copy()->addDay();

        return [$start->copy()->utc(), $end->utc()];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function between(string $from, string $to): array
    {
        $start = Carbon::createFromFormat('!Y-m-d', $from, self::TIMEZONE)->startOfDay();
        $end = Carbon::createFromFormat('!Y-m-d', $to, self::TIMEZONE)->startOfDay()->addDay();

        return [$start->utc(), $end->utc()];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function month(string $ym): array
    {
        $start = Carbon::createFromFormat('!Y-m', $ym, self::TIMEZONE)->startOfMonth()->startOfDay();
        $end = $start->copy()->addMonth();

        return [$start->utc(), $end->utc()];
    }
}
