<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A task can now be closed by someone other than the technician holding it -
 * an administrator or the project's lead, tidying up work that is finished on
 * site but never marked. Those closures carry no notes or photos, so the
 * completion panel has to be able to say who closed it and why it is bare.
 *
 * Null covers both the rows that predate this column and any closure where the
 * account has since been deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_tasks', function (Blueprint $table): void {
            $table->foreignId('completed_by')
                ->nullable()
                ->after('completed_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('completed_by');
        });
    }
};
