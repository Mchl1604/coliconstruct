<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A registration waiting on its code.
 *
 * Everything the form collected, held outside `users` until the address has
 * been proved. No account, no user_code and no activity log entry exists for
 * one of these - see UserAccountService::completeRegistration(), which is the
 * only thing that turns a row here into a real account.
 *
 * `password` is already hashed. It is handed to User::create() untouched: the
 * `hashed` cast on the account leaves a value that is already a hash alone.
 */
class PendingRegistration extends Model
{
    protected $table = 'tbl_pending_registrations';

    protected $primaryKey = 'pending_id';

    /**
     * How long an unfinished registration is kept.
     *
     * Generous next to the code's ten minutes on purpose. The code lapsing is
     * ordinary - somebody reads their email an hour later and asks for
     * another - and throwing the form away with it would make them type it all
     * again. A day is long enough for that and short enough that an address
     * typed by mistake is not held for a week.
     */
    public const VALID_HOURS = 24;

    protected $fillable = [
        'email',
        'full_name',
        'contact_number',
        'birthdate',
        'password',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'expires_at' => 'datetime',
            // Deliberately NOT 'hashed'. The value written here is already a
            // hash; the cast is for turning a password into one, and this
            // model never sees a password.
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * The registrations a sweep may remove.
     */
    public function scopeLapsed(Builder $query): Builder
    {
        return $query->where('expires_at', '<', CarbonImmutable::now());
    }

    /**
     * The live registration for an address, if there is one.
     *
     * An expired row is not one: it is left for the sweep rather than deleted
     * on a read, but it must not be treated as a registration in progress.
     */
    public static function liveFor(string $email): ?self
    {
        $registration = self::where('email', mb_strtolower(trim($email)))->first();

        return $registration && ! $registration->isExpired() ? $registration : null;
    }

    /**
     * The name the verification email greets, matching what a User would give.
     */
    public function fullName(): string
    {
        return trim((string) $this->full_name);
    }
}
