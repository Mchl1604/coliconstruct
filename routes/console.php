<?php

use App\Services\OtpService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The morning reminder run: tasks due tomorrow, due today, and overdue.
// Needs `php artisan schedule:work` (or a cron entry calling schedule:run) to
// be running on the server.
Schedule::command('tasks:remind')->dailyAt('08:00');

// Lapsed verification codes.
//
// Issuing a code already sweeps as it goes, so this only matters on a quiet
// system where nobody has asked for one in a while - it stops the table
// keeping rows nothing will ever read again.
Schedule::call(fn () => app(OtpService::class)->purgeExpired())
    ->dailyAt('03:00')
    ->name('otp:purge-expired');
