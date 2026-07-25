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
        Schema::create('tbl_project_completion_photos', function (Blueprint $table): void {
            $table->id('completion_photo_id');
            $table->unsignedBigInteger('project_id');
            $table->string('photo_path');
            $table->timestamp('uploaded_at')->nullable();

            $table->foreign('project_id')
                ->references('project_id')
                ->on('tbl_projects')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_project_completion_photos');
    }
};
