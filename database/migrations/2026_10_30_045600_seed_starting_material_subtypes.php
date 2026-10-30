<?php

use App\Models\MaterialSubtype;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives the material types already on the list their starting sub-types, and
 * files the materials already listed under them by name - see
 * MaterialSubtype::STARTING_LIST.
 *
 * Only for types that exist and only for materials with no sub-type yet, so
 * a list Purchasing has already arranged is left as it is. A material whose
 * name matches nothing keeps none.
 */
return new class extends Migration
{
    public function up(): void
    {
        MaterialSubtype::applyStartingList();
    }

    public function down(): void
    {
        DB::table('tbl_materials')->update(['material_subtype_id' => null]);
        DB::table('tbl_material_subtypes')->delete();
    }
};
