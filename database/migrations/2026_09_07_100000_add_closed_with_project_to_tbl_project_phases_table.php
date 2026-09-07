<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A phase the system closed because the project finished, rather than one a
 * person ticked off.
 *
 * Completing a project already requires every task on it to be closed, so by
 * the time it finishes there is no work outstanding in any phase - but closing
 * a phase is a separate click, and the last one is the click nobody makes: the
 * project goes read-only the instant completion is requested, which is the
 * same instant the phases freeze. Every project completed before this shipped
 * therefore ended at N-1/N or worse, with its final phase badged "Current
 * Phase" on finished work and no way left to close it.
 *
 * ProjectCompletion now closes what is left open. This column records which
 * ones it closed, so the panel can say "Closed when the project was completed"
 * rather than crediting whoever finished the project with a decision they
 * never took - a phase closed this way names nobody, and that would otherwise
 * be indistinguishable from the phases the backfill closed.
 *
 * It does not drive the reopen. A reopened project keeps every phase closed
 * and gets a new one for the extra work - see ProjectReopen::addReopenPhase(),
 * which is what stops a reopened project having nowhere to file a task.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_project_phases', function (Blueprint $table): void {
            $table->boolean('closed_with_project')->default(false)->after('completed_by');
        });

        // The projects already stuck. Their work is finished - that is what
        // these two statuses mean - so the phases left open on them are the
        // ones nobody could close, not ones anybody decided to leave.
        //
        // Cancelled and archived work is deliberately untouched: a cancelled
        // project stopped rather than finished, and closing its phases would
        // claim stages were completed that were abandoned part-way.
        $finished = DB::table('tbl_projects')
            ->whereIn('status', ['completed', 'awaiting_client_confirmation'])
            ->pluck('project_id');

        if ($finished->isNotEmpty()) {
            DB::table('tbl_project_phases')
                ->whereIn('project_id', $finished)
                ->whereNull('completed_at')
                ->update([
                    'completed_at' => now(),
                    // No completer named, for the same reason the phase
                    // backfill named none: nobody pressed anything.
                    'completed_by' => null,
                    'closed_with_project' => true,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Put the auto-closed ones back before the column that identifies them
        // goes, or they become indistinguishable from phases somebody closed.
        DB::table('tbl_project_phases')
            ->where('closed_with_project', true)
            ->update([
                'completed_at' => null,
                'completed_by' => null,
                'updated_at' => now(),
            ]);

        Schema::table('tbl_project_phases', function (Blueprint $table): void {
            $table->dropColumn('closed_with_project');
        });
    }
};
