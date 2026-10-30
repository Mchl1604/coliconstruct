<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily progress on a task: what was done on it each day, by the technician
 * holding it, before the day it is finally completed.
 *
 * One update per technician per task per day - posting again the same day
 * edits it rather than adding another. Written by the technician, or by the
 * project's lead on their behalf, which is why who the work belongs to and
 * who pressed Post are kept apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_task_updates', function (Blueprint $table): void {
            $table->id('task_update_id');

            $table->foreignId('task_id')->constrained('tbl_tasks', 'task_id')->cascadeOnDelete();
            // Whose work the day was - the task's holder when it was posted.
            $table->foreignId('technician_id')->constrained('tbl_technicians', 'technician_id')->cascadeOnDelete();
            $table->date('update_date');
            $table->text('notes');

            // Who pressed Post: the technician, or the lead on their behalf.
            // The name is kept beside the id so the record still reads after
            // the account is renamed or removed.
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('posted_by_name')->nullable();

            $table->timestamps();

            $table->unique(['task_id', 'technician_id', 'update_date']);
            $table->index('update_date');
        });

        Schema::create('tbl_task_update_images', function (Blueprint $table): void {
            $table->id('task_update_image_id');
            $table->foreignId('task_update_id')->constrained('tbl_task_updates', 'task_update_id')->cascadeOnDelete();
            $table->string('image_path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_task_update_images');
        Schema::dropIfExists('tbl_task_updates');
    }
};
