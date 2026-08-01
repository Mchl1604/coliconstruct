<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything Configuration > User Management needs on top of the original
 * users table.
 *
 * `name` is deliberately left in place and kept in sync with the new name
 * parts: technician listings, report joins and the topbar all read it, so
 * dropping it would break them. The parts exist so the edit form can offer
 * First / Middle / Last without re-parsing a display string every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The immutable public identifier - EMP-0001 / CLI-0001. Nullable
            // only so the backfill below can run; every row has one after it.
            $table->string('user_code', 20)->nullable()->after('id');

            $table->string('first_name')->nullable()->after('name');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('last_name')->nullable()->after('middle_name');
            $table->string('contact_number', 32)->nullable()->after('last_name');
            $table->string('profile_photo_path')->nullable()->after('contact_number');

            // Employees only.
            $table->string('position')->nullable()->after('profile_photo_path');

            // Clients only.
            $table->string('company_name')->nullable()->after('position');
            $table->string('company_address')->nullable()->after('company_name');

            $table->string('status', 20)->default('active')->after('role');
            $table->boolean('is_archived')->default(false)->after('status');
            $table->timestamp('archived_at')->nullable()->after('is_archived');

            // Forces the first-login password change for generated credentials.
            $table->boolean('must_change_password')->default(false)->after('archived_at');
            $table->timestamp('last_login_at')->nullable()->after('must_change_password');

            $table->foreignId('created_by')->nullable()->after('last_login_at')
                ->constrained('users')->nullOnDelete();

            // Both tables filter by role + archived + status, so one composite
            // index serves the employee list and the client list alike.
            $table->index(['role', 'is_archived', 'status'], 'users_role_archived_status_index');
        });

        $this->backfillExistingAccounts();

        // Only safe to demand once every existing row has been given a code.
        Schema::table('users', function (Blueprint $table) {
            $table->string('user_code', 20)->nullable(false)->change();
            $table->unique('user_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropIndex('users_role_archived_status_index');
            $table->dropUnique(['user_code']);

            $table->dropColumn([
                'user_code',
                'first_name',
                'middle_name',
                'last_name',
                'contact_number',
                'profile_photo_path',
                'position',
                'company_name',
                'company_address',
                'status',
                'is_archived',
                'archived_at',
                'must_change_password',
                'last_login_at',
                'created_by',
            ]);
        });
    }

    /**
     * Give every account that predates this module a code and name parts, so
     * the module manages the existing users rather than only new ones.
     */
    private function backfillExistingAccounts(): void
    {
        $sequences = [];

        foreach (DB::table('users')->orderBy('id')->get() as $user) {
            $prefix = $user->role === 'client' ? 'CLI' : 'EMP';
            $sequences[$prefix] = ($sequences[$prefix] ?? 0) + 1;

            [$first, $middle, $last] = $this->splitName((string) $user->name);

            DB::table('users')->where('id', $user->id)->update([
                'user_code' => $prefix.'-'.str_pad((string) $sequences[$prefix], 4, '0', STR_PAD_LEFT),
                'first_name' => $first,
                'middle_name' => $middle,
                'last_name' => $last,
            ]);
        }
    }

    /**
     * Best-effort split of a single display name into parts: the last word is
     * the surname, the one before it a middle name, the rest the first name.
     * That reads "Michael A. Capanayan" and "Ana Mendoza" both correctly.
     *
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return match (true) {
            count($parts) === 0 => ['Unnamed', null, null],
            count($parts) === 1 => [$parts[0], null, null],
            count($parts) === 2 => [$parts[0], null, $parts[1]],
            default => [
                implode(' ', array_slice($parts, 0, -2)),
                $parts[count($parts) - 2],
                $parts[count($parts) - 1],
            ],
        };
    }
};
