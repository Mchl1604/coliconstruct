<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Puts Quantity back on the shipped Installation and Cleaning forms, the two
 * that had it before it was retired, keeping its place and its saved values.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->field()->update(['archived_at' => null]);
    }

    public function down(): void
    {
        $this->field()->whereNull('archived_at')->update(['archived_at' => now()]);
    }

    private function field(): Builder
    {
        $formIds = DB::table('tbl_detail_forms')
            ->whereIn(DB::raw('LOWER(name)'), ['installation', 'cleaning'])
            ->pluck('form_id');

        return DB::table('tbl_detail_form_fields')
            ->whereIn('form_id', $formIds)
            ->where(DB::raw('LOWER(label)'), 'quantity');
    }
};
