<?php

namespace App\Services;

use App\Mail\TemporaryCredentialsMail;
use App\Models\User;

/**
 * Emails an account its temporary sign-in details.
 *
 * A thin, named wrapper over EmailService rather than a second way to send
 * mail: the queueing, the address validation and the "never fail the request"
 * guarantee all live there. What this adds is the module's own vocabulary, and
 * the fact that delivery is best-effort on purpose - the caller shows the
 * password on screen regardless, so an administrator can always hand it over
 * directly when a mail server is down.
 */
class CredentialDelivery
{
    public function __construct(private readonly EmailService $email) {}

    /**
     * @return bool whether the message was handed to the mailer
     */
    public function send(User $account, string $temporaryPassword, bool $isReset = false): bool
    {
        return $this->email->sendTo(
            $account,
            new TemporaryCredentialsMail($account, $temporaryPassword, $isReset)
        );
    }

    /**
     * Whether mail actually reaches a person from here.
     *
     * The `log` and `array` drivers write the message somewhere rather than
     * delivering it, so the interface should not promise the account has been
     * emailed when one of those is active.
     */
    public function isDeliverable(): bool
    {
        return $this->email->isDeliverable();
    }
}
