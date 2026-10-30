<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a project's detail forms were filled in with: for each type, one
 * "project" row for its once-per-project fields and one "unit" row per unit.
 *
 * values is keyed by field_key. labels is the form's labels as they were when
 * the row was saved, so a renamed or archived field still reads correctly on
 * the projects that used it. form_id is nulled rather than cascaded, because
 * the row stands on its own once saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_project_details', function (Blueprint $table): void {
            $table->id('detail_id');
            $table->foreignId('project_id')->constrained('tbl_projects', 'project_id')->cascadeOnDelete();
            $table->foreignId('type_id')->constrained('tbl_project_types', 'type_id')->cascadeOnDelete();
            $table->foreignId('form_id')->nullable()->constrained('tbl_detail_forms', 'form_id')->nullOnDelete();
            $table->string('scope', 10)->default('unit');
            $table->json('values');
            $table->json('labels');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_project_details');
    }
};
