<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The demo data credited project file uploads to HR Admin accounts, who
 * cannot upload or edit a project's files. Those entries are moved to the
 * office account that creates projects - an Admin Officer, or else the Super
 * Admin - which is who the seeder credits now.
 */
return new class extends Migration
{
    public function up(): void
    {
        $office = User::query()
            ->whereIn('role', [User::ROLE_ADMIN_OFFICER, User::ROLE_SUPER_ADMIN])
            ->orderByRaw('role = ? desc', [User::ROLE_ADMIN_OFFICER])
            ->orderBy('id')
            ->first();

        if ($office === null) {
            return;
        }

        DB::table('tbl_document_history')
            ->where('actor_role', User::ROLE_ADMIN)
            ->update([
                'actor_id' => $office->id,
                'actor_name' => $office->fullName(),
                'actor_role' => $office->role,
            ]);
    }

    /**
     * Who the entries used to name is not kept, so there is nothing to undo.
     */
    public function down(): void {}
};
