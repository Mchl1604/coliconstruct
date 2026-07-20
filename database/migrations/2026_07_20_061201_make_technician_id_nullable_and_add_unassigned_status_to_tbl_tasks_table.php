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
        Schema::table('tbl_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('technician_id')
                ->nullable()
                ->change();

            $table->enum('status', [
                'unassigned',
                'pending',
                'ongoing',
                'completed',
                'cancelled',
            ])->default('unassigned')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('technician_id')
                ->nullable(false)
                ->change();

            $table->enum('status', [
                'pending',
                'ongoing',
                'completed',
                'cancelled',
            ])->default('pending')->change();
        });
    }
};
