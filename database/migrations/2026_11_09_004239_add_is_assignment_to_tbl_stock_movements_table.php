<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A project's plan is now a real Stock Out: stock assigned to a project has
 * left the warehouse's count, whether it is on site yet or not. The row the
 * plan writes is marked so the plan can find it again - see
 * StockLedger::syncAssignments().
 *
 * Live projects still holding an unreleased reservation get it written as an
 * assignment here, capped at what is on hand, and their old Reserved rows are
 * voided. Closed projects keep their history as it was. Not reversed by
 * down(): the converted rows stay as ordinary stock outs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_stock_movements', function (Blueprint $table): void {
            $table->boolean('is_assignment')->default(false)->after('is_reservation');
        });

        $now = now();
        $sum = fn ($query): int => (int) round((float) $query->sum('quantity') * 100);

        DB::table('tbl_project_materials')
            ->join('tbl_projects', 'tbl_projects.project_id', '=', 'tbl_project_materials.project_id')
            ->where('tbl_projects.is_archived', false)
            ->whereNotIn('tbl_projects.status', ['awaiting_client_confirmation', 'completed', 'cancelled', 'archived'])
            ->where('tbl_project_materials.quantity_needed', '>', 0)
            ->orderBy('tbl_project_materials.project_material_id')
            ->select('tbl_project_materials.*')
            ->get()
            ->each(function (object $plan) use ($now, $sum): void {
                $movements = fn () => DB::table('tbl_stock_movements')
                    ->whereNull('voided_at')
                    ->where('project_id', $plan->project_id)
                    ->where('material_id', $plan->material_id);

                $released = -$sum($movements()->where('is_reservation', false)->whereNull('project_material_entry_id'));
                $givenBack = $sum($movements()->where('is_reservation', true)->where('quantity', '>', 0));
                $usedOffShelf = -$sum($movements()
                    ->where('is_reservation', false)
                    ->whereIn('project_material_entry_id', DB::table('tbl_project_material_entries')
                        ->where('entry_type', 'used')
                        ->select('material_entry_id')));

                $claim = (int) round((float) $plan->quantity_needed * 100) - max(0, $released) - $givenBack - $usedOffShelf;
                $onHand = $sum(DB::table('tbl_stock_movements')
                    ->whereNull('voided_at')
                    ->where('is_reservation', false)
                    ->where('material_id', $plan->material_id));
                $assigned = min($claim, $onHand);

                $movements()
                    ->where('is_reservation', true)
                    ->where('quantity', '<', 0)
                    ->update(['voided_at' => $now, 'updated_at' => $now]);

                if ($assigned > 0) {
                    DB::table('tbl_stock_movements')->insert([
                        'material_id' => $plan->material_id,
                        'movement_type' => 'stock_out',
                        'quantity' => -$assigned / 100,
                        'movement_date' => $now->toDateString(),
                        'project_id' => $plan->project_id,
                        'is_reservation' => false,
                        'is_assignment' => true,
                        'notes' => 'Assigned to project',
                        'recorded_by_name' => 'System',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('tbl_stock_movements', function (Blueprint $table): void {
            $table->dropColumn('is_assignment');
        });
    }
};
