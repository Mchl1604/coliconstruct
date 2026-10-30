<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fields of one detail form, in order.
 *
 * scope is "project" for a field filled once for the whole job - a trouble
 * description, the warranty - and "unit" for one filled for every unit on it,
 * the way the paper service report lists each aircon.
 *
 * field_key is what a project's saved values are keyed on, so it never
 * changes once the field exists - renaming changes the label only. A field a
 * project has already been saved with is archived rather than deleted, so the
 * project can still say what it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_detail_form_fields', function (Blueprint $table): void {
            $table->id('field_id');
            $table->foreignId('form_id')->constrained('tbl_detail_forms', 'form_id')->cascadeOnDelete();
            $table->string('field_key', 60);
            $table->string('label', 100);
            $table->string('input_type', 20);
            $table->string('scope', 10)->default('unit');
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['form_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_detail_form_fields');
    }
};
