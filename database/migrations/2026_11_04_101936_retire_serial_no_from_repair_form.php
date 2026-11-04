<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Takes Serial no. off the shipped Repair form. Archived rather than deleted:
 * details already saved on a project keep their own labels and values.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->field()->whereNull('archived_at')->update(['archived_at' => now()]);
    }

    public function down(): void
    {
        $this->field()->update(['archived_at' => null]);
    }

    private function field(): Builder
    {
        $formIds = DB::table('tbl_detail_forms')
            ->where(DB::raw('LOWER(name)'), 'repair')
            ->pluck('form_id');

        return DB::table('tbl_detail_form_fields')
            ->whereIn('form_id', $formIds)
            ->where(DB::raw('LOWER(label)'), 'serial no.');
    }
};
