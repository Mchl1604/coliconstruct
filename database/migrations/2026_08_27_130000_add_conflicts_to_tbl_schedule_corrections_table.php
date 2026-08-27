<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the record already said, when a Super Admin overruled it.
     *
     * A historical correction is allowed to land on a day another project
     * already claims that technician for - the two records disagree, and only
     * a person can say which is right. What must not happen is that decision
     * being taken silently: this column holds the clash as it stood at the
     * moment it was overruled, so an auditor reading the correction afterwards
     * can see the technician, the day, the other project and the booking that
     * was already there, and that somebody confirmed it deliberately.
     *
     * Nullable, and empty for the ordinary case: a correction that clashed
     * with nothing has nothing to answer for.
     */
    public function up(): void
    {
        Schema::table('tbl_schedule_corrections', function (Blueprint $table): void {
            $table->json('conflicts')->nullable()->after('technicians');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_schedule_corrections', function (Blueprint $table): void {
            $table->dropColumn('conflicts');
        });
    }
};
