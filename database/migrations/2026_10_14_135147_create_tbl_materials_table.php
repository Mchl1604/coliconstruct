<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The material list: what a project may say it needs.
 *
 * Deliberately not an inventory. There is no stock, cost or supplier here -
 * only the name, brand, group and unit a project's material plan picks from,
 * so the same item is spelled the same way on every job.
 *
 * A material that has been used on a project is deactivated rather than
 * deleted: the projects that used it still need to say what it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_materials', function (Blueprint $table): void {
            $table->id('material_id');

            $table->foreignId('material_type_id')
                ->constrained('tbl_material_types', 'material_type_id')
                ->restrictOnDelete();

            $table->string('name', 150);
            // Optional: a length of PVC pipe has no brand worth naming, an
            // aircon unit is chosen by it.
            $table->string('brand', 100)->nullable();
            // A MaterialUnit value.
            $table->string('unit', 20);
            // Size, capacity or model notes - "R32, wall-mounted".
            $table->string('description', 255)->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Set when a lead or an administrator added it from a project
            // rather than from System Settings.
            $table->foreignId('created_from_project_id')
                ->nullable()
                ->constrained('tbl_projects', 'project_id')
                ->nullOnDelete();

            $table->timestamps();

            // Name, brand and unit together are unique, but brand is nullable
            // and a null never collides in a unique index - so the rule is
            // enforced by MaterialCatalog and this is only for the search.
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_materials');
    }
};
