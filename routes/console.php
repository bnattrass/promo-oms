<?php

use App\Jobs\SendQuoteFollowUps;
use Illuminate\Support\Facades\Schedule;

/*
| Supplier syncs (Amrod schedule, SAST). Amrod is offline 00:00-01:00 every night.
| Prices/products update once a day; stock fully at 06:30, 12:30, 18:30 and rolling every few minutes.
*/
$tz = 'Africa/Johannesburg';

Schedule::command('suppliers:sync --module=categories --module=brands --module=branding_prices --module=inclusive_brandings --module=products')
    ->dailyAt('01:15')->timezone($tz)->withoutOverlapping(120)->onOneServer()
    ->skip(fn () => now($tz)->isSunday());

// Prices: a FULL import every night (the feed is small). A changes-only sync misses prices for
// products that were newly added to the catalogue since the last full price import.
Schedule::command('suppliers:sync --full --module=prices')
    ->dailyAt('02:15')->timezone($tz)->withoutOverlapping(60)->onOneServer();

Schedule::command('suppliers:sync --full --module=categories --module=brands --module=branding_prices --module=inclusive_brandings --module=products')
    ->weeklyOn(0, '01:30')->timezone($tz)->withoutOverlapping(180)->onOneServer();

Schedule::command('suppliers:sync --full --module=stock')
    ->cron('45 6,12,18 * * *')->timezone($tz)->withoutOverlapping(30)->onOneServer();

Schedule::command('suppliers:sync --module=stock')
    ->cron('*/15 7-23 * * *')->timezone($tz)->withoutOverlapping(14)->onOneServer();

// Quote follow-ups and deposit reminders.
Schedule::job(new SendQuoteFollowUps)->hourlyAt(5)->timezone($tz)->between('08:00', '17:00')->weekdays();

/*
| Hosts without a process manager (e.g. CloudPanel site users can't run Supervisor): set
| QUEUE_VIA_SCHEDULER=true and the cron-driven scheduler works the queue every minute.
*/
if (env('QUEUE_VIA_SCHEDULER', false)) {
    Schedule::command('queue:work --queue=sync,default --stop-when-empty --max-time=55 --tries=1 --timeout=3600')
        ->everyMinute()->withoutOverlapping(90)->runInBackground();
}
