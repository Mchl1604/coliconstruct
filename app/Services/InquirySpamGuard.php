<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * What keeps the public Contact form from becoming a spam funnel.
 *
 * Three fences, and they are deliberately not the same fence stated three
 * times:
 *
 *   - The honeypot catches a script that fills every input it finds. Cheap,
 *     and it catches the overwhelming majority of them.
 *   - The per-IP window catches somebody leaning on the submit button.
 *   - The per-email windows catch what the IP window cannot, because an
 *     address is the only thing here that identifies a sender: a botnet has
 *     as many IPs as it likes, and a school has one for everybody in it.
 *
 * The last point is the reason both exist. An IP is a poor identity - shared
 * by a whole office, and changed at will by anyone who cares to - so it is
 * held to a short window and the real accounting is done per address. Nobody
 * is ever blocked for longer than a window, and one person on a shared
 * connection delays the next rather than shutting them out.
 *
 * Modelled on OtpService, which guards addresses the same way: the rules live
 * in a service and are raised as a RuntimeException carrying a message
 * written for somebody to read, and the controller decides how to show it.
 * Counting is Laravel's own RateLimiter rather than a table of our own - the
 * framework already has this, and an enquiry refused is not a record worth
 * keeping.
 *
 * Every limit here is server side. The form carries no timer, no counter and
 * no cooldown, so there is nothing in the browser to edit around: a request
 * forged by hand meets exactly these checks and no others.
 */
class InquirySpamGuard
{
    /**
     * What somebody held back by the per-IP window is told.
     *
     * Neither message names a limit, a count or a number of minutes. Being
     * vague is the point: it is all a person needs to know to try again later,
     * and it hands a script nothing to calibrate against.
     */
    public const IP_MESSAGE = 'Please wait before submitting another inquiry.';

    /** What somebody held back by either per-address window is told. */
    public const EMAIL_MESSAGE = 'You have submitted too many inquiries. Please try again later.';

    /**
     * How long one sender waits between enquiries, when nobody has configured
     * anything else. Ten minutes, which is what the shipped config file has
     * always said in seconds.
     */
    public const DEFAULT_SUBMISSION_LIMIT_MINUTES = 10;

    /**
     * The setting the wait is stored under.
     */
    public const SETTING_LIMIT_MINUTES = 'inquiry_settings.submission_limit_minutes';

    public function __construct(private readonly SystemContentService $settings) {}

    /**
     * How long a sender must wait, in minutes.
     *
     * A setting rather than a constant because it is the one limit here an
     * administrator has a reason to change: a company running a campaign wants
     * it short, one being pestered wants it long, and neither should need a
     * deployment. The per-address caps below stay in config - those are a
     * property of the deployment, not a decision about the business.
     *
     * Asked of the settings store on every submission, which costs nothing:
     * SystemContentService caches the whole table as one map.
     */
    public function submissionLimitMinutes(): int
    {
        return $this->settings->number(
            self::SETTING_LIMIT_MINUTES,
            $this->configuredLimitMinutes()
        );
    }

    // ------------------------------------------------------------------
    // The honeypot
    // ------------------------------------------------------------------

    /**
     * Whether the request filled in the field no person can see.
     *
     * Read straight off the request rather than validated. A validation rule
     * would answer with an error naming the field, which tells a bot exactly
     * what it was caught by and exactly what to leave blank next time.
     */
    public function tripsHoneypot(Request $request): bool
    {
        return filled($request->input($this->honeypotField()));
    }

    public function honeypotField(): string
    {
        return (string) config('inquiries.honeypot_field', 'company_website');
    }

    // ------------------------------------------------------------------
    // The windows
    // ------------------------------------------------------------------

