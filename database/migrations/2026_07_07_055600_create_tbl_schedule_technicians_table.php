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
        if (! Schema::hasTable('tbl_schedule_technicians')) {
            Schema::create('tbl_schedule_technicians', function (Blueprint $table) {
                $table->id('schedule_technician_id');
                $table->unsignedBigInteger('schedule_id');
                $table->unsignedBigInteger('project_technician_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_schedule_technicians');
    }
};
