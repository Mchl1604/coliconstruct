<?php

namespace App\Http\Controllers;

use App\Models\OtpVerification;
use App\Models\Skill;
use App\Models\User;
use App\Services\OtpService;
use App\Services\ProfileService;
use App\Services\UserAccountService;
use App\Support\PasswordPolicy;
use App\Support\PersonName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * The signed-in account's own details.
 *
 * Every action here operates on `$request->user()` and never on an id from the
 * request: "users may edit only their own profile" is not a check that can be
 * forgotten if there is nothing else to address.
 *
 * What a profile offers depends on who is reading it. A client gets their name,
 * their email and their password. Everyone who runs the work also gets a
 * profile picture, and the two technician roles additionally get specialties -
 * which they may ask to change, but not change. The Super Admin also gets the
 * one way their role changes hands.
 */
class ProfileController extends Controller
{
    /** The Admin a Super Admin transfer is waiting to go to, between its code and its confirmation. */
    public const TRANSFER_SESSION_KEY = 'super_admin_transfer.new_owner_id';

    public function __construct(
        private readonly ProfileService $profile,
        private readonly UserAccountService $accounts,
        private readonly OtpService $otp
    ) {}

    public function edit(Request $request)
    {
        $user = $request->user();

        // Each role reads this inside the shell it already lives in; a client
        // has no portal of their own, so theirs is the public website.
        $layout = match (true) {
            in_array($user->role, User::ADMINISTRATOR_ROLES, true) => 'layouts.superadminNav',
            $user->isClient() => 'layouts.publicSite',
            default => 'layouts.portalNav',
        };

        $technician = $user->technician;

        return view('profile.edit', [
            'account' => $user,
            'layout' => $layout,
            'technician' => $technician,
            // Specialties belong to the two technician roles. An account that
            // used to be a technician keeps its record, so the role decides
            // rather than the record's existence.
            'showsSpecialties' => $technician !== null
                && in_array($user->role, User::TECHNICIAN_ROLES, true),
            'approvedSkills' => $technician?->skills->sortBy('skill_name')->values() ?? collect(),
            'pendingRequest' => $technician ? $this->profile->pendingSpecialtyRequest($technician) : null,
            'allSkills' => Skill::query()->orderBy('skill_name')->get(['skill_id', 'skill_name']),
            // A change of email waiting on the code sent to the new address.
            'pendingEmail' => $user->pending_email,
            'emailRetryAfter' => $user->hasPendingEmailChange()
                ? app(OtpService::class)->secondsUntilResend(
                    (string) $user->pending_email,
                    OtpVerification::PURPOSE_EMAIL_CHANGE
                )
                : 0,
            // Who the Super Admin could hand their role to: an active Admin
            // who has verified their email, the only kind
            // UserAccountService::transferSuperAdmin() accepts.
            'superAdminCandidates' => $user->isSuperAdmin()
                ? User::query()
                    ->loginable()
                    ->where('role', User::ROLE_ADMIN)
                    ->whereNotNull('email_verified_at')
                    ->orderBy('last_name')
                    ->orderBy('first_name')
                    ->get()
                : collect(),
        ]);
    }

    // ------------------------------------------------------------------
    // Profile picture
    // ------------------------------------------------------------------

    public function updatePhoto(Request $request): RedirectResponse
    {
        $request->validateWithBag('photo', [
            'profile_photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'profile_photo.required' => 'Choose an image to upload.',
            'profile_photo.image' => 'The profile picture must be an image.',
            'profile_photo.max' => 'The profile picture must be 5 MB or smaller.',
        ]);

        // Whether this is a first upload or a replacement decides the wording
        // as well as which action the trail records.
        $isReplacement = filled($request->user()->profile_photo_path);

        return $this->attempt(
            function () use ($request, $isReplacement): string {
                $this->profile->updatePhoto($request->user(), $request->file('profile_photo'));

                return $isReplacement ? 'Profile picture changed.' : 'Profile picture uploaded.';
            },
            'Unable to save profile picture.'
        );
    }

    public function destroyPhoto(Request $request): RedirectResponse
    {
        return $this->attempt(
            function () use ($request): string {
                $this->profile->removePhoto($request->user());

                return 'Profile picture removed.';
            },
            'Unable to remove profile picture.'
        );
    }

