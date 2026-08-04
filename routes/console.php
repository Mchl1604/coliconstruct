<?php

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
