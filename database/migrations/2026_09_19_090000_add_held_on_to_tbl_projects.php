<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The office day a project was put on hold.
 *
 * A hold keeps every booked day up to and including that day - it was worked -
 * and preserves the rest as the proposal for resuming. Without the date the
 * two could not be told apart, so the kept days stopped counting against
 * anybody's availability and the crew could be booked elsewhere on the very
 * day they were still on site.
 *
 * Nullable: projects already on hold have no recorded day and behave as they
 * did before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table): void {
            $table->date('held_on')->nullable()->after('on_hold');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table): void {
            $table->dropColumn('held_on');
        });
    }
};
