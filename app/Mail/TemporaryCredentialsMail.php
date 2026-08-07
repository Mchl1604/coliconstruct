<?php

namespace App\Mail;

use App\Models\User;

/**
 * The sign-in details handed to a newly created account, and the same message
 * again after an administrator resets a password.
 *
 * The plain password is passed in rather than read from the model - the stored
 * one is hashed and cannot be read back, which is the point.
 */
class TemporaryCredentialsMail extends SystemMail
{
    public function __construct(
        public readonly User $account,
        public readonly string $temporaryPassword,
        public readonly bool $isReset = false,
    ) {}

    protected function subjectLine(): string
    {
        return $this->isReset
            ? 'Your '.config('company.name').' password has been reset'
            : 'Welcome to '.config('company.name').' - your account is ready';
    }

    protected function template(): string
    {
        return 'emails.temporary-credentials';
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'account' => $this->account,
            'temporaryPassword' => $this->temporaryPassword,
            'isReset' => $this->isReset,
            'loginUrl' => route('auth.login'),
        ];
    }
}
