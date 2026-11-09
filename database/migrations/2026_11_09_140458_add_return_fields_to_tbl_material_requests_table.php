<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lead's return waits on the inventory side as a request, like a request
 * for more: what kind it is, which line it comes off, the day it went back
 * and the lead's note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_material_requests', function (Blueprint $table) {
            $table->string('request_type', 20)->default('supply')->after('material_id');
            $table->unsignedBigInteger('project_material_id')->nullable()->after('request_type');
            $table->date('entry_date')->nullable()->after('quantity');
            $table->string('notes', 255)->nullable()->after('entry_date');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_material_requests', function (Blueprint $table) {
            $table->dropColumn(['request_type', 'project_material_id', 'entry_date', 'notes']);
        });
    }
};
