<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completing a task used to be a bare status flip. The technician portal asks
 * what was actually done and for a photo of it, so the note lives here and the
 * photos go in tbl_task_images - a table that already existed but had nothing
 * writing to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_tasks', function (Blueprint $table): void {
            $table->text('completion_notes')->nullable()->after('status');
            $table->timestamp('completed_at')->nullable()->after('completion_notes');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_tasks', function (Blueprint $table): void {
            $table->dropColumn(['completion_notes', 'completed_at']);
        });
    }
};