    // ------------------------------------------------------------------
    // Personal information
    // ------------------------------------------------------------------

    public function updateInformation(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validateWithBag('information', [
            'first_name' => ['required', 'string', 'max:100'],
            // An initial, not a name - see PersonName, which every form
            // collecting one is held to.
            'middle_name' => PersonName::middleInitialRules(),
            'last_name' => ['required', 'string', 'max:100'],
            // Theirs to correct: a number that has changed is no use to
            // anybody sitting behind an administrator's queue.
            'contact_number' => ['required', 'string', 'max:'.User::CONTACT_NUMBER_LENGTH, User::CONTACT_NUMBER_RULE],
            'email' => [
                'required', 'string', 'email', 'max:255',
                // Their own address is theirs to keep; anybody else's is taken,
                // including one somebody else is part-way through claiming.
                Rule::unique('users', 'email')->ignore($user->id),
                Rule::unique('users', 'pending_email')->ignore($user->id),
            ],
        ], [
            'email.unique' => 'Another account already uses that email address.',
            'contact_number.regex' => User::CONTACT_NUMBER_MESSAGE,
            'contact_number.max' => User::CONTACT_NUMBER_MESSAGE,
            ...PersonName::middleInitialMessages(),
        ]);

        return $this->attempt(
            function () use ($user, $data): string {
                $result = $this->profile->updateInformation($user, $data);

                // A new address is not applied until a code sent to it comes
                // back, so the message must not claim the email has changed.
                return $result['email_pending']
                    ? 'Profile updated. Enter the code sent to '.$user->pending_email.'.'
                    : 'Profile updated.';
            },
            'Unable to update profile.'
        );
    }

    // ------------------------------------------------------------------
    // Changing the sign-in address
    // ------------------------------------------------------------------

    /**
     * Confirm the parked address with the code emailed to it.
     *
     * A wrong or expired code changes nothing: the account keeps the address
     * it already had, and the person may ask for another code.
     */
    public function verifyEmailChange(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('emailChange', [
            'code' => ['required', 'string', 'max:12'],
        ], [
            'code.required' => 'Enter the emailed code.',
        ]);

        $user = $request->user();

        return $this->attempt(
            function () use ($user, $validated): string {
                $updated = $this->profile->confirmEmailChange($user, $validated['code']);

                return 'Your email address is now '.$updated->email.'.';
            },
            'Unable to change email address.'
        );
    }

    public function resendEmailChange(Request $request): RedirectResponse
    {
        $user = $request->user();

        return $this->attempt(
            function () use ($user): string {
                $this->profile->resendEmailChangeCode($user);

                return 'A new code is on its way to '.$user->pending_email.'.';
            },
            'Unable to send a new code.'
        );
    }

    public function cancelEmailChange(Request $request): RedirectResponse
    {
        $user = $request->user();

        return $this->attempt(
            function () use ($user): string {
                $this->profile->cancelEmailChange($user);

                return 'Email change cancelled.';
            },
            'Unable to cancel the email change.'
        );
    }

    // ------------------------------------------------------------------
    // Password
    // ------------------------------------------------------------------

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validateWithBag('password', [
            'current_password' => ['required', 'string'],
            // The same policy every other way of setting a password is held to.
            'password' => PasswordPolicy::rules(),
        ], [
            'password.confirmed' => 'The two new passwords do not match.',
        ] + PasswordPolicy::messages());

        // Checked here rather than with the `current_password` rule so the
        // message names the field a person actually sees.
        if (! Hash::check($validated['current_password'], (string) $user->password)) {
            return back()
                ->withErrors(['current_password' => 'That is not your current password.'], 'password')
                ->withInput();
        }

