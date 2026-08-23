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
     * Queue one message, after the surrounding write has committed.
     *
     * For mail that accompanies an action rather than being it: a status
     * change, a notification, a welcome. The action is what the person asked
     * for, so a mail server having a bad afternoon must not undo it.
     *
     * The return value is honest about how little it can know. Queueing a
     * message is not delivering one, and when this is called inside a
     * transaction the queueing has not even been attempted yet - so `true`
     * here means "accepted for delivery", never "delivered". Anything that
     * needs to tell somebody a message arrived wants sendNow() instead.
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

        // DB::afterCommit runs the callback immediately when no transaction is
        // open and defers it when one is. In the first case $accepted carries
        // the real answer by the time this returns; in the second there is
        // nothing yet to report and it stays optimistic.
        $accepted = true;

        try {
            DB::afterCommit(function () use ($recipients, $mail, &$accepted): void {
                $accepted = $this->dispatch($recipients, $mail);
            });
        } catch (Throwable $exception) {
            $this->report($recipients, $mail, $exception);

            return false;
        }

        return $accepted;
    }

    /**
     * Send one message now, and say whether it actually left.
     *
     * For the messages that ARE the action - a verification code, a reset
     * code - where the whole point of the request is that something arrives.
     * Queueing one of those means the interface announcing a code while the
     * rejection is still an hour away in a worker's log, which is how a
     * provider refusing every recipient but one went unnoticed.
     *
     * Still swallows the exception rather than throwing: what a failure means
     * is the caller's decision, and for none of them is it a 500. The reason
     * goes to the log either way.
     *
     * @param  string|array<int, string>  $to
     * @return bool whether the mailer accepted the message
     */
    public function sendNow(string|array $to, SystemMail $mail): bool
    {
        $recipients = $this->validRecipients($to);

        if ($recipients === []) {
            return false;
        }

        try {
            // sendNow rather than send: every system mailable is ShouldQueue,
            // and send() would hand it to a worker - whose success or failure
            // is not something this method could then report.
            Mail::to($recipients)->sendNow($mail);

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
     * @return bool whether the message reached the queue
     */
    private function dispatch(array $recipients, SystemMail $mail): bool
    {
        try {
            Mail::to($recipients)->queue($mail);

            return true;
        } catch (Throwable $exception) {
            $this->report($recipients, $mail, $exception);

            return false;
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
