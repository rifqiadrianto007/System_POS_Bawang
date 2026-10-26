<?php

use App\Support\BusinessDay;
use App\Support\CashierTransactionWindow;
use Carbon\Carbon;

afterEach(fn () => Carbon::setTestNow());

it('H-05 returns an inclusive local date as a half-open UTC range', function () {
    [$start, $end] = BusinessDay::forDate('2026-10-26');

    expect($start->toIso8601String())->toBe('2026-10-25T17:00:00+00:00')
        ->and($end->toIso8601String())->toBe('2026-10-26T17:00:00+00:00');
});

it('uses Jakarta calendar dates for today and yesterday around 00:30 WIB', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-31 00:30:00', 'Asia/Jakarta'));
    [$todayStart, $todayEnd] = BusinessDay::today();
    [$yesterdayStart, $yesterdayEnd] = BusinessDay::yesterday();

    expect($todayStart->toIso8601String())->toBe('2026-10-30T17:00:00+00:00')
        ->and($todayEnd->toIso8601String())->toBe('2026-10-31T17:00:00+00:00')
        ->and($yesterdayStart->toIso8601String())->toBe('2026-10-29T17:00:00+00:00')
        ->and($yesterdayEnd->equalTo($todayStart))->toBeTrue();

    Carbon::setTestNow();
});

it('makes between inclusive of both Jakarta dates across a month boundary', function () {
    [$start, $end] = BusinessDay::between('2026-10-31', '2026-11-01');

    expect($start->toIso8601String())->toBe('2026-10-30T17:00:00+00:00')
        ->and($end->toIso8601String())->toBe('2026-11-01T17:00:00+00:00');
});

it('returns the exact UTC range for October 2026', function () {
    [$start, $end] = BusinessDay::month('2026-10');

    expect($start->toIso8601String())->toBe('2026-09-30T17:00:00+00:00')
        ->and($end->toIso8601String())->toBe('2026-10-31T17:00:00+00:00');
});

it('sets the cashier window from yesterday start through today end exclusively', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-26 00:30:00', 'Asia/Jakarta'));
    [$start, $end] = CashierTransactionWindow::bounds();

    expect($start->toIso8601String())->toBe('2026-10-24T17:00:00+00:00')
        ->and($end->toIso8601String())->toBe('2026-10-26T17:00:00+00:00');

    Carbon::setTestNow();
});
