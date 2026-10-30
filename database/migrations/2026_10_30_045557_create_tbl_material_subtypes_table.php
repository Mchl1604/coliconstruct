<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sub-types: the kinds within a material type - Split-Type, Window-Type and
 * Ceiling Cassette under Aircon Units; Copper Tube and Fittings under Copper
 * & Fittings.
 *
 * Each belongs to one type, so the same name may appear under two types and
 * mean two different things. Optional on a material: a type with no
 * sub-types, or a material that fits none of them, simply has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_material_subtypes', function (Blueprint $table): void {
            $table->id('material_subtype_id');

            // A type is only removed once it holds no materials, so its
            // sub-types go with it.
            $table->foreignId('material_type_id')
                ->constrained('tbl_material_types', 'material_type_id')
                ->cascadeOnDelete();

            $table->string('subtype_name', 100);

            $table->timestamps();

            $table->unique(['material_type_id', 'subtype_name']);
        });

        Schema::table('tbl_materials', function (Blueprint $table): void {
            // Restricted, so a sub-type still on a material cannot be deleted
            // out from under it.
            $table->foreignId('material_subtype_id')
                ->nullable()
                ->after('material_type_id')
                ->constrained('tbl_material_subtypes', 'material_subtype_id')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_materials', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('material_subtype_id');
        });

        Schema::dropIfExists('tbl_material_subtypes');
    }
};
