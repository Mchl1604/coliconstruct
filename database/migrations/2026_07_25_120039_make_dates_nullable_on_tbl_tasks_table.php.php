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
        Schema::table('tbl_tasks', function (Blueprint $table): void {
            $table->date('start_date')->nullable()->change();
            $table->date('due_date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_tasks', function (Blueprint $table): void {
            $table->date('start_date')->nullable(false)->change();
            $table->date('due_date')->nullable(false)->change();
        });
    }
};
