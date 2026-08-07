<?php

namespace App\Mail;

use App\Models\User;

/**
 * Tells the old address that the account has moved to a new one.
 *
 * Sent to the address being left behind rather than the one being adopted:
 * the new address has already proved itself with a code, and the person who
 * needs warning is whoever still reads the old mailbox.
 */
class EmailChangedMail extends SystemMail
{
    public function __construct(
        public readonly User $account,
        public readonly string $previousEmail,
        public readonly string $newEmail,
    ) {}

    protected function subjectLine(): string
    {
        return 'The email address on your '.config('company.name').' account has changed';
    }

    protected function template(): string
    {
        return 'emails.email-changed';
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'account' => $this->account,
            'previousEmail' => $this->previousEmail,
            'newEmail' => $this->newEmail,
            'changedAt' => now()->format('M j, Y g:i A'),
        ];
    }
}
