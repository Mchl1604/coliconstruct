<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Giving team membership a timeline, so leaving a project stops erasing the
 * fact you were ever on it.
 *
 * Until now a removal deleted the tbl_project_technicians row outright, and
 * tbl_schedule_technicians.project_technician_id cascades from it - so every
 * record of which dates that technician was booked for went with it. Days
 * genuinely worked in July disappeared from the project's history because
 * somebody was taken off the team in August. The two facts are unrelated, and
 * one should never have decided the other.
 *
 * Three columns, and a rule that follows from them:
 *
 *     joined_at   the day they came onto the team
 *     removed_at  the day they came off it, or null while they are still on
 *     removed_by  who took them off, for the audit trail
 *
 *     "was technician T on project P on date D?"
 *         joined_at <= D and (removed_at is null or removed_at > D)
 *
 * That question could not be asked before at all: ProjectTeam's own docblock
 * says so - "Nothing records when somebody joined a team - tbl_project_
 * technicians keeps no timestamp" - and it is why its repair command has to
 * refuse to restore links it cannot prove belong to anyone.
 *
 * The row is never deleted again. Removal stamps removed_at instead, which
 * leaves the parent alive so the schedule links hanging off it still resolve
 * to a technician.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_project_technicians', function (Blueprint $table) {
            $table->timestamp('joined_at')->nullable()->after('technician_id');
            $table->timestamp('removed_at')->nullable()->after('joined_at');
            $table->unsignedBigInteger('removed_by')->nullable()->after('removed_at');

            // Every read of this table now asks "who is still on the team?",
            // which is this pair of columns and nothing else.
            $table->index(['project_id', 'removed_at'], 'pt_project_removed_idx');
        });

        $this->backfillJoinedAt();
    }

    public function down(): void
    {
        Schema::table('tbl_project_technicians', function (Blueprint $table) {
            $table->dropIndex('pt_project_removed_idx');
            $table->dropColumn(['joined_at', 'removed_at', 'removed_by']);
        });
    }

    /**
     * Date every existing membership from the first day its project was
     * booked for.
     *
     * A guess, and deliberately the safest one available. Nothing recorded
     * when these people joined, so the only defensible answer is the earliest
     * moment the project could have had a team at all - which makes every
     * existing member read as present for the whole of their project's
     * history, exactly as the application treated them before this column
     * existed. Dating them from today instead would blank the past for
     * everybody currently on a team, which is the very failure this migration
     * is here to stop.
     *
     * A project with no schedule falls back to its own created_at, and a row
     * whose project has neither falls back to now.
     *
     * What this cannot do is bring back the technicians who were already
     * removed. Those rows are gone, with no timestamp anywhere to rebuild
     * them from. The record starts here.
     */
    private function backfillJoinedAt(): void
    {
        $firstScheduled = DB::table('tbl_schedule')
            ->select('project_id', DB::raw('MIN(start_datetime) as first_day'))
            ->groupBy('project_id')
            ->pluck('first_day', 'project_id');

        $projectCreatedAt = DB::table('tbl_projects')->pluck('created_at', 'project_id');

        $now = now();

        DB::table('tbl_project_technicians')
            ->whereNull('joined_at')
            ->orderBy('project_technician_id')
            ->chunkById(200, function ($assignments) use ($firstScheduled, $projectCreatedAt, $now): void {
                foreach ($assignments as $assignment) {
                    DB::table('tbl_project_technicians')
                        ->where('project_technician_id', $assignment->project_technician_id)
                        ->update([
                            'joined_at' => $firstScheduled[$assignment->project_id]
                                ?? $projectCreatedAt[$assignment->project_id]
                                ?? $now,
                        ]);
                }
            }, 'project_technician_id');
    }
};
