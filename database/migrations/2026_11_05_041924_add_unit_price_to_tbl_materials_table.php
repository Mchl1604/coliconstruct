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
        Schema::table('tbl_materials', function (Blueprint $table) {
            $table->decimal('unit_price', 12, 2)->nullable()->after('reorder_level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_materials', function (Blueprint $table) {
            $table->dropColumn('unit_price');
        });
    }
};
