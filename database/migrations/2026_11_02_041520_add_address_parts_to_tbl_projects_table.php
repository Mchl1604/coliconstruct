<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project's address, part by part.
 *
 * The `address` column stays: it is the one line every page, report and email
 * prints, now composed from these parts - see ProjectAddress. Null until a
 * project's old free-text address has been split or re-entered - see
 * SplitProjectAddresses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table) {
            $table->string('address_province', 100)->nullable()->after('address');
            $table->string('address_municipality', 100)->nullable()->after('address_province');
            $table->string('address_barangay', 150)->nullable()->after('address_municipality');
            $table->string('address_street')->nullable()->after('address_barangay');
            $table->string('address_house_no', 50)->nullable()->after('address_street');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table) {
            $table->dropColumn([
                'address_province',
                'address_municipality',
                'address_barangay',
                'address_street',
                'address_house_no',
            ]);
        });
    }
};
