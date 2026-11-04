<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Takes Quantity, Model, Equipment tag and the indoor and outdoor serial numbers
 * off the shipped aircon forms. Archived rather than deleted: details already
 * saved on a project keep their own labels and values.
 */
return new class extends Migration
{
    private const FORMS = ['installation', 'repair', 'cleaning'];

    private const LABELS = ['quantity', 'model', 'equipment tag', 'indoor serial no.', 'outdoor serial no.'];

    public function up(): void
    {
        $this->fields()->whereNull('archived_at')->update(['archived_at' => now()]);
    }

    public function down(): void
    {
        $this->fields()->update(['archived_at' => null]);
    }

    private function fields(): Builder
    {
        $formIds = DB::table('tbl_detail_forms')
            ->whereIn(DB::raw('LOWER(name)'), self::FORMS)
            ->pluck('form_id');

        return DB::table('tbl_detail_form_fields')
            ->whereIn('form_id', $formIds)
            ->whereIn(DB::raw('LOWER(label)'), self::LABELS);
    }
};
