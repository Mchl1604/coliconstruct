<?php

use App\Models\Schedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Scheduling mode is recorded per schedule row, not per project, so one
     * project can hold a whole-day range and an hours-only booking side by
     * side. Every row that already exists is a whole-day range, which is
     * exactly what the default says - so nothing has to be backfilled and
     * every existing project keeps behaving as it does today.
     */
    public function up(): void
    {
        Schema::table('tbl_schedule', function (Blueprint $table) {
            $table->string('scheduling_mode')
                ->default(Schedule::MODE_DATE_BASED)
                ->after('end_datetime');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_schedule', function (Blueprint $table) {
            $table->dropColumn('scheduling_mode');
        });
    }
};
