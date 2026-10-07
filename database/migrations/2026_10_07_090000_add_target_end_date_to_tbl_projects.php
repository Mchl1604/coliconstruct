<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The date a project is promised to be finished by.
 *
 * Not a schedule: it books nobody and blocks no day. It is the commitment the
 * schedule is measured against, and a project whose work runs past it reads
 * as Overdue rather than being refused.
 *
 * Nullable: projects created before the field existed have no target.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table): void {
            $table->date('target_end_date')->nullable()->after('quotation');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table): void {
            $table->dropColumn('target_end_date');
        });
    }
};
