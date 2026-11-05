<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lines of a project's quotation. The description, unit and price are kept
 * as quoted, so material_id is only nulled if the material is ever removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_quotation_items', function (Blueprint $table): void {
            $table->id('quotation_item_id');
            $table->foreignId('project_id')->constrained('tbl_projects', 'project_id')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->foreignId('material_id')->nullable()->constrained('tbl_materials', 'material_id')->nullOnDelete();
            $table->string('description');
            $table->string('unit', 20)->nullable();
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 12, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_quotation_items');
    }
};
