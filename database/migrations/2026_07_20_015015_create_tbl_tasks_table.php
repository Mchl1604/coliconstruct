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
        Schema::create('tbl_tasks', function (Blueprint $table) {
    $table->id('task_id');

    $table->foreignId('project_id')
        ->constrained('tbl_projects', 'project_id')
        ->cascadeOnDelete();

    $table->foreignId('technician_id')
        ->constrained('tbl_technicians', 'technician_id')
        ->cascadeOnDelete();

    $table->string('task_title');
    $table->text('task_description');

    $table->date('start_date');
    $table->date('due_date');

    $table->enum('status', [
        'pending',
        'ongoing',
        'completed',
        'cancelled'
    ])->default('pending');

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_tasks');
    }
};
