<?php

namespace App\Services;

use App\Mail\EmailChangedMail;
use App\Mail\SpecialtyDecisionMail;
use App\Models\ActivityLog;
use App\Models\OtpVerification;
use App\Models\SpecialtyRequest;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Everything an account may change about itself.
 *
 * Deliberately separate from UserAccountService, which is an administrator
 * acting on somebody else: the rules are not the same. Nobody here may change
 * a role, a status or another person's details, and a technician cannot change
 * their own specialties at all - they can only ask.
 *
 * Every method audits what it did, and only what it did: saving a form that
 * changed nothing writes no entry.
 */
class ProfileService
{
    /** Where profile pictures live on the public disk. */
    private const PHOTO_DIRECTORY = 'profile-photos';

    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly NotificationService $notifications,
        private readonly OtpService $otp,
        private readonly EmailService $email
    ) {}

    // ------------------------------------------------------------------
    // Profile picture
    // ------------------------------------------------------------------

    /**
     * Store a new picture, replacing whatever was there.
     *
     * Only one picture is ever active: the previous file is deleted rather
     * than orphaned on disk, so replacing a photo repeatedly costs nothing.
     */
    public function updatePhoto(User $user, UploadedFile $photo): User
    {

        $previous = $user->profile_photo_path;

        $user->profile_photo_path = $photo->store(self::PHOTO_DIRECTORY, 'public');
        $user->save();

        if ($previous && $previous !== $user->profile_photo_path) {
            Storage::disk('public')->delete($previous);
        }

        $this->activityLogger->record(
            $previous ? ActivityLog::PROFILE_PHOTO_CHANGED : ActivityLog::PROFILE_PHOTO_UPLOADED,
            $user,
            sprintf(
                '%s their profile picture.',
                $previous ? 'Changed' : 'Uploaded'
            )
        );

        return $user;
    }

    /**
     * Drop the picture and fall back to the default avatar.
     */
    public function removePhoto(User $user): User
    {

        if (! $user->profile_photo_path) {
            throw new RuntimeException('There is no profile picture to remove.');
        }

        $previous = $user->profile_photo_path;

        $user->profile_photo_path = null;
        $user->save();

        Storage::disk('public')->delete($previous);

        $this->activityLogger->record(
            ActivityLog::PROFILE_PHOTO_REMOVED,
            $user,
            'Removed their profile picture.'
        );

        return $user;
    }

    // ------------------------------------------------------------------
    // Personal information
    // ------------------------------------------------------------------

    /**
     * Name and email. The role, status, user code and contact history are all
     * absent on purpose - none of them is the account's own to change.
     *
     * The name is applied at once. The email is not: a new address is parked
     * on the account and a code is sent to it, and only that code moves the
     * address the person signs in with. A typo therefore costs an unread
     * email rather than an account.
     *
     * @param  array{first_name: string, middle_name?: ?string, last_name: string, email: string}  $data
     * @return array{user: User, email_pending: bool}
     */
    public function updateInformation(User $user, array $data): array
    {
        $nameBefore = $user->fullName();
        $emailBefore = mb_strtolower((string) $user->email);
        $requestedEmail = mb_strtolower(trim($data['email']));

        DB::transaction(function () use ($user, $data): void {
            $user->fill([
                'first_name' => trim($data['first_name']),
                'middle_name' => filled($data['middle_name'] ?? null) ? trim((string) $data['middle_name']) : null,
                'last_name' => trim($data['last_name']),
            ]);

            // `name` is what the topbar, technician listings and report joins
            // all read, so it has to stay in step with the parts.
            $user->name = $this->displayName($user);
            $user->save();
        });

        if ($user->fullName() !== $nameBefore) {
            $this->activityLogger->record(
                ActivityLog::PROFILE_NAME_UPDATED,
                $user,
                sprintf('Changed their name from %s to %s.', $nameBefore, $user->fullName())
            );
        }

        $emailPending = $requestedEmail !== $emailBefore
            && $this->requestEmailChange($user, $requestedEmail);

        return ['user' => $user->refresh(), 'email_pending' => $emailPending];
    }

    // ------------------------------------------------------------------
    // Changing the sign-in address
    // ------------------------------------------------------------------

    /**
     * Park a new address and send a code to it.
     *
     * Nothing about the account changes here beyond the parked value: the
     * person keeps signing in with their old address, and keeps receiving mail
     * there, until the code comes back.
     *
     * @return bool whether a code was actually sent
     */
    public function requestEmailChange(User $user, string $newEmail): bool
    {
        $newEmail = mb_strtolower(trim($newEmail));

        if ($newEmail === mb_strtolower((string) $user->email)) {
            throw new RuntimeException('That is already the address on your account.');
        }

        // Checked again here rather than trusting the form: the request and
        // the confirmation are minutes apart, and somebody else may have taken
        // the address in between.
        $taken = User::query()
            ->where('id', '!=', $user->id)
            ->where(function ($query) use ($newEmail): void {
                $query->whereRaw('LOWER(email) = ?', [$newEmail])
                    ->orWhereRaw('LOWER(pending_email) = ?', [$newEmail]);
            })
            ->exists();

        if ($taken) {
            throw new RuntimeException('Another account already uses that email address.');
        }

        $user->forceFill(['pending_email' => $newEmail])->save();

        $this->otp->issue(
            $newEmail,
            OtpVerification::PURPOSE_EMAIL_CHANGE,
            $user
        );

        $this->activityLogger->record(
            ActivityLog::EMAIL_CHANGE_REQUESTED,
            $user,
            sprintf('Asked to change their email address from %s to %s.', $user->email, $newEmail)
        );

        return true;
    }

    /**
     * Confirm the parked address and make it the account's own.
     *
     * @throws RuntimeException when the code is wrong, expired or spent - the
     *                          old address stays exactly as it was.
     */
    public function confirmEmailChange(User $user, string $code): User
    {
        if (! $user->hasPendingEmailChange()) {
            throw new RuntimeException('There is no email change waiting to be confirmed.');
        }

        $newEmail = (string) $user->pending_email;
        $previousEmail = (string) $user->email;

        // Throws with the message the person should read; nothing below runs
        // and the account is untouched.
        $this->otp->verify($newEmail, OtpVerification::PURPOSE_EMAIL_CHANGE, $code);

        $user->forceFill([
            'email' => $newEmail,
            'pending_email' => null,
            // The address has just been proved, which is exactly what this
            // column records.
            'email_verified_at' => now(),
        ])->save();

        $this->otp->clear($newEmail, OtpVerification::PURPOSE_EMAIL_CHANGE);

        $this->activityLogger->record(
            ActivityLog::EMAIL_CHANGED,
            $user,
            sprintf('Changed their email address from %s to %s.', $previousEmail, $newEmail)
        );

        // The old address is told, so a change nobody at that mailbox asked
        // for does not go unnoticed.
        $this->email->send(
            $previousEmail,
            new EmailChangedMail($user, $previousEmail, $newEmail)
        );

        return $user->refresh();
    }

    /**
     * Send another code to the parked address.
     */
    public function resendEmailChangeCode(User $user): void
    {
        if (! $user->hasPendingEmailChange()) {
            throw new RuntimeException('There is no email change waiting to be confirmed.');
        }

        $this->otp->issue(
            (string) $user->pending_email,
            OtpVerification::PURPOSE_EMAIL_CHANGE,
            $user
        );
    }

    /**
     * Abandon a parked change. The account keeps the address it already had.
     */
    public function cancelEmailChange(User $user): User
    {
        if (! $user->hasPendingEmailChange()) {
            return $user;
        }

        $abandoned = (string) $user->pending_email;

        $user->forceFill(['pending_email' => null])->save();

        $this->otp->clear($abandoned, OtpVerification::PURPOSE_EMAIL_CHANGE);

        return $user->refresh();
    }

    /**
     * Set a new password.
     *
     * The current one is verified by the request's validation rule before this
     * is reached. The value itself never appears in the audit entry.
     */
    public function updatePassword(User $user, string $password): User
    {
        $user->password = $password;
        // Choosing a password of their own satisfies any outstanding demand
        // that they choose one.
        $user->must_change_password = false;
        $user->save();

        $this->activityLogger->record(
            ActivityLog::PASSWORD_CHANGED,
            $user,
            'Changed their own password.'
        );

        return $user;
    }

    // ------------------------------------------------------------------
    // Specialties
    // ------------------------------------------------------------------

    /**
     * Ask for a different set of specialties.
     *
     * Nothing about the technician's approved specialties changes here. The
     * scheduler, the project wizard and every suggestion list keep reading the
     * approved set until an administrator decides.
     *
     * @param  array<int, int>  $skillIds  The whole set being asked for.
     */
    public function requestSpecialties(Technician $technician, array $skillIds): SpecialtyRequest
    {
        $account = $technician->account;

        if (! $account) {
            throw new RuntimeException('That technician record has no account.');
        }

        if ($this->pendingSpecialtyRequest($technician)) {
            throw new RuntimeException('You already have a specialty request awaiting approval.');
        }

        $requested = collect($skillIds)->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $current = $technician->skills->pluck('skill_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values();

        if ($requested->all() === $current->all()) {
            throw new RuntimeException('That is the same set of specialties you already have.');
        }

        $request = SpecialtyRequest::create([
            'technician_id' => $technician->technician_id,
            'status' => SpecialtyRequest::STATUS_PENDING,
            'requested_skill_ids' => $requested->all(),
            'current_skill_ids' => $current->all(),
            'requested_by' => $account->id,
        ]);

        $this->activityLogger->record(
            ActivityLog::SPECIALTY_REQUEST_SUBMITTED,
            $account,
            sprintf(
                'Requested a specialty change: %s.',
                $request->changeSummary()
            ),
            $request
        );

        $this->notifications->specialtyRequestSubmitted($account, $request);

        return $request;
    }

    /**
     * Apply a pending request. This is the only thing in the system that moves
     * a technician's specialties other than an administrator editing them
     * directly on the Technicians page.
     */
    public function approveSpecialtyRequest(SpecialtyRequest $request, User $reviewer): SpecialtyRequest
    {
        $this->guardPending($request);

        $technician = $request->technician;

        if (! $technician) {
            throw new RuntimeException('That request no longer has a technician.');
        }

        DB::transaction(function () use ($request, $reviewer, $technician): void {
            // sync() is what makes a duplicate impossible: the pivot ends up
            // holding exactly these ids, however many times one was sent.
            $technician->skills()->sync($request->requested_skill_ids ?? []);

            $request->update([
                'status' => SpecialtyRequest::STATUS_APPROVED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);
        });

        $account = $technician->account;

        $this->activityLogger->record(
            ActivityLog::SPECIALTY_REQUEST_APPROVED,
            $account,
            sprintf(
                'Approved a specialty change for %s: %s.',
                $account?->fullName() ?? $technician->name,
                $request->changeSummary()
            ),
            $request
        );

        if ($account) {
            $this->notifications->specialtyRequestApproved($account, $request);

            $this->email->sendTo($account, new SpecialtyDecisionMail(
                $account,
                approved: true,
                specialties: $technician->skills()->pluck('skill_name')->all()
            ));
        }

        return $request->refresh();
    }

    /**
     * Turn a request down. The approved specialties are left exactly as they
     * were - a rejection changes nothing except that the technician may ask
     * again.
     */
    public function rejectSpecialtyRequest(SpecialtyRequest $request, User $reviewer): SpecialtyRequest
    {
        $this->guardPending($request);

        $request->update([
            'status' => SpecialtyRequest::STATUS_REJECTED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $account = $request->technician?->account;

        $this->activityLogger->record(
            ActivityLog::SPECIALTY_REQUEST_REJECTED,
            $account,
            sprintf(
                'Rejected a specialty change for %s: %s.',
                $account?->fullName() ?? 'a technician',
                $request->changeSummary()
            ),
            $request
        );

        if ($account) {
            $this->notifications->specialtyRequestRejected($account, $request);

            $this->email->sendTo($account, new SpecialtyDecisionMail(
                $account,
                approved: false,
                specialties: $request->technician?->skills()->pluck('skill_name')->all() ?? []
            ));
        }

        return $request->refresh();
    }

    /**
     * The technician's outstanding request, if they have one.
     */
    public function pendingSpecialtyRequest(Technician $technician): ?SpecialtyRequest
    {
        return SpecialtyRequest::query()
            ->pending()
            ->where('technician_id', $technician->technician_id)
            ->latest('specialty_request_id')
            ->first();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function guardPending(SpecialtyRequest $request): void
    {
        if (! $request->isPending()) {
            throw new RuntimeException('That request has already been decided.');
        }
    }

    private function displayName(User $user): string
    {
        return implode(' ', array_filter([
            trim((string) $user->first_name),
            trim((string) $user->middle_name),
            trim((string) $user->last_name),
        ]));
    }
}
