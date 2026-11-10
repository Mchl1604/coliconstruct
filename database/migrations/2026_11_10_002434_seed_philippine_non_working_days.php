<?php

use App\Models\Holiday;
use Database\Seeders\PhilippineNonWorkingDaySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Puts the Philippines' proclaimed non-working days on a deployed system, which
 * runs migrations but not seeders - see PhilippineNonWorkingDaySeeder.
 *
 * Only adds dates not listed yet, so days the Super Admin already entered or
 * renamed stay as they are. Skipped under tests, which expect an empty list.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $this->seedDays();
    }

    public function seedDays(): void
    {
        (new PhilippineNonWorkingDaySeeder)->run();
    }

    /**
     * Takes back only the seeded days still under their seeded name and not
     * added by anyone.
     */
    public function down(): void
    {
        foreach (PhilippineNonWorkingDaySeeder::DAYS as $date => $name) {
            Holiday::query()
                ->whereDate('holiday_date', $date)
                ->where('name', $name)
                ->whereNull('created_by')
                ->delete();
        }
    }
};
