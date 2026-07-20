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
        Schema::create('tbl_technician_report_images', function (Blueprint $table) {
    $table->id();

    $table->foreignId('technician_report_id')
        ->constrained('tbl_technician_reports')
        ->cascadeOnDelete();

    $table->string('image_path');

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_technician_report_images');
    }
};
