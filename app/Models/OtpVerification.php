<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One issued verification code.
 *
 * The row is the whole of the code's state: how long it has left, how many
 * guesses it has taken, and whether it has already been spent. Nothing about a
 * code lives in the session, so a second browser tab cannot be used to get a
 * fresh set of attempts.
 *
 * `otp_code` holds a hash. Nothing in this class can tell you the digits.
 */
class OtpVerification extends Model
{
    protected $table = 'tbl_otp_verifications';

    protected $primaryKey = 'otp_id';

    // ------------------------------------------------------------------
    // Purposes
    // ------------------------------------------------------------------

    public const PURPOSE_REGISTRATION = 'registration';

    public const PURPOSE_FORGOT_PASSWORD = 'forgot_password';

    public const PURPOSE_EMAIL_CHANGE = 'email_change';

    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    /**
     * Every purpose a code may be issued for, with the wording the email uses.
     *
     * @var array<string, string>
     */
    public const PURPOSE_LABELS = [
        self::PURPOSE_REGISTRATION => 'verify your email address',
        self::PURPOSE_FORGOT_PASSWORD => 'reset your password',
        self::PURPOSE_EMAIL_CHANGE => 'confirm your new email address',
        self::PURPOSE_PASSWORD_RESET => 'confirm your password reset',
    ];

    protected $fillable = [
        'user_id',
        'email',
        'otp_code',
        'purpose',
        'attempts',
        'expires_at',
        'verified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    // ------------------------------------------------------------------
    // State
    // ------------------------------------------------------------------

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Whether this code can still be presented at all: unspent, unexpired, and
     * with guesses left.
     */
    public function isUsable(int $maxAttempts): bool
    {
        return ! $this->isVerified()
            && ! $this->isExpired()
            && $this->attempts < $maxAttempts;
    }

    /**
     * Seconds until this code stops working, floored at zero.
     */
    public function secondsRemaining(): int
    {
        if ($this->expires_at === null) {
            return 0;
        }

        return (int) max(0, now()->diffInSeconds($this->expires_at, false));
    }

    /**
     * Seconds before a replacement may be asked for.
     */
    public function secondsUntilResend(int $cooldownSeconds): int
    {
        if ($this->created_at === null) {
            return 0;
        }

        return max(0, $cooldownSeconds - (int) $this->created_at->diffInSeconds(now()));
    }

    public function purposeLabel(): string
    {
        return self::PURPOSE_LABELS[$this->purpose] ?? 'verify your request';
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    /**
     * Codes that have not been spent yet. Says nothing about expiry - an
     * expired code still has to be found so the person can be told it lapsed
     * rather than that it was wrong.
     */
    public function scopeUnspent(Builder $query): Builder
    {
        return $query->whereNull('verified_at');
    }

    public function scopeForPurpose(Builder $query, string $email, string $purpose): Builder
    {
        return $query->where('email', $email)->where('purpose', $purpose);
    }
}
