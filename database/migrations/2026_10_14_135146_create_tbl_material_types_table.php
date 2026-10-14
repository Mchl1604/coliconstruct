<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The groups the material list is sorted into - Aircon Units, Refrigerants,
 * Electrical and so on.
 *
 * A table of its own rather than free text on each material, so the list
 * filters on one spelling of each group and a lead adding a material from a
 * project picks from the same groups the Super Admin keeps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_material_types', function (Blueprint $table): void {
            $table->id('material_type_id');
            $table->string('type_name', 100)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_material_types');
    }
};
