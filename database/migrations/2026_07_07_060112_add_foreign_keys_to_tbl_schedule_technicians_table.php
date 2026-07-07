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
        Schema::table('tbl_schedule_technicians', function (Blueprint $table) {
            $table->foreign('schedule_id')->references('schedule_id')->on('tbl_schedule')->cascadeOnDelete();
            $table->foreign('project_technician_id')->references('project_technician_id')->on('tbl_project_technicians')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_schedule_technicians', function (Blueprint $table) {
            $table->dropForeign(['schedule_id']);
            $table->dropForeign(['project_technician_id']);
        });
    }
};
