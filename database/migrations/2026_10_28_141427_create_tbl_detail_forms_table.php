<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The detail forms a project type asks for when a project is created -
 * Installation, Repair, Cleaning, or one the Super Admin builds. Together
 * they are the project's description; see tbl_detail_form_fields for which
 * fields are filled once and which once per unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_detail_forms', function (Blueprint $table): void {
            $table->id('form_id');
            $table->string('name', 100)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_detail_forms');
    }
};
