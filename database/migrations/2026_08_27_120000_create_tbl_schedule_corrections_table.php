<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record of every correction made to work already on the books.
 *
 * A Super Admin may edit a schedule that has already run - to add days that
 * were worked but never booked, to shorten a range that claimed days nobody
 * was on site for, or to move one that was recorded against the wrong week.
 * The activity log carries a sentence about it; this table carries the facts,
 * so an audit can ask exactly which days were added and whose name was put
 * against them without parsing prose.
 *
 * Nothing here is a foreign key to the schedule row. A correction has to
 * outlive the range it describes - including the case where the correction was
 * the deletion of that range - which is the same stance
 * tbl_activity_logs.record_id takes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_schedule_corrections', function (Blueprint $table): void {
            $table->id('schedule_correction_id');

            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('schedule_id')->nullable();

            // Who made it. Snapshotted alongside the id for the same reason the
            // activity log snapshots its actor: the entry has to keep reading
            // correctly after the account is renamed, demoted or removed.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('actor_role', 30)->nullable();

            // What the range said before and what it says now. Null on the
            // "before" side means the range did not exist - a past booking
            // created outright rather than edited.
            $table->string('original_range')->nullable();
            $table->string('new_range')->nullable();

            // The days this correction newly claimed, and the days it gave up.
            // Stored as lists of Y-m-d so a report can count them.
            $table->json('added_dates');
            $table->json('removed_dates');

            // Who worked the added days, as {technician_id, name} pairs. Empty
            // only for a correction that added nothing.
            $table->json('technicians');

            $table->timestamp('created_at')->nullable();

            $table->index(['project_id', 'created_at']);
            $table->index(['schedule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_schedule_corrections');
    }
};
