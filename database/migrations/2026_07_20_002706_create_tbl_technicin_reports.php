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

    $table->unsignedBigInteger('technician_id');
$table->foreign('technician_id')
    ->references('id')
    ->on('users')
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
