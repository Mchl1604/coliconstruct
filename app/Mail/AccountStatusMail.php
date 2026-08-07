<?php

namespace App\Mail;

use App\Models\User;

/**
 * Tells an account holder that their access has changed.
 *
 * One mailable for three closely related messages - an address just verified,
 * an account switched back on, an account switched off - because they differ
 * only in wording and in whether there is anywhere to send the reader next.
 */
class AccountStatusMail extends SystemMail
{
    /** A newly registered address has been confirmed. */
    public const VERIFIED = 'verified';

    /** A deactivated account has been switched back on. */
    public const ACTIVATED = 'activated';

    /** An account has been temporarily switched off. */
    public const DEACTIVATED = 'deactivated';

    public function __construct(
        public readonly User $account,
        public readonly string $change,
        public readonly ?string $reason = null,
    ) {}

    protected function subjectLine(): string
    {
        $company = config('company.name');

        return match ($this->change) {
            self::VERIFIED => 'Your '.$company.' account is ready',
            self::DEACTIVATED => 'Your '.$company.' account has been deactivated',
            default => 'Your '.$company.' account has been reactivated',
        };
    }

    protected function template(): string
    {
        return 'emails.account-status';
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'account' => $this->account,
            'change' => $this->change,
            'reason' => $this->reason,
            'isDeactivation' => $this->change === self::DEACTIVATED,
            'loginUrl' => route('auth.login'),
            'heading' => match ($this->change) {
                self::VERIFIED => 'Your account is ready',
                self::DEACTIVATED => 'Your account has been deactivated',
                default => 'Your account is active again',
            },
        ];
    }
}
