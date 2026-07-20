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
            $table->boolean('on_hold')->default(false)->after('status');
            $table->boolean('is_archived')->default(false)->after('on_hold');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table) {
            $table->dropColumn(['on_hold', 'is_archived']);
        });
    }
};
