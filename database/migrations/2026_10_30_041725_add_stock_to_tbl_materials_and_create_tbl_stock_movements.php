<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock: how many of each material the company holds, kept as a record of
 * every movement in and out rather than as a number somebody overwrites.
 *
 *     On Hand = the sum of every movement that is not voided
 *
 * Each movement's quantity carries its own sign - a delivery adds, an issue
 * takes away, a count correction does either - so the figure is one SUM and
 * cannot be added up two different ways. Nothing is edited after it is
 * written: a wrong entry is voided and stays on the history, struck through,
 * exactly as a project's material entries are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_materials', function (Blueprint $table): void {
            // At or below this, the material is Low Stock and Purchasing is
            // told. Optional: a material nobody reorders has no level.
            $table->decimal('reorder_level', 12, 2)->nullable()->after('description');
        });

        Schema::create('tbl_stock_movements', function (Blueprint $table): void {
            $table->id('stock_movement_id');

            $table->foreignId('material_id')
                ->constrained('tbl_materials', 'material_id')
                ->restrictOnDelete();

            // A StockMovement::TYPES key.
            $table->string('movement_type', 20);
            // Signed: positive adds to stock, negative takes from it.
            $table->decimal('quantity', 12, 2);
            $table->date('movement_date');

            // The delivery receipt, purchase order or invoice it came with.
            $table->string('reference_no', 100)->nullable();
            // Who delivered it, for stock received. Free text - there is no
            // supplier list.
            $table->string('supplier', 150)->nullable();
            // The project stock went out to, or came back from.
            $table->foreignId('project_id')
                ->nullable()
                ->constrained('tbl_projects', 'project_id')
                ->nullOnDelete();
            $table->string('notes', 255)->nullable();

            // The name is kept beside the id so the history still reads
            // correctly after the account is renamed or removed.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorded_by_name')->nullable();

            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['material_id', 'movement_date']);
            $table->index('movement_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_stock_movements');

        Schema::table('tbl_materials', function (Blueprint $table): void {
            $table->dropColumn('reorder_level');
        });
    }
};
