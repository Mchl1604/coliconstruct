<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every movement of a project's materials after setup: more added, some used,
 * some returned, and what was left over when the project closed.
 *
 * Append-only. A mistake is voided, not edited or deleted, so the totals a
 * project shows can always be traced back to the entries that make them up.
 * The totals themselves are never stored - see ProjectMaterialLedger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_project_material_entries', function (Blueprint $table): void {
            $table->id('material_entry_id');

            $table->foreignId('project_id')->constrained('tbl_projects', 'project_id')->cascadeOnDelete();
            $table->foreignId('project_material_id')
                ->constrained('tbl_project_materials', 'project_material_id')
                ->cascadeOnDelete();

            // added, used, returned or unused - see ProjectMaterialEntry.
            $table->string('entry_type', 20);
            $table->decimal('quantity', 12, 2);
            $table->date('entry_date');
            $table->string('notes', 255)->nullable();

            // Null for the leftovers the system records when a project
            // closes. The name is snapshotted so the entry keeps reading
            // correctly after the account changes, as the other trails do.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorded_by_name')->nullable();

            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['project_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_project_material_entries');
    }
};
