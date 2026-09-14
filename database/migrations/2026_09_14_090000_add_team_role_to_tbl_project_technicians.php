<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The role a technician held on a project, kept on the membership itself.
 *
 * A project's lead is not stored anywhere: it is whichever member's ACCOUNT
 * role is Lead Technician - see Project::leadAssignment(). That is the right
 * answer for a live project, where the role and the lead are kept the same
 * fact by TechnicianRoleChangeRules. It is the wrong answer for a record. The
 * guard only protects live work, so demoting a lead after their projects are
 * finished quietly re-labelled every one of those projects: the team history
 * stopped calling them the lead, the completed project's Assigned Team listed
 * them as a plain technician, and the client's page named no lead at all.
 * Nothing about the job had changed - only somebody's job title, later.
 *
 * So each membership now carries the role it was opened with. A settled
 * membership - closed, or on a project that is finished or archived - reads
 * this column; a live one still reads the account, exactly as before.
 *
 * Backfilled from the account role as it stands now. For every live project
 * that is exact: the role-change guard has kept the two in step. For finished
 * work it is the best record there is - a lead demoted before this column
 * existed cannot be recovered, because nothing else remembers them. An account
 * that no longer holds a technician role is left null, which reads as "not
 * recorded" rather than as a guess.
 */
return new class extends Migration
{
    private const TECHNICIAN_ROLES = ['technician', 'lead_technician'];

    public function up(): void
    {
        Schema::table('tbl_project_technicians', function (Blueprint $table) {
            $table->string('team_role', 30)->nullable()->after('technician_id');
        });

        $roles = DB::table('tbl_technicians')
            ->join('users', 'users.id', '=', 'tbl_technicians.account_id')
            ->whereIn('users.role', self::TECHNICIAN_ROLES)
            ->pluck('users.role', 'tbl_technicians.technician_id');

        foreach ($roles as $technicianId => $role) {
            DB::table('tbl_project_technicians')
                ->where('technician_id', $technicianId)
                ->update(['team_role' => $role]);
        }
    }

    public function down(): void
    {
        Schema::table('tbl_project_technicians', function (Blueprint $table) {
            $table->dropColumn('team_role');
        });
    }
};
