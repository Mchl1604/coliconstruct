<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Labor is a cost, not a count of anything, so its line has no unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tbl_quotation_items')->where('kind', 'labor')->update(['unit' => null]);
    }

    public function down(): void
    {
        //
    }
};
