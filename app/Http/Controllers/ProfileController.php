<?php

namespace App\Http\Controllers;

use App\Models\Skill;
use App\Models\User;
use App\Services\ProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
 * which they may ask to change, but not change.
 */
class ProfileController extends Controller
{
    /**
     * The same rule the rest of the system holds passwords to.
     *
     * @var array<int, string>
     */
    private const PASSWORD_RULES = ['required', 'string', 'min:8', 'max:72', 'confirmed'];

    public function __construct(private readonly ProfileService $profile) {}

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
            'The profile picture could not be saved.'
        );
    }

    public function destroyPhoto(Request $request): RedirectResponse
    {
        return $this->attempt(
            function () use ($request): string {
                $this->profile->removePhoto($request->user());

                return 'Profile picture removed.';
            },
            'The profile picture could not be removed.'
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
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                // Their own address is theirs to keep; anybody else's is taken.
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ], [
            'email.unique' => 'Another account already uses that email address.',
        ]);

        return $this->attempt(
            function () use ($user, $data): string {
                $this->profile->updateInformation($user, $data);

                return 'Profile updated.';
            },
            'Your profile could not be updated.'
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
            'password' => self::PASSWORD_RULES,
        ], [
            'password.confirmed' => 'The two new passwords do not match.',
            'password.min' => 'The new password must be at least 8 characters.',
        ]);

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
            'Your password could not be updated.'
        );
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
            'skill_ids' => ['present', 'array'],
            'skill_ids.*' => ['integer', 'exists:tbl_skills,skill_id'],
        ], [
            'skill_ids.present' => 'No specialty selection was submitted.',
        ]);

        return $this->attempt(
            function () use ($technician, $validated): string {
                $this->profile->requestSpecialties(
                    $technician,
                    array_map('intval', $validated['skill_ids'] ?? [])
                );

                return 'Specialty request submitted. An administrator will review it.';
            },
            'Your specialty request could not be submitted.'
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
