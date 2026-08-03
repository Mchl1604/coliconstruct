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
        Schema::create('tbl_technician_reports', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('project_id');

            $table->foreign('project_id')
                ->references('project_id')
                ->on('tbl_projects')
                ->onDelete('cascade');

            // A tbl_technicians id, not a users id - TechnicianReport::technician()
            // and every form that writes this column agree on that. Corrected here
            // so a fresh database is right from the start; databases created before
            // the fix are repaired by the later repointing migration.
            $table->unsignedBigInteger('technician_id');
            $table->foreign('technician_id')
                ->references('technician_id')
                ->on('tbl_technicians')
                ->onDelete('cascade');

            $table->string('report_title')->nullable();
            $table->text('report_description');
            $table->date('report_date');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_technician_reports');
    }
};
