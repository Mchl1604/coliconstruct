<?php

use App\Models\PhaseStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fills the stage vocabulary with the four stages this system has always
 * suggested.
 *
 * Only the vocabulary. No project type is attached to any of them, and that is
 * deliberate: a type with no stages contributes nothing to a merge, the merger
 * falls back to ProjectPhase::SUGGESTED_PHASES when no type contributes
 * anything, and the fallback is these same four stages. So the day this ships,
 * every project's setup screen offers exactly what it offered the day before,
 * and it goes on doing that until a Super Admin actually writes a template.
 *
 * Inventing a template for every existing type would have produced the same
 * screen by a worse route - rows nobody authored, which the first person into
 * System Settings would have had to read and decide whether to trust.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (PhaseStage::STARTING_VOCABULARY as $index => $stage) {
            // Idempotent, and quiet about a name a Super Admin has already
            // added by hand between deploys.
            if (DB::table('tbl_phase_stages')->where('name', $stage['name'])->exists()) {
                continue;
            }

            DB::table('tbl_phase_stages')->insert([
                'name' => $stage['name'],
                'default_description' => $stage['default_description'],
                // Tens, so a stage can be slotted between two of these later
                // without renumbering anything.
                'sort_order' => ($index + 1) * PhaseStage::SORT_STEP,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('tbl_phase_stages')
            ->whereIn('name', array_column(PhaseStage::STARTING_VOCABULARY, 'name'))
            ->delete();
    }
};
