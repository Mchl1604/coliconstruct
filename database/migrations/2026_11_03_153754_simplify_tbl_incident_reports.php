<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The form no longer asks how severe an incident was, and a list answered
     * Other now says what.
     */
    public function up(): void
    {
        Schema::table('tbl_incident_reports', function (Blueprint $table): void {
            $table->string('severity', 20)->nullable()->change();
            $table->json('other_details')->nullable()->after('client_notified');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_incident_reports', function (Blueprint $table): void {
            $table->dropColumn('other_details');
        });
    }
};
