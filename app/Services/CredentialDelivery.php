<?php

namespace App\Services;

use App\Mail\TemporaryCredentialsMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails an account its temporary sign-in details.
 *
 * Delivery is best-effort on purpose. The mailer is on the `log` driver in
 * this environment, and a real SMTP host can be down; neither is a reason to
 * fail an account that has already been created. The caller shows the password
 * on screen regardless, so the administrator can always hand it over directly.
 */
class CredentialDelivery
{
    /**
     * @return bool whether the message was handed to the mailer
     */
    public function send(User $account, string $temporaryPassword, bool $isReset = false): bool
    {
        try {
            Mail::to($account->email)->send(
                new TemporaryCredentialsMail($account, $temporaryPassword, $isReset)
            );

            return true;
        } catch (Throwable $exception) {
            Log::warning('Could not email temporary credentials.', [
                'user_id' => $account->id,
                'user_code' => $account->user_code,
                'reason' => $exception->getMessage(),
            ]);

            return false;
        }
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
        return ! in_array(config('mail.default'), ['log', 'array', null], true);
    }
}
