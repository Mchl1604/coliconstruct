<?php

namespace App\Services;

use App\Mail\SystemMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The single way a system email leaves this application.
 *
 * Modelled on ActivityLogger and NotificationService, and holding the same
 * two guarantees:
 *
 *   - Nothing thrown here escapes. A mail server being unreachable must never
 *     be the reason an account, a project or a password change fails.
 *   - Inside a transaction the message is deferred until after the commit, so
 *     a rolled-back action never emails somebody about work that did not
 *     happen.
 *
 * Delivery itself is queued by every SystemMail, so the request returns
 * without waiting on SMTP. On the `sync` queue driver that degrades to an
 * immediate send rather than to nothing.
 */
class EmailService
{
    /**
     * Mailers that write a message somewhere instead of delivering it.
     *
     * @var array<int, string|null>
     */
    private const NON_DELIVERING = ['log', 'array', null];

    /**
     * Hand one message to the mailer.
     *
     * @param  string|array<int, string>  $to
     * @return bool whether the message was accepted for delivery
     */
    public function send(string|array $to, SystemMail $mail): bool
    {
        $recipients = $this->validRecipients($to);

        if ($recipients === []) {
            return false;
        }

        try {
            DB::afterCommit(function () use ($recipients, $mail): void {
                $this->dispatch($recipients, $mail);
            });

            return true;
        } catch (Throwable $exception) {
            $this->report($recipients, $mail, $exception);

            return false;
        }
    }

    /**
     * Send to an account, addressing it by name.
     */
    public function sendTo(?User $user, SystemMail $mail): bool
    {
        if (! $user || ! filled($user->email)) {
            return false;
        }

        return $this->send($user->email, $mail);
    }

    /**
     * Whether mail actually reaches a person from here.
     *
     * The `log` and `array` drivers write the message somewhere rather than
     * delivering it, so the interface must not promise somebody has been
     * emailed while one of those is active.
     */
    public function isDeliverable(): bool
    {
        return ! in_array(config('mail.default'), self::NON_DELIVERING, true);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @param  array<int, string>  $recipients
     */
    private function dispatch(array $recipients, SystemMail $mail): void
    {
        try {
            Mail::to($recipients)->queue($mail);
        } catch (Throwable $exception) {
            $this->report($recipients, $mail, $exception);
        }
    }

    /**
     * Drop anything that is not an address a mail server would accept.
     *
     * A malformed address makes the transport throw for the whole message, so
     * one bad entry must not cost the others their delivery.
     *
     * @param  string|array<int, string>  $to
     * @return array<int, string>
     */
    private function validRecipients(string|array $to): array
    {
        return collect(is_array($to) ? $to : [$to])
            ->map(fn ($address): string => trim((string) $address))
            ->filter(fn (string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $recipients
     */
    private function report(array $recipients, SystemMail $mail, Throwable $exception): void
    {
        Log::warning('A system email could not be sent.', [
            'mailable' => $mail::class,
            'recipients' => $recipients,
            'reason' => $exception->getMessage(),
        ]);
    }
}
