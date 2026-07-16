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
        Schema::table('tbl_clients', function (Blueprint $table) {
            $table->renameColumn('lastname', 'fullname');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_clients', function (Blueprint $table) {
            $table->renameColumn('fullname', 'lastname');
        });
        
    }
};
