<?php

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Entries written before the audit-context columns existed have no module and
 * no actor role, so the Activity Logs filters - which narrow in SQL - would
 * skip them and the table would label every one of them "System".
 *
 * The module is derivable from the action, and the role from the account that
 * is still on file. Neither is a perfect reconstruction (a since-promoted user
 * backfills as their current role), but it beats leaving history unfilterable.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (ActivityLog::MODULE_FOR as $action => $module) {
            DB::table('tbl_activity_logs')
                ->whereNull('module')
                ->where('action', $action)
                ->update(['module' => $module]);
        }

        // Anything left is an action no longer in the map; file it where the
        // model's own fallback would put it.
        DB::table('tbl_activity_logs')
            ->whereNull('module')
            ->update(['module' => ActivityLog::MODULE_CONFIGURATION]);

        foreach (User::query()->select('id', 'role')->cursor() as $user) {
            DB::table('tbl_activity_logs')
                ->whereNull('actor_role')
                ->where('actor_id', $user->id)
                ->update(['actor_role' => $user->role]);
        }
    }

    public function down(): void
    {
        // The original state was "unknown", which is not worth restoring.
    }
};
