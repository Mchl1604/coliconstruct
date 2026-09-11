<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every change made to a project's files: each one uploaded, replaced or
 * removed, of every document type.
 *
 * tbl_documents says what a project holds now, and a removed file leaves no
 * row there at all. This is the record of how it got that way - which file,
 * what happened to it, who and when - written in the same transaction as the
 * change itself, so a save that rolls back leaves nothing behind here either.
 *
 * document_id is deliberately not a foreign key. The entry for a removed file
 * has to outlive the row it describes, which is the same stance
 * tbl_schedule_corrections takes toward its schedule; the name and type are
 * snapshotted for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_document_history', function (Blueprint $table): void {
            $table->id('document_history_id');

            $table->foreignId('project_id')->constrained('tbl_projects', 'project_id')->cascadeOnDelete();
            $table->unsignedBigInteger('document_id')->nullable();

            $table->string('document_type');
            $table->string('document_name');

            // uploaded, replaced or removed - see DocumentHistory.
            $table->string('event', 20);

            // Who made it, snapshotted beside the id so the entry keeps
            // reading correctly after the account is renamed or removed.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('actor_role', 30)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['project_id', 'document_type', 'created_at']);
            $table->index(['document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_document_history');
    }
};
