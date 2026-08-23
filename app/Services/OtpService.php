<?php

namespace App\Services;

use App\Mail\OtpCodeMail;
use App\Models\ActivityLog;
use App\Models\OtpVerification;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * One-time codes, for every workflow that has to prove somebody owns an
 * address.
 *
 * Registration, a forgotten password, a change of email and an
 * administrator-issued reset all use this and nothing else, so the rules -
 * how long a code lives, how many guesses it takes, how soon another may be
 * asked for - are stated once and cannot drift apart between flows.
 *
 * Three properties hold whatever the caller does:
 *
 *   - The digits are never stored. `otp_code` holds a hash, so a database
 *     dump hands nobody a working code.
 *   - Issuing a code invalidates every earlier one for that address and
 *     purpose. There is never more than one live code.
 *   - A code is spent the moment it verifies. Presenting it twice fails.
 */
class OtpService
{
    /** Digits in a code. Six is short enough to read off a phone screen. */
    public const CODE_LENGTH = 6;

    /** How long a code lives. */
    public const VALID_MINUTES = 10;

    /** Guesses allowed before the code is burnt. */
    public const MAX_ATTEMPTS = 5;

    /** How long before a replacement may be asked for. */
    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Codes one address may be issued for one purpose within the window below.
     *
     * The 60-second cooldown already stops a person hammering the button; this
     * is the outer fence, so an address cannot be mailbombed by a script that
     * waits politely between requests.
     */
    private const MAX_REQUESTS_PER_WINDOW = 8;

    /** The window that cap is measured over. */
    private const REQUEST_WINDOW_SECONDS = 3600;

    /**
     * How long an expired row is kept before it is swept.
     *
     * Not deleted the moment it lapses: somebody who types a code a minute too
     * late should be told it expired, which needs the row to still be there.
     */
    private const SWEEP_AFTER_HOURS = 24;

    public function __construct(private readonly ActivityLogger $activityLogger) {}

    // ------------------------------------------------------------------
    // Issuing
    // ------------------------------------------------------------------

    /**
     * Generate a code, store its hash, and email the digits.
     *
     * The plain code is deliberately not returned: the mailable is the only
     * thing that ever holds it, so no caller can log it or put it in a
     * response by accident.
     *
     * @param  User|null  $user  The account, when the address has one.
     * @param  string|null  $recipientName  Overrides the account's name, for an
     *                                      address that has no account yet.
     *
     * @throws RuntimeException when a code was issued too recently, or too
     *                          many have been issued.
     */
    public function issue(
        string $email,
        string $purpose,
        ?User $user = null,
        ?string $recipientName = null
    ): OtpVerification {
        $email = $this->normalise($email);

        $this->guardResendCooldown($email, $purpose);
        $this->guardRequestRate($email, $purpose);

        $code = $this->generateCode();

        // Everything issued before this moment stops working, so there is only
        // ever one live code for an address and a purpose.
        $this->invalidate($email, $purpose);

        $record = OtpVerification::create([
            'user_id' => $user?->id,
            'email' => $email,
            'otp_code' => Hash::make($code),
            'purpose' => $purpose,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::VALID_MINUTES),
        ]);

        RateLimiter::hit($this->requestKey($email, $purpose), self::REQUEST_WINDOW_SECONDS);

        // Sent rather than queued, and the answer is acted on. A code is the
        // one kind of message where "we sent it" is the entire content of what
        // the interface then tells somebody: announcing one that a worker will
        // fail to deliver an hour later leaves them waiting on an email that
        // is never coming, with nothing on screen to suggest otherwise.
        $delivered = app(EmailService::class)->sendNow($email, new OtpCodeMail(
            $code,
            $purpose,
            self::VALID_MINUTES,
            $recipientName ?? $user?->fullName()
        ));

        if (! $delivered) {
            // A code nobody received is worse than no code: it would sit here
            // as the one live code for this address and purpose, running down
            // the resend cooldown and showing a countdown for a message that
            // does not exist. The rate-limiter hit above deliberately stands -
            // a failing mail provider is not a reason to lift the ceiling on
            // how often an address may be tried.
            $record->delete();

            throw new RuntimeException(
                'We could not send a code to that address. Check it is correct and try again.'
            );
        }

        $this->record(
            $purpose === OtpVerification::PURPOSE_REGISTRATION
                ? ActivityLog::REGISTRATION_OTP_SENT
                : ActivityLog::OTP_SENT,
            $email,
            $user,
            sprintf('A verification code was sent to %s to %s.', $email, $record->purposeLabel())
        );

        // Housekeeping rides along with a write that already happens, rather
        // than needing a scheduler entry of its own.
        $this->purgeExpired();

