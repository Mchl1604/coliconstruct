<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A project's plan reserving stock shows on the history as a Stock Out. It is
 * marked, because nothing has left the shelf yet: the row records the claim,
 * and only a release moves the count - see StockAvailability.
 *
 * Plans already live get their row here, so the history covers them too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_stock_movements', function (Blueprint $table): void {
            $table->boolean('is_reservation')->default(false)->after('project_material_entry_id');
        });

        $now = now();

        DB::table('tbl_project_materials')
            ->join('tbl_projects', 'tbl_projects.project_id', '=', 'tbl_project_materials.project_id')
            ->where('tbl_projects.is_archived', false)
            ->whereNotIn('tbl_projects.status', ['awaiting_client_confirmation', 'completed', 'cancelled', 'archived'])
            ->where('tbl_project_materials.quantity_needed', '>', 0)
            ->orderBy('tbl_project_materials.project_material_id')
            ->select('tbl_project_materials.*')
            ->get()
            ->each(function (object $plan) use ($now): void {
                DB::table('tbl_stock_movements')->insert([
                    'material_id' => $plan->material_id,
                    'movement_type' => 'stock_out',
                    'quantity' => -$plan->quantity_needed,
                    'movement_date' => substr((string) ($plan->created_at ?? $now), 0, 10),
                    'project_id' => $plan->project_id,
                    'is_reservation' => true,
                    'notes' => 'Reserved',
                    'recorded_by_name' => 'System',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        DB::table('tbl_stock_movements')->where('is_reservation', true)->delete();

        Schema::table('tbl_stock_movements', function (Blueprint $table): void {
            $table->dropColumn('is_reservation');
        });
    }
};