    /**
     * Refuse the submission if any window is already full.
     *
     * Checked in the order the fences were described: the address the request
     * came from, then the address it claims to be from. A caller that is past
     * both may proceed, and only then does anything get counted - see
     * recordSubmission().
     *
     * @throws RuntimeException when a window is full, carrying the message the
     *                          visitor is shown.
     */
    public function guard(Request $request, string $email): void
    {
        if (RateLimiter::tooManyAttempts($this->ipKey($request), $this->limit('per_ip.max'))) {
            throw new RuntimeException(self::IP_MESSAGE);
        }

        if (RateLimiter::tooManyAttempts($this->hourlyEmailKey($email), $this->limit('per_email.hourly.max'))) {
            throw new RuntimeException(self::EMAIL_MESSAGE);
        }

        if (RateLimiter::tooManyAttempts($this->dailyEmailKey($email), $this->limit('per_email.daily.max'))) {
            throw new RuntimeException(self::EMAIL_MESSAGE);
        }
    }

    /**
     * Count an enquiry that was actually stored.
     *
     * Called after the write, never before it. A refused submission and a
     * submission this application failed to store both cost the sender
     * nothing, so a database that was briefly down does not go on to lock
     * somebody out of the form for ten minutes.
     *
     * Each window is counted separately, and RateLimiter fixes a window's
     * expiry on its first hit rather than extending it on later ones - so ten
     * minutes after the enquiry that filled it, the IP window is open again.
     */
    public function recordSubmission(Request $request, string $email): void
    {
        // The one window an administrator sets, so it is read from the setting
        // rather than from config. The two below are deployment properties and
        // are not on any screen.
        RateLimiter::hit($this->ipKey($request), $this->submissionLimitMinutes() * 60);
        RateLimiter::hit($this->hourlyEmailKey($email), $this->limit('per_email.hourly.decay_seconds'));
        RateLimiter::hit($this->dailyEmailKey($email), $this->limit('per_email.daily.decay_seconds'));
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The form of an address the limits are counted against.
     *
     * Trimmed and lower-cased, so "Rosa@Example.test" and " rosa@example.test "
     * are one sender rather than three. The same normalisation OtpService
     * applies, and for the same reason: an address that differs only in case
     * or padding is the same mailbox, and letting it through would make the
     * cap trivial to walk around.
     */
    private function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function ipKey(Request $request): string
    {
        return 'inquiry|ip|'.$request->ip();
    }

    private function hourlyEmailKey(string $email): string
    {
        return 'inquiry|email-hour|'.$this->normalise($email);
    }

    private function dailyEmailKey(string $email): string
    {
        return 'inquiry|email-day|'.$this->normalise($email);
    }

    /**
     * One limit from config, as a positive integer.
     *
     * A missing or nonsensical value falls back to the shipped default rather
     * than to zero - a zero maximum would refuse every enquiry, which is a
     * far worse failure than a limit that is briefly the wrong size.
     */
    private function limit(string $key): int
    {
        $configured = (int) config('inquiries.'.$key);

        return $configured > 0 ? $configured : $this->defaults()[$key];
    }

    /**
     * The deployment's own idea of the wait, in whole minutes.
     *
     * What the setting falls back to, so a site that configured
     * INQUIRY_IP_WINDOW_SECONDS before the setting existed keeps the window it
     * chose rather than being silently reset to ten minutes. Rounded up, so a
     * configured window is never shortened by the conversion.
     */
    private function configuredLimitMinutes(): int
    {
        $seconds = $this->limit('per_ip.decay_seconds');

        return max(1, (int) ceil($seconds / 60));
    }

    /**
     * @return array<string, int>
     */
    private function defaults(): array
    {
        return [
            'per_ip.max' => 1,
            'per_ip.decay_seconds' => self::DEFAULT_SUBMISSION_LIMIT_MINUTES * 60,
            'per_email.hourly.max' => 3,
            'per_email.hourly.decay_seconds' => 3600,
            'per_email.daily.max' => 10,
            'per_email.daily.decay_seconds' => 86400,
        ];
    }
}