        return $this->attempt(
            function () use ($user, $validated): string {
                $this->profile->updatePassword($user, $validated['password']);

                return 'Password updated.';
            },
            'Unable to update password.'
        );
    }

    // ------------------------------------------------------------------
    // Super Admin
    // ------------------------------------------------------------------

    /**
     * Email the Super Admin the code that confirms handing their role over.
     *
     * The first half of the transfer, sent when the dialog opens. The Admin is
     * checked before anything is sent, so a code is never spent on a transfer
     * that would be refused. Who the role goes to is then held in the session
     * rather than read again from the confirmation, so a code asked for one
     * Admin cannot confirm a transfer to another.
     */
    public function sendSuperAdminTransferCode(Request $request): JsonResponse
    {
        $owner = $request->user();

        $validator = Validator::make($request->all(), [
            'new_owner_id' => ['required', 'integer'],
        ], [
            'new_owner_id.required' => 'Choose an Admin.',
            'new_owner_id.integer' => 'Choose an Admin.',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $newOwner = User::find($validator->validated()['new_owner_id']);

        try {
            $this->accounts->guardSuperAdminSuccessor($newOwner);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $purpose = OtpVerification::PURPOSE_SUPER_ADMIN_TRANSFER;
        $wait = $this->otp->secondsUntilResend($owner->email, $purpose);

        // The dialog opened again for the same Admin while the last code is
        // still within its resend wait: that code stands, and is not
        // replaced by one the wait would refuse.
        if ($wait > 0 && (int) $request->session()->get(self::TRANSFER_SESSION_KEY) === $newOwner->id) {
            return response()->json([
                'email' => $owner->email,
                'retry_after' => $wait,
            ]);
        }

        try {
            $this->otp->issue($owner->email, $purpose, $owner);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $request->session()->put(self::TRANSFER_SESSION_KEY, $newOwner->id);

        return response()->json([
            'email' => $owner->email,
            'retry_after' => OtpService::RESEND_COOLDOWN_SECONDS,
        ]);
    }

    /**
     * Check the emailed code and, only then, hand the Super Admin role to the
     * Admin it was sent for - stepping down to Admin in the same move. See
     * UserAccountService::transferSuperAdmin().
     */
    public function transferSuperAdmin(Request $request): JsonResponse
    {
        $owner = $request->user();
        $newOwner = User::find($request->session()->get(self::TRANSFER_SESSION_KEY));

        if (! $newOwner) {
            return response()->json(['error' => 'Send a code first.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:12'],
        ], [
            'code.required' => 'Enter the emailed code.',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        try {
            $this->otp->verify(
                $owner->email,
                OtpVerification::PURPOSE_SUPER_ADMIN_TRANSFER,
                (string) $validator->validated()['code']
            );

            $this->accounts->transferSuperAdmin($newOwner);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Unable to transfer the role.'], 500);
        }

        // Nothing of the workflow is left standing to be replayed.
        $this->otp->clear($owner->email, OtpVerification::PURPOSE_SUPER_ADMIN_TRANSFER);
        $request->session()->forget(self::TRANSFER_SESSION_KEY);

        // The page reloads: it is now an Admin's profile.
        $request->session()->flash('success', 'Super Admin transferred. You are now an Admin.');

        return response()->json(['redirect' => route('profile.edit')]);
    }

    // ------------------------------------------------------------------
    // Specialties
    // ------------------------------------------------------------------

    /**
     * Ask for a different set of specialties.
     *
     * Nothing changes here except that an administrator now has something to
     * decide - see ProfileService::requestSpecialties().
     */
    public function requestSpecialties(Request $request): RedirectResponse
    {
        $user = $request->user();
        $technician = $user->technician;

        if (! $technician || ! in_array($user->role, User::TECHNICIAN_ROLES, true)) {
            return back()->with('error', 'Only technicians hold specialties.');
        }

        $validated = $request->validateWithBag('specialties', [
            'skill_ids' => ['required', 'array', 'min:1'],
            'skill_ids.*' => ['integer', 'exists:tbl_skills,skill_id'],
        ], [
            'skill_ids.required' => 'Select at least one specialty.',
            'skill_ids.min' => 'Select at least one specialty.',
        ]);

        return $this->attempt(
            function () use ($technician, $validated): string {
                $this->profile->requestSpecialties(
                    $technician,
                    array_map('intval', $validated['skill_ids'] ?? [])
                );

                return 'Specialty request submitted for approval.';
            },
            'Unable to submit specialty request.'
        );
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Run one profile action and turn its outcome into a toast.
     *
     * A RuntimeException from the service is something the person can fix and
     * carries its own message; anything else is a fault, reported without
     * leaking its internals.
     *
     * @param  callable(): string  $action  Returns the success message.
     */
    private function attempt(callable $action, string $fallback): RedirectResponse
    {
        try {
            $message = $action();
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $fallback);
        }

        return back()->with('success', $message);
    }
}
