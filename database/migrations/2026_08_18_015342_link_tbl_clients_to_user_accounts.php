<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which account a project's client contact belongs to.
 *
 * Ownership used to be derived entirely from the email address: a project was
 * yours if its contact row carried the same address as your account. That
 * works right up until somebody changes their address, at which point every
 * project they own silently disappears from My Projects - the account and the
 * contact row no longer match, and nothing links them any other way.
 *
 * An address is a detail about a person, not a name for them. So the link is
 * recorded once, here, and the address goes back to being a detail: change it
 * and the projects come along, because they were never held by it.
 *
 * The email match is kept as a fallback rather than removed, and deliberately:
 * a project is very often booked before its client opens an account, and the
 * address is the only thing connecting the two until they do. Registering is
 * what fills this column in - see UserAccountService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_clients', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->after('project_id');

            // Null rather than cascade: deleting an account must not take a
            // project's contact details with it. The row keeps its name,
            // address and number, and falls back to matching on the email.
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index('user_id');
        });

        // Every contact that already has an account behind it, linked on the
        // address as it stands today - which is correct precisely because
        // nothing has changed an address yet.
        //
        // Walked per account rather than done as one UPDATE ... JOIN: that
        // syntax is MySQL's, and the test suite runs these migrations on
        // SQLite. Chunked so the memory cost does not grow with the table.
        DB::table('users')
            ->where('role', 'client')
            ->orderBy('id')
            ->chunkById(200, function ($accounts): void {
                foreach ($accounts as $account) {
                    $address = mb_strtolower(trim((string) $account->email));

                    if ($address === '') {
                        continue;
                    }

                    DB::table('tbl_clients')
                        ->whereNull('user_id')
                        ->whereRaw('LOWER(TRIM(email_address)) = ?', [$address])
                        ->update(['user_id' => $account->id]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('tbl_clients', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
