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
        Schema::create('tbl_technicians', function (Blueprint $table) {
            $table->id('technician_id');
            $table->foreignId('account_id')->constrained('users')->cascadeOnDelete();
            $table->string('role');
            $table->string('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_technicians');
    }
};
