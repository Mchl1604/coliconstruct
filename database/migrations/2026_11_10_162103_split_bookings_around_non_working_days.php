<?php

use App\Models\Holiday;
use App\Services\HolidayCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Split the bookings already running across a non-working day, which were
 * left whole when the day was added - see HolidayCalendar::splitBookingsOn().
 * Upcoming days only.
 */
return new class extends Migration
{
    public function up(): void
    {
        $calendar = app(HolidayCalendar::class);

        DB::transaction(function () use ($calendar): void {
            Holiday::query()
                ->orderBy('holiday_date')
                ->pluck('holiday_date')
                ->each(fn ($date) => $calendar->splitBookingsOn(CarbonImmutable::parse($date)));
        });
    }

    /**
     * The split rows are ordinary bookings now; nothing to put back.
     */
    public function down(): void {}
};
