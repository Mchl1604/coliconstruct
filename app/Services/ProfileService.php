<?php

namespace App\Services;

use App\Models\ActivityLog;
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
        private readonly NotificationService $notifications
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
        $this->guardInternal($user, 'Client accounts do not use profile pictures.');

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
        $this->guardInternal($user, 'Client accounts do not use profile pictures.');

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
     * @param  array{first_name: string, middle_name?: ?string, last_name: string, email: string}  $data
     */
    public function updateInformation(User $user, array $data): User
    {
        $nameBefore = $user->fullName();
        $emailBefore = $user->email;

        DB::transaction(function () use ($user, $data): void {
            $user->fill([
                'first_name' => trim($data['first_name']),
                'middle_name' => filled($data['middle_name'] ?? null) ? trim((string) $data['middle_name']) : null,
                'last_name' => trim($data['last_name']),
                'email' => trim($data['email']),
            ]);

            // `name` is what the topbar, technician listings and report joins
            // all read, so it has to stay in step with the parts.
            $user->name = $this->displayName($user);
            $user->save();
        });

        // Two separate facts, logged separately: "who changed their email" is a
        // question worth being able to ask on its own.
        if ($user->fullName() !== $nameBefore) {
            $this->activityLogger->record(
                ActivityLog::PROFILE_NAME_UPDATED,
                $user,
                sprintf('Changed their name from %s to %s.', $nameBefore, $user->fullName())
            );
        }

        if ($user->email !== $emailBefore) {
            $this->activityLogger->record(
                ActivityLog::PROFILE_EMAIL_UPDATED,
                $user,
                sprintf('Changed their email address from %s to %s.', $emailBefore, $user->email)
            );
        }

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
                $this->changeSummary($request)
            ),
            $request
        );

        $this->notifications->specialtyRequestSubmitted($account);

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
                $this->changeSummary($request)
            ),
            $request
        );

        if ($account) {
            $this->notifications->specialtyRequestApproved($account);
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
                $this->changeSummary($request)
            ),
            $request
        );

        if ($account) {
            $this->notifications->specialtyRequestRejected($account);
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

    /**
     * "+ HVAC, - Plumbing", or "no change" for a request that somehow carries
     * neither - which the submission guard already rules out.
     */
    private function changeSummary(SpecialtyRequest $request): string
    {
        $parts = [];

        if ($request->additions()->isNotEmpty()) {
            $parts[] = 'added '.$request->additions()->implode(', ');
        }

        if ($request->removals()->isNotEmpty()) {
            $parts[] = 'removed '.$request->removals()->implode(', ');
        }

        return $parts === [] ? 'no change' : implode('; ', $parts);
    }

    private function guardPending(SpecialtyRequest $request): void
    {
        if (! $request->isPending()) {
            throw new RuntimeException('That request has already been decided.');
        }
    }

    /**
     * Profile pictures are for the people who run the work. A client is a
     * customer, and the system shows their name and nothing more.
     */
    private function guardInternal(User $user, string $message): void
    {
        if ($user->isClient()) {
            throw new RuntimeException($message);
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
