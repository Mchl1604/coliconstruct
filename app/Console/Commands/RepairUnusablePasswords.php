<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Console\Command;

/**
 * Find accounts whose stored password is not a hash, and issue a real one.
 *
 * Such a row can never sign in: Hash::check has nothing valid to compare
 * against, so every attempt reports "those credentials do not match" however
 * correct the password is. They come from seed files and database imports that
 * wrote a placeholder into the column - an application write always hashes,
 * because the User model casts the attribute.
 */
class RepairUnusablePasswords extends Command
{
    protected $signature = 'users:repair-passwords
                            {--password= : Set this password on every affected account instead of generating one each}
                            {--keep-password : Do not force a password change at next sign-in}
                            {--dry-run : List the affected accounts and change nothing}';

    protected $description = 'Reissue passwords for accounts whose stored hash is unusable';

    public function handle(UserAccountService $accounts): int
    {
        $affected = User::query()->withUnusablePassword()->orderBy('id')->get();

        if ($affected->isEmpty()) {
            $this->info('Every account has a usable password hash. Nothing to do.');

            return self::SUCCESS;
        }

        $this->warn($affected->count().' account(s) cannot sign in - their stored password is not a hash:');
        $this->table(
            ['ID', 'Email', 'Role'],
            $affected->map(fn (User $user): array => [$user->id, $user->email, $user->role])->all()
        );

        if ($this->option('dry-run')) {
            $this->line('Dry run - nothing was changed.');

            return self::SUCCESS;
        }

        $shared = $this->option('password');
        $forceChange = ! $this->option('keep-password');
        $issued = [];

        foreach ($affected as $user) {
            $password = $shared ?: $accounts->generateTemporaryPassword();

            $user->forceFill([
                'password' => $password,
                'must_change_password' => $forceChange,
            ])->save();

            $issued[] = [$user->email, $password];
        }

        $this->newLine();
        $this->info('Reissued. These values are shown once:');
        $this->table(['Email', 'Password'], $issued);

        if ($forceChange) {
            $this->line('Each account must choose a new password at next sign-in.');
        }

        return self::SUCCESS;
    }
}
