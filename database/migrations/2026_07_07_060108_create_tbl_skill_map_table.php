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
        Schema::create('tbl_skill_map', function (Blueprint $table) {
            $table->unsignedBigInteger('technician_id');
            $table->unsignedBigInteger('skill_id');

            $table->primary(['technician_id', 'skill_id']);

            $table->foreign('technician_id')->references('technician_id')->on('tbl_technicians')->cascadeOnDelete();
            $table->foreign('skill_id')->references('skill_id')->on('tbl_skills')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_skill_map');
    }
};
