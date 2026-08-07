<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who archived an account.
 *
 * `archived_at` already recorded when, but the Archived Accounts table has to
 * name the administrator responsible, and the activity log alone cannot answer
 * it for a row - a trail is searched, a column is joined.
 *
 * Nullable and nullOnDelete for the same reason `created_by` is: an account
 * archived before this column existed has no answer, and the administrator who
 * archived one may themselves be removed later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('archived_by')->nullable()->after('archived_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['archived_by']);
            $table->dropColumn('archived_by');
        });
    }
};
