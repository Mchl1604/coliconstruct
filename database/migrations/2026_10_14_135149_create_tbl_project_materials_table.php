<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The materials a project needs, one row per material.
 *
 * quantity_needed is the plan agreed at setup and is never edited once setup
 * is finalized. Everything after that - more brought in, used, returned, left
 * over - is an entry in tbl_project_material_entries, so the original plan
 * and what actually happened can always be read side by side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_project_materials', function (Blueprint $table): void {
            $table->id('project_material_id');

            $table->foreignId('project_id')->constrained('tbl_projects', 'project_id')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('tbl_materials', 'material_id')->restrictOnDelete();

            $table->decimal('quantity_needed', 12, 2)->default(0);
            $table->string('notes', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['project_id', 'material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_project_materials');
    }
};
