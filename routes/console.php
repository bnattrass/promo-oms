<?php

use App\Jobs\SendQuoteFollowUps;
use Illuminate\Support\Facades\Schedule;

/*
| Supplier syncs (Amrod schedule, SAST). Amrod is offline 00:00-01:00 every night.
| Prices/products update once a day; stock fully at 06:30, 12:30, 18:30 and rolling every few minutes.
*/
$tz = 'Africa/Johannesburg';

Schedule::command('suppliers:sync --module=categories --module=brands --module=branding_prices --module=inclusive_brandings --module=products --module=prices')
    ->dailyAt('01:15')->timezone($tz)->withoutOverlapping(120)->onOneServer()
    ->skip(fn () => now($tz)->isSunday());

Schedule::command('suppliers:sync --full --module=categories --module=brands --module=branding_prices --module=inclusive_brandings --module=products --module=prices')
    ->weeklyOn(0, '01:30')->timezone($tz)->withoutOverlapping(180)->onOneServer();

Schedule::command('suppliers:sync --full --module=stock')
    ->cron('45 6,12,18 * * *')->timezone($tz)->withoutOverlapping(30)->onOneServer();

Schedule::command('suppliers:sync --module=stock')
    ->cron('*/15 7-23 * * *')->timezone($tz)->withoutOverlapping(14)->onOneServer();

// Quote follow-ups and deposit reminders.
Schedule::job(new SendQuoteFollowUps)->hourlyAt(5)->timezone($tz)->between('08:00', '17:00')->weekdays();
