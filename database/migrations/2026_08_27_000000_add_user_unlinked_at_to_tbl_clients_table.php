<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an administrator took the Registered User off a project.
 *
 * `user_id` records which account a project's contact belongs to, and a null
 * there has always meant "nobody has registered under this address yet" - which
 * is why the address is still matched as a fallback (see ClientProjects).
 *
 * Removing an assignment by hand produces the same null and means the opposite
 * thing: somebody looked at this project and decided that account does not own
 * it. Without a way to tell the two apart the removal would not survive the
 * next page load - the address fallback would hand the project straight back,
 * and registering again would refill the column.
 *
 * So the decision is recorded rather than inferred. A timestamp rather than a
 * flag, because "when was this account taken off?" is the question asked next,
 * and it is cleared the moment an assignment is made again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_clients', function (Blueprint $table): void {
            $table->timestamp('user_unlinked_at')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_clients', function (Blueprint $table): void {
            $table->dropColumn('user_unlinked_at');
        });
    }
};
