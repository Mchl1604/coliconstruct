<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tbl_material_requests', function (Blueprint $table) {
            $table->id('material_request_id');
            $table->foreignId('project_id')->constrained('tbl_projects', 'project_id')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('tbl_materials', 'material_id')->restrictOnDelete();
            $table->decimal('quantity', 10, 2);
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->foreignId('project_material_entry_id')->nullable()
                ->constrained('tbl_project_material_entries', 'material_entry_id')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_material_requests');
    }
};
