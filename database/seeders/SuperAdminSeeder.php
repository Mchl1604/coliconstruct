<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\UserAccountService;
use App\Support\PasswordPolicy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The system's own super administrator.
 *
 * Idempotent: matched on the email address, so re-running it updates the
 * existing account instead of failing on the unique index. The password is
 * only ever set when the account is first created, so a later change made
 * from inside the app is not undone by a re-seed.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = app(UserAccountService::class);

        $existing = User::where('email', 'michaelcapanayan@gmail.com')->first();

        $attributes = [
            'name' => 'Michael A. Capanayan',
            'first_name' => 'Michael',
            'middle_name' => 'A.',
            'last_name' => 'Capanayan',
            'position' => 'System Owner',
            'role' => 'super_admin',
            'status' => User::STATUS_ACTIVE,
            'is_archived' => false,
        ];

        if ($existing) {
            $existing->fill($attributes)->save();

            $this->command?->info("Super admin already present: {$existing->user_code}.");

            return;
        }

        $password = (string) env('SUPER_ADMIN_PASSWORD');

        // The most powerful account in the system is held to the same
        // password policy as every other. Refused outright rather than seeded
        // weak: nothing would ever ask the owner to replace it.
        $problem = PasswordPolicy::failureMessage($password);

        if ($problem !== null) {
            throw new RuntimeException(
                'SUPER_ADMIN_PASSWORD does not meet the password policy. '.$problem
            );
        }

        $user = User::create($attributes + [
            'user_code' => $accounts->nextUserCode('EMP'),
            'email' => 'michaelcapanayan@gmail.com',
            'password' => Hash::make($password),
            // The owner chose this password deliberately, so it is not
            // treated as a temporary one that has to be replaced.
            'must_change_password' => false,
        ]);

        $this->command?->info("Super admin created: {$user->user_code}.");
    }
}
