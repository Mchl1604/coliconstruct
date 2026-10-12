<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The list of database backups on Configuration -> System Settings.
 *
 * Each row names one archive on the backups disk. The table is left out of
 * every backup and every restore - see DatabaseBackupService::EXCLUDED_TABLES -
 * so restoring last week's data does not also forget the backups made since.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_database_backups', function (Blueprint $table): void {
            $table->id('backup_id');

            // What a download is saved as, and where the archive lives on the
            // backups disk.
            $table->string('file_name');
            $table->string('path');

            // manual, pre_restore or uploaded - see DatabaseBackup::KINDS.
            $table->string('kind', 20);

            $table->boolean('includes_files')->default(true);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('table_count')->default(0);
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('file_count')->default(0);

            // Snapshotted beside the id and not constrained: a restore can
            // replace every account, and the row must still say who made it.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();

            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_database_backups');
    }
};
