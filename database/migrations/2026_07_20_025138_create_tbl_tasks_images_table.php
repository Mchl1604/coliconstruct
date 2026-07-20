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
         Schema::create('tbl_task_images', function (Blueprint $table) {

            $table->id();

            $table->unsignedBigInteger('task_id');

            $table->string('image_path');

            $table->timestamps();

            $table->foreign('task_id')
                ->references('task_id')
                ->on('tbl_tasks')
                ->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_tasks_images');
    }
};
