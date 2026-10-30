<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brands become a list of their own, kept on the Inventory page beside the
 * material types, instead of whatever was typed into each material.
 *
 * Every brand already written on a material is carried over - once, whatever
 * its casing, so "Daikin" and "DAIKIN" become one brand - and the material is
 * pointed at it. The old text column goes once nothing reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_brands', function (Blueprint $table): void {
            $table->id('brand_id');
            $table->string('brand_name', 100)->unique();
            $table->timestamps();
        });

        Schema::table('tbl_materials', function (Blueprint $table): void {
            // Optional, as the text was: a length of PVC pipe has no brand
            // worth naming. Restricted, so a brand still on a material
            // cannot be deleted out from under it.
            $table->foreignId('brand_id')
                ->nullable()
                ->after('material_type_id')
                ->constrained('tbl_brands', 'brand_id')
                ->restrictOnDelete();
        });

        $brands = DB::table('tbl_materials')
            ->whereNotNull('brand')
            ->pluck('brand')
            ->map(fn (string $brand): string => trim((string) preg_replace('/\s+/u', ' ', $brand)))
            ->filter()
            ->unique(fn (string $brand): string => mb_strtolower($brand))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($brands as $brand) {
            $brandId = DB::table('tbl_brands')->insertGetId([
                'brand_name' => $brand,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('tbl_materials')
                ->whereRaw('LOWER(TRIM(brand)) = ?', [mb_strtolower($brand)])
                ->update(['brand_id' => $brandId]);
        }

        Schema::table('tbl_materials', function (Blueprint $table): void {
            $table->dropColumn('brand');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_materials', function (Blueprint $table): void {
            $table->string('brand', 100)->nullable()->after('name');
        });

        DB::table('tbl_brands')->orderBy('brand_id')->each(function (object $brand): void {
            DB::table('tbl_materials')
                ->where('brand_id', $brand->brand_id)
                ->update(['brand' => $brand->brand_name]);
        });

        Schema::table('tbl_materials', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('brand_id');
        });

        Schema::dropIfExists('tbl_brands');
    }
};
