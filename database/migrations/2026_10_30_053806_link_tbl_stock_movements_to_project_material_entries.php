<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stock movement the project's own Materials tab caused - an Added entry
 * taking stock out of the warehouse, a Returned or Unused one bringing it
 * back - points at that entry.
 *
 * Two reasons. Voiding the entry voids its movement with it, so the two
 * records cannot disagree. And a project's plan is reserved until the
 * warehouse releases it: only a movement Purchasing made by hand for the
 * project counts as that release, never one the Materials tab wrote - see
 * StockAvailability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_stock_movements', function (Blueprint $table): void {
            $table->foreignId('project_material_entry_id')
                ->nullable()
                ->after('project_id')
                ->constrained('tbl_project_material_entries', 'material_entry_id')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_stock_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('project_material_entry_id');
        });
    }
};
