<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The quotation's discounts, its printed terms and signatories, and the date
 * it was last quoted. The quotation column stays the amount owed: the lines
 * less both discounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table) {
            $table->decimal('quotation_discount_percent', 5, 2)->default(0)->after('quotation');
            $table->decimal('quotation_special_discount', 12, 2)->default(0)->after('quotation_discount_percent');
            $table->text('quotation_notes')->nullable()->after('quotation_special_discount');
            $table->text('quotation_inclusions')->nullable()->after('quotation_notes');
            $table->text('quotation_exclusions')->nullable()->after('quotation_inclusions');
            $table->text('quotation_payment_terms')->nullable()->after('quotation_exclusions');
            $table->string('quotation_prepared_by', 100)->nullable()->after('quotation_payment_terms');
            $table->string('quotation_approved_by', 100)->nullable()->after('quotation_prepared_by');
            $table->timestamp('quoted_at')->nullable()->after('quotation_approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table) {
            $table->dropColumn([
                'quotation_discount_percent',
                'quotation_special_discount',
                'quotation_notes',
                'quotation_inclusions',
                'quotation_exclusions',
                'quotation_payment_terms',
                'quotation_prepared_by',
                'quotation_approved_by',
                'quoted_at',
            ]);
        });
    }
};
