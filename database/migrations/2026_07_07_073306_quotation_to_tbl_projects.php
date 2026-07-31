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
        // An earlier migration already adds this column; guard so a fresh
        // migrate (tests, new environments) doesn't fail on a duplicate.
        if (Schema::hasColumn('tbl_projects', 'quotation')) {
            return;
        }

        Schema::table('tbl_projects', function (Blueprint $table) {
            $table->decimal('quotation', 15, 2)->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('tbl_projects', 'quotation')) {
            return;
        }

        Schema::table('tbl_projects', function (Blueprint $table) {
            $table->dropColumn('quotation');
        });
    }
};
