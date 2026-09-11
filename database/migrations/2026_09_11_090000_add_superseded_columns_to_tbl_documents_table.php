<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quotation file that has been replaced, kept rather than deleted.
 *
 * Uploading a quotation now replaces the one on record instead of sitting
 * beside it - a project whose amount says one thing and whose newest file
 * says another is exactly the mismatch the quotation prompt exists to stop.
 * The file it replaces is still a record of what the client was once quoted,
 * so the row and the bytes both stay: the row is marked superseded, drops out
 * of every list that reads "the project's documents", and is read from the
 * quotation history instead.
 *
 * Null means current, which is what every existing row is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_documents', function (Blueprint $table): void {
            $table->timestamp('superseded_at')->nullable()->after('uploaded_at');
            $table->foreignId('superseded_by')->nullable()->after('superseded_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['project_id', 'document_type', 'superseded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tbl_documents', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'document_type', 'superseded_at']);
            $table->dropConstrainedForeignId('superseded_by');
            $table->dropColumn('superseded_at');
        });
    }
};
