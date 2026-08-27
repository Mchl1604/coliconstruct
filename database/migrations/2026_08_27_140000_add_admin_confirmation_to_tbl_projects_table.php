<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client's confirmation that did not arrive through the website.
 *
 * Clients confirm by phone, in person, and on paper, and until now none of
 * those could be recorded: the only ways out of Awaiting Client Confirmation
 * were the client pressing the button themselves or seven days elapsing. A
 * project whose client had already said "yes, it's finished" still sat waiting
 * out a clock, and the fact that they had said so was written down nowhere.
 *
 * These columns are what an administrator recording that adds, and they are
 * deliberately separate from the ones already there:
 *
 *   - `client_confirmed_at` keeps its meaning - when the confirmation became
 *     official - and is now the date the ADMINISTRATOR was told the client
 *     confirmed, which may be days before anybody typed it in. Same column,
 *     same meaning, filled from a form rather than from the clock.
 *   - `client_confirmed_by` keeps its meaning too: the client's own account,
 *     and null when there isn't one. It is never filled with an administrator,
 *     because "who confirmed" and "who wrote it down" are two different people
 *     and one column cannot answer both.
 *
 * Hence `client_confirmation_recorded_by` and `_recorded_at`: the second pair,
 * for the second person. An audit that could not tell them apart would be
 * unable to answer the only question worth asking about an off-site
 * confirmation - who says this happened?
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table): void {
            // How the client confirmed: phone, in person, on paper, or another
            // channel the administrator names in the note. Null on every
            // confirmation that came through the website, which is what tells
            // the two apart alongside completion_method.
            $table->string('client_confirmation_channel', 32)->nullable()->after('completion_method');

            // Why this is being recorded by hand. Required of the
            // administrator by the form, nullable here because every project
            // confirmed the ordinary way has nothing to say.
            $table->text('client_confirmation_note')->nullable()->after('client_confirmation_channel');

            // The administrator, and the moment they wrote it down. Taken from
            // the session rather than from the form - see
            // ProjectController::recordClientConfirmation(), which never lets
            // anybody nominate somebody else.
            $table->unsignedBigInteger('client_confirmation_recorded_by')->nullable()->after('client_confirmation_note');
            $table->timestamp('client_confirmation_recorded_at')->nullable()->after('client_confirmation_recorded_by');

            // Matches the confirmation columns beside it: the trail survives
            // the account being removed, because the activity log carries the
            // name snapshot.
            $table->foreign('client_confirmation_recorded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table): void {
            $table->dropForeign(['client_confirmation_recorded_by']);

            $table->dropColumn([
                'client_confirmation_channel',
                'client_confirmation_note',
                'client_confirmation_recorded_by',
                'client_confirmation_recorded_at',
            ]);
        });
    }
};
