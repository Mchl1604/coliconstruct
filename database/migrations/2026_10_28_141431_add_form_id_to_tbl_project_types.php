<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which detail form a project type asks for. Null is "None": the type adds
 * nothing to the create wizard's Units step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_project_types', function (Blueprint $table): void {
            $table->foreignId('form_id')
                ->nullable()
                ->after('type_name')
                ->constrained('tbl_detail_forms', 'form_id')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_project_types', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('form_id');
        });
    }
};
