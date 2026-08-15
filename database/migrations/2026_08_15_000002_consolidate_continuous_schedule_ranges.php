<?php

use App\Models\Project;
use App\Services\ScheduleConsolidation;
use Illuminate\Database\Migrations\Migration;

/**
 * Ranges that run into each other are now merged as they are written, but the
 * rows already in the table were written before that rule existed.
 *
 * Left alone they would go on reading as several visits on every screen - two
 * bars on the calendar, two lines in the schedule panel - and describing a
 * break between them that does not exist. So the same rule is applied once to
 * what is already there.
 *
 * The service is called rather than the logic repeated: this is exactly the
 * question it answers, and a second copy here could only drift from it. Only
 * whole-day ranges merge; partial days book hours on one date and are left
 * exactly as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        $consolidation = app(ScheduleConsolidation::class);

        // Every project, not only the live ones: a completed project's dates
        // are its record of what happened, and that record should read the way
        // the work actually ran.
        Project::query()
            ->with('schedules')
            ->cursor()
            ->each(fn (Project $project) => $consolidation->consolidate($project));
    }

    public function down(): void
    {
        // Merging is not reversible: once Aug 20-21 and Aug 22-24 are one row
        // covering Aug 20-24, nothing records where the seam used to be - and
        // the seam was the thing that was wrong.
    }
};
