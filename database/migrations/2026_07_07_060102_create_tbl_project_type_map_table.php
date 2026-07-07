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
        Schema::create('tbl_project_type_map', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('type_id');

            $table->primary(['project_id', 'type_id']);

            $table->foreign('project_id')->references('project_id')->on('tbl_projects')->cascadeOnDelete();
            $table->foreign('type_id')->references('type_id')->on('tbl_project_types')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_project_type_map');
    }
};
