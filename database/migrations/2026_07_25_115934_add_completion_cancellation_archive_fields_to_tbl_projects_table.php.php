<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table): void {
            // Completion report
            $table->timestamp('completed_at')->nullable()->after('is_archived');
            $table->text('completion_summary')->nullable()->after('completed_at');
            $table->text('completion_remarks')->nullable()->after('completion_summary');

            // Cancellation report
            $table->timestamp('cancelled_at')->nullable()->after('completion_remarks');
            $table->string('cancellation_reason')->nullable()->after('cancelled_at');
            $table->text('cancellation_remarks')->nullable()->after('cancellation_reason');

            // Archive metadata (is_archived already exists)
            $table->timestamp('archived_at')->nullable()->after('cancellation_remarks');
            $table->unsignedBigInteger('archived_by')->nullable()->after('archived_at');

            $table->foreign('archived_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table): void {
            $table->dropForeign(['archived_by']);

            $table->dropColumn([
                'completed_at',
                'completion_summary',
                'completion_remarks',
                'cancelled_at',
                'cancellation_reason',
                'cancellation_remarks',
                'archived_at',
                'archived_by',
            ]);
        });
    }
};
