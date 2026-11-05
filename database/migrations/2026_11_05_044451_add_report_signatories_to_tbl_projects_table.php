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
        Schema::table('tbl_projects', function (Blueprint $table) {
            $table->string('report_performed_by', 150)->nullable();
            $table->string('report_witnessed_by', 150)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table) {
            $table->dropColumn(['report_performed_by', 'report_witnessed_by']);
        });
    }
};
