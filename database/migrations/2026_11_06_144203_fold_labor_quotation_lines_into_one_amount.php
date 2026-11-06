<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Labor is one amount on a quotation: a line saved with a count becomes a
 * single line of the same total.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tbl_quotation_items')
            ->where('kind', 'labor')
            ->where('quantity', '!=', 1)
            ->update([
                'unit_price' => DB::raw('ROUND(quantity * unit_price, 2)'),
                'quantity' => 1,
            ]);
    }

    public function down(): void
    {
        //
    }
};