        return $record;
    }

    // ------------------------------------------------------------------
    // Verifying
    // ------------------------------------------------------------------

    /**
     * Check a code and spend it.
     *
     * @throws RuntimeException with the message the person should be shown.
     */
    public function verify(string $email, string $purpose, string $code): OtpVerification
    {
        $email = $this->normalise($email);

        $record = $this->latest($email, $purpose);

        if (! $record) {
            throw new RuntimeException('No verification code is waiting for that address. Ask for a new one.');
        }

        if ($record->isExpired()) {
            $this->record(
                ActivityLog::OTP_EXPIRED,
                $email,
                $record->user,
                sprintf('An expired verification code was submitted for %s.', $email)
            );

            throw new RuntimeException('That code has expired. Ask for a new one.');
        }

        if ($record->attempts >= self::MAX_ATTEMPTS) {
            throw new RuntimeException('Too many incorrect attempts. Ask for a new code.');
        }

        // Counted before the comparison, so a request that dies mid-flight
        // still costs an attempt and cannot be used to guess for free.
        $record->increment('attempts');
        $record->refresh();

        if (! Hash::check($this->digitsOnly($code), $record->otp_code)) {
            $this->record(
                ActivityLog::OTP_FAILED,
                $email,
                $record->user,
                sprintf(
                    'An incorrect verification code was submitted for %s (attempt %d of %d).',
                    $email,
                    $record->attempts,
                    self::MAX_ATTEMPTS
                )
            );

            $remaining = max(0, self::MAX_ATTEMPTS - $record->attempts);

            throw new RuntimeException($remaining === 0
                ? 'That code is incorrect, and no attempts remain. Ask for a new code.'
                : sprintf('That code is incorrect. %d attempt%s remaining.', $remaining, $remaining === 1 ? '' : 's'));
        }

        // Spent. Presenting the same digits again finds a verified row and
        // fails, which is the whole of "one-time use".
        $record->forceFill(['verified_at' => now()])->save();

        RateLimiter::clear($this->requestKey($email, $purpose));

        $this->record(
            ActivityLog::OTP_VERIFIED,
            $email,
            $record->user,
            sprintf('A verification code was confirmed for %s.', $email)
        );

        return $record;
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * The live code for an address and purpose, spent ones excluded.
     */
    public function latest(string $email, string $purpose): ?OtpVerification
    {
        return OtpVerification::query()
            ->forPurpose($this->normalise($email), $purpose)
            ->unspent()
            ->latest('otp_id')
            ->first();
    }

    /**
     * Whether a code for this address and purpose has already been confirmed.
     *
     * This is what stands between "I proved I own the address" and the action
     * it unlocks - the reset form checks it rather than trusting a session
     * flag alone.
     */
    public function hasVerified(string $email, string $purpose, ?int $withinMinutes = null): bool
    {
        return OtpVerification::query()
            ->forPurpose($this->normalise($email), $purpose)
            ->whereNotNull('verified_at')
            ->when(
                $withinMinutes !== null,
                fn ($query) => $query->where('verified_at', '>=', now()->subMinutes($withinMinutes))
            )
            ->exists();
    }

    /**
     * Seconds before another code may be asked for. Zero means now.
     */
    public function secondsUntilResend(string $email, string $purpose): int
    {
        return $this->latest($email, $purpose)
            ?->secondsUntilResend(self::RESEND_COOLDOWN_SECONDS) ?? 0;
    }

    // ------------------------------------------------------------------
    // Housekeeping
    // ------------------------------------------------------------------

    /**
     * Stop every outstanding code for an address and purpose working.
     *
     * Deleted rather than flagged: an unspent code that has been superseded is
     * of no interest to anybody, and the audit trail already records that one
     * was sent.
     */
    public function invalidate(string $email, string $purpose): void
    {
        OtpVerification::query()
            ->forPurpose($this->normalise($email), $purpose)
            ->unspent()
            ->delete();
    }

    /**
     * Remove every trace of a workflow, confirmed codes included.
     *
     * Called once the action a code unlocked has actually happened, so a
     * replayed session cannot find its own confirmation still standing and use
     * it a second time. invalidate() deliberately does less - it supersedes
     * outstanding codes without touching one that has already been spent.
     */
    public function clear(string $email, string $purpose): void
    {
        OtpVerification::query()
            ->forPurpose($this->normalise($email), $purpose)
            ->delete();
    }

    /**
     * Drop rows that lapsed long enough ago to be of no use to anyone.
     *
     * @return int rows removed
     */
    public function purgeExpired(): int
    {
        return OtpVerification::query()
            ->where('expires_at', '<', now()->subHours(self::SWEEP_AFTER_HOURS))
            ->delete();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Six digits from a cryptographically secure source, leading zeros kept -
     * "007431" is as valid a code as any other, and dropping the padding would
     * quietly shrink the keyspace.
     */
    private function generateCode(): string
    {
        return str_pad(
            (string) random_int(0, (10 ** self::CODE_LENGTH) - 1),
            self::CODE_LENGTH,
            '0',
            STR_PAD_LEFT
        );
    }

    /**
     * @throws RuntimeException
     */
    private function guardResendCooldown(string $email, string $purpose): void
    {
        $seconds = $this->secondsUntilResend($email, $purpose);

        if ($seconds > 0) {
            throw new RuntimeException(
                sprintf('Wait %d second%s before requesting another code.', $seconds, $seconds === 1 ? '' : 's')
            );
        }
    }

    /**
     * @throws RuntimeException
     */
    private function guardRequestRate(string $email, string $purpose): void
    {
        if (! RateLimiter::tooManyAttempts($this->requestKey($email, $purpose), self::MAX_REQUESTS_PER_WINDOW)) {
            return;
        }

        $minutes = (int) ceil(RateLimiter::availableIn($this->requestKey($email, $purpose)) / 60);

        throw new RuntimeException(
            sprintf('Too many codes requested. Try again in %d minute%s.', max(1, $minutes), $minutes === 1 ? '' : 's')
        );
    }

    private function requestKey(string $email, string $purpose): string
    {
        return 'otp|'.$purpose.'|'.$email;
    }

    private function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * People paste codes with spaces in them. The comparison should not care.
     */
    private function digitsOnly(string $code): string
    {
        return preg_replace('/\D/', '', $code) ?? '';
    }

    /**
     * Every verification event reaches the audit trail the same way.
     *
     * recordAnonymous() rather than record(), because most of these happen
     * while nobody is signed in - which is exactly when an audit trail has to
     * name the address rather than an actor.
     */
    private function record(string $action, string $email, ?User $user, string $description): void
    {
        $this->activityLogger->recordAnonymous(
            $action,
            $user?->fullName() ?? $email,
            $user,
            $description
        );
    }
}
