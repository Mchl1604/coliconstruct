<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every change made to a project's quotation amount.
 *
 * The amount is edited in place on tbl_projects, so without this the figure a
 * client was first quoted is gone the moment somebody saves a new one. The
 * activity log carries a sentence about the save; this table carries the
 * facts - the amount before, the amount after, who and when - so the history
 * dialog can list them without parsing prose.
 *
 * One row per save that actually changed the amount. Saving the same figure
 * again writes nothing, and neither does replacing only the file:
 * `file_replaced` records whether the file went with the amount in the same
 * save, not a history of the file on its own - superseded rows on
 * tbl_documents are that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_quotation_history', function (Blueprint $table): void {
            $table->id('quotation_history_id');

            $table->foreignId('project_id')->constrained('tbl_projects', 'project_id')->cascadeOnDelete();

            // Null before means the project had no amount on record - an old
            // project created before the quotation column was required.
            $table->decimal('previous_amount', 15, 2)->nullable();
            $table->decimal('new_amount', 15, 2);

            $table->boolean('file_replaced')->default(false);

            // Who made it, snapshotted beside the id for the same reason the
            // activity log snapshots its actor: the entry has to keep reading
            // correctly after the account is renamed, demoted or removed.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('actor_role', 30)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_quotation_history');
    }
};
