<?php

namespace Tests\Feature;

use App\Mail\EmailChangedMail;
use App\Mail\OtpCodeMail;
use App\Models\ActivityLog;
use App\Models\OtpVerification;
use App\Models\Skill;
use App\Models\SpecialtyRequest;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Profile Management System.
 *
 * The rules worth holding onto are about authority rather than appearance: a
 * user edits only themselves, a technician asks rather than changes, a client
 * never has a picture, and nothing takes effect until whoever owns the
 * decision has made it.
 */
class ProfileManagementTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role, string $email, array $attributes = []): User
    {
        return User::create(array_merge([
            'user_code' => strtoupper(substr($role, 0, 3)).'-'.random_int(1000, 9999),
            'name' => 'Juan Dela Cruz',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'contact_number' => '09171234567',
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'is_archived' => false,
            'must_change_password' => false,
            'email_verified_at' => now(),
            'password' => 'password',
        ], $this->acceptedTerms(), $attributes));
    }

    private function technicianFor(User $user, array $skillNames = []): Technician
    {
        $technician = Technician::create(['account_id' => $user->id, 'role' => $user->role]);

        $technician->skills()->sync(
            collect($skillNames)
                ->map(fn (string $name): int => Skill::firstOrCreate(['skill_name' => $name])->skill_id)
                ->all()
        );

        return $technician->refresh();
    }

    // ------------------------------------------------------------------
    // The header
    // ------------------------------------------------------------------

    public function test_an_internal_header_shows_a_clickable_profile_with_picture_name_and_role(): void
    {
        $lead = $this->account('lead_technician', 'lead@example.test');
        $this->technicianFor($lead);

        $this->actingAs($lead)
            ->get(route('technician.projects'))
            ->assertOk()
            ->assertSee('admin-user-link', escape: false)
            ->assertSee(route('profile.edit'), escape: false)
            ->assertSee(asset('img/default-avatar.svg'), escape: false)
            ->assertSee('Juan Dela Cruz')
            ->assertSee('Lead Technician');
    }

    /**
     * The administrative header is the profile link and nothing else - no
     * caret, no account menu. Settings and Logout live in the sidebar.
     */
    public function test_the_administrative_header_has_no_account_dropdown(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');

        $this->actingAs($owner)
            ->get(route('super-admin.configuration.index'))
            ->assertOk()
            ->assertSee('admin-user-link', escape: false)
            ->assertDontSee('admin-user-caret', escape: false)
            ->assertDontSee('data-user-menu-toggle', escape: false);
    }

    /**
     * One button, not two. Get Started opens Login - which is what most people
     * arriving here want - and the form behind it is where somebody without an
     * account yet is sent to Register.
     */
    public function test_a_guest_header_offers_get_started(): void
    {
        $this->get(route('landing.home'))
            ->assertOk()
            ->assertSee('Get Started')
            ->assertSee(route('auth.login'), escape: false);

        $this->get(route('auth.login'))
            ->assertOk()
            ->assertSee(route('auth.register'), escape: false);
    }

    /**
     * One control, carrying the client's picture, their name and their email,
     * that opens the account menu.
     */
    public function test_a_client_header_carries_their_picture_name_and_email(): void
    {
        $client = $this->account('client', 'client@example.test');

        $this->actingAs($client)
            ->get(route('landing.home'))
            ->assertOk()
            ->assertSee('public-profile-link', escape: false)
            ->assertSee(route('profile.edit'), escape: false)
            ->assertSee('client@example.test')
            // Nothing uploaded yet, so the default avatar stands in.
            ->assertSee('default-avatar.svg');
    }

    // ------------------------------------------------------------------
    // The page
    // ------------------------------------------------------------------

    public function test_the_profile_page_offers_each_role_what_it_should(): void
    {
        // A technician: picture, details, password and specialties.
        $tech = $this->account('technician', 'tech@example.test');
        $this->technicianFor($tech, ['Electrical']);

        $this->actingAs($tech)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Upload Picture')
            ->assertSee('Personal Information')
            ->assertSee('Specialties')
            ->assertSee('Approved Specialties')
            ->assertSee('Electrical')
            ->assertSee('Change Password');

        // An administrator: everything but specialties.
        $admin = $this->account('admin', 'admin@example.test');

        $this->actingAs($admin)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Upload Picture')
            ->assertSee('Personal Information')
            ->assertSee('Change Password')
            ->assertDontSee('Approved Specialties');
    }

    public function test_a_pending_request_locks_the_specialty_form(): void
    {
        $tech = $this->account('technician', 'tech@example.test');
        $this->technicianFor($tech, ['Electrical']);

        $hvac = Skill::firstOrCreate(['skill_name' => 'HVAC']);

        $this->actingAs($tech)->post(route('profile.specialties.request'), ['skill_ids' => [$hvac->skill_id]]);

        $this->actingAs($tech)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Pending Approval')
            ->assertSee('Pending Changes')
            // The form is replaced by the pending panel, so there is nothing
            // to submit while a decision is outstanding.
            ->assertDontSee('Submit for Approval');
    }

    // ------------------------------------------------------------------
    // Profile picture
    // ------------------------------------------------------------------

    public function test_an_internal_user_uploads_replaces_and_removes_their_picture(): void
    {
        Storage::fake('uploads');

        $admin = $this->account('admin', 'admin@example.test');

        $this->actingAs($admin)
            ->post(route('profile.photo.update'), [
                'profile_photo' => UploadedFile::fake()->image('me.jpg'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Profile picture uploaded.');

        $first = $admin->refresh()->profile_photo_path;
        $this->assertNotNull($first);
        Storage::disk('uploads')->assertExists($first);
        $this->assertDatabaseHas('tbl_activity_logs', ['action' => ActivityLog::PROFILE_PHOTO_UPLOADED]);

        // Replacing keeps exactly one picture: the old file goes.
        $this->actingAs($admin)
            ->post(route('profile.photo.update'), [
                'profile_photo' => UploadedFile::fake()->image('newer.png'),
            ])
            ->assertSessionHas('success', 'Profile picture changed.');

        $second = $admin->refresh()->profile_photo_path;
        $this->assertNotSame($first, $second);
        Storage::disk('uploads')->assertMissing($first);
        $this->assertDatabaseHas('tbl_activity_logs', ['action' => ActivityLog::PROFILE_PHOTO_CHANGED]);

        $this->actingAs($admin)
            ->delete(route('profile.photo.destroy'))
            ->assertSessionHas('success', 'Profile picture removed.');

        $this->assertNull($admin->refresh()->profile_photo_path);
        Storage::disk('uploads')->assertMissing($second);
        $this->assertDatabaseHas('tbl_activity_logs', ['action' => ActivityLog::PROFILE_PHOTO_REMOVED]);

        // And with none set, the default avatar stands in.
        $this->assertSame(asset('img/default-avatar.svg'), $admin->avatarUrl());
    }

    /**
     * A client sets their own picture from their profile page, the same way
     * everybody else does.
     */
    public function test_a_client_sets_and_removes_their_own_picture(): void
    {
        Storage::fake('uploads');

        $client = $this->account('client', 'client@example.test');

        $this->assertTrue($client->usesProfilePhoto());
        // Nothing set yet, so the default avatar stands in.
        $this->assertSame(asset('img/default-avatar.svg'), $client->avatarUrl());

        $this->actingAs($client)
            ->post(route('profile.photo.update'), [
                'profile_photo' => UploadedFile::fake()->image('me.jpg'),
            ])
            ->assertSessionHas('success');

        $stored = $client->refresh()->profile_photo_path;

        $this->assertNotNull($stored);
        Storage::disk('uploads')->assertExists($stored);
        $this->assertNotSame(asset('img/default-avatar.svg'), $client->avatarUrl());

        $this->actingAs($client)
            ->delete(route('profile.photo.destroy'))
            ->assertSessionHas('success', 'Profile picture removed.');

        $this->assertNull($client->refresh()->profile_photo_path);
        Storage::disk('uploads')->assertMissing($stored);
        $this->assertSame(asset('img/default-avatar.svg'), $client->avatarUrl());
    }

    // ------------------------------------------------------------------
    // Personal information and password
    // ------------------------------------------------------------------

    /**
     * The name is applied at once. The email is not: it is parked until the
     * code sent to the new address comes back.
     */
    public function test_a_user_updates_their_own_name_and_asks_to_change_their_email(): void
    {
        Mail::fake();

        $technician = $this->account('technician', 'tech@example.test');
        $this->technicianFor($technician);

        $this->actingAs($technician)
            ->put(route('profile.information'), [
                'first_name' => 'Maria',
                'middle_name' => 'S',
                'last_name' => 'Reyes',
                'contact_number' => '09171234567',
                'email' => 'maria@example.test',
            ])
            ->assertSessionHas('success');

        $technician->refresh();

        $this->assertSame('Maria S Reyes', $technician->fullName());
        // `name` is what the topbar and every listing read, so it has to keep
        // up with the parts.
        $this->assertSame('Maria S Reyes', $technician->name);

        // The address they sign in with has not moved.
        $this->assertSame('tech@example.test', $technician->email);
        $this->assertSame('maria@example.test', $technician->pending_email);

        Mail::assertSent(OtpCodeMail::class, fn (OtpCodeMail $mail): bool => $mail->hasTo('maria@example.test')
            && $mail->purpose === OtpVerification::PURPOSE_EMAIL_CHANGE);

        $this->assertDatabaseHas('tbl_activity_logs', ['action' => ActivityLog::PROFILE_NAME_UPDATED]);
        $this->assertDatabaseHas('tbl_activity_logs', ['action' => ActivityLog::EMAIL_CHANGE_REQUESTED]);
    }

    /**
     * The code is the whole of the change: entering it moves the address, and
     * the old mailbox is told so.
     */
    public function test_the_emailed_code_completes_the_change_of_address(): void
    {
        Mail::fake();

        $technician = $this->account('technician', 'tech@example.test');
        $this->technicianFor($technician);

        $this->actingAs($technician)->put(route('profile.information'), [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'contact_number' => '09171234567',
            'email' => 'maria@example.test',
        ]);

        $this->actingAs($technician)
            ->post(route('profile.email.verify'), ['code' => $this->issuedCode()])
            ->assertSessionHas('success');

        $technician->refresh();

        $this->assertSame('maria@example.test', $technician->email);
        $this->assertNull($technician->pending_email);
        $this->assertTrue($technician->hasVerifiedEmail());

        // The address being left behind is warned.
        Mail::assertQueued(EmailChangedMail::class, fn (EmailChangedMail $mail): bool => $mail->hasTo('tech@example.test'));

        $this->assertDatabaseHas('tbl_activity_logs', ['action' => ActivityLog::EMAIL_CHANGED]);
    }

    /**
     * A wrong code leaves the account exactly as it was - which is the whole
     * point of not applying the new address up front.
     */
    public function test_a_wrong_code_leaves_the_old_address_in_place(): void
    {
        Mail::fake();

        $technician = $this->account('technician', 'tech@example.test');
        $this->technicianFor($technician);

        $this->actingAs($technician)->put(route('profile.information'), [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'contact_number' => '09171234567',
            'email' => 'maria@example.test',
        ]);

        $this->actingAs($technician)
            ->post(route('profile.email.verify'), ['code' => '000000'])
            ->assertSessionHas('error');

        $technician->refresh();

        $this->assertSame('tech@example.test', $technician->email);
        $this->assertSame('maria@example.test', $technician->pending_email);
    }

    public function test_a_pending_change_of_address_can_be_abandoned(): void
    {
        Mail::fake();

        $technician = $this->account('technician', 'tech@example.test');
        $this->technicianFor($technician);

        $this->actingAs($technician)->put(route('profile.information'), [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'contact_number' => '09171234567',
            'email' => 'maria@example.test',
        ]);

        $this->actingAs($technician)
            ->delete(route('profile.email.cancel'))
            ->assertSessionHas('success');

        $technician->refresh();

        $this->assertSame('tech@example.test', $technician->email);
        $this->assertNull($technician->pending_email);
        $this->assertDatabaseCount('tbl_otp_verifications', 0);
    }

    /**
     * The six digits, read back from the message that carried them.
     */
    private function issuedCode(): string
    {
        $code = null;

        Mail::assertSent(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        return (string) $code;
    }

    /**
     * A number that has changed is the account holder's own to correct, and
     * it takes effect at once - unlike the email, which has to be proved.
     */
    public function test_a_user_changes_their_own_contact_number(): void
    {
        $technician = $this->account('technician', 'tech@example.test');
        $this->technicianFor($technician);

        $this->actingAs($technician)
            ->put(route('profile.information'), [
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'contact_number' => '09182223344',
                'email' => 'tech@example.test',
            ])
            ->assertSessionHas('success');

        $this->assertSame('09182223344', $technician->refresh()->contact_number);

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::PROFILE_UPDATED,
            'subject_id' => $technician->id,
        ]);
    }

    public function test_a_contact_number_that_is_not_one_is_refused(): void
    {
        $technician = $this->account('technician', 'tech@example.test');
        $this->technicianFor($technician);

        $this->actingAs($technician)
            ->put(route('profile.information'), [
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'contact_number' => 'call me maybe',
                'email' => 'tech@example.test',
            ])
            ->assertSessionHasErrors(
                ['contact_number' => 'Enter an 11-digit contact number, digits only (e.g. 09171234567).'],
                null,
                'information'
            );

        $this->assertSame('09171234567', $technician->refresh()->contact_number);
    }

    /**
     * The number is editable on the page; the birthdate is shown but is an
     * administrator's to set.
     */
    public function test_the_page_edits_the_contact_number_and_shows_the_birthdate(): void
    {
        $technician = $this->account('technician', 'tech@example.test', [
            'birthdate' => '1990-05-04',
        ]);
        $this->technicianFor($technician);

        $response = $this->actingAs($technician)->get(route('profile.edit'))->assertOk();

        $response->assertSee('name="contact_number"', escape: false);
        $response->assertSee('value="09171234567"', escape: false);
        $response->assertSee('Date of Birth');
        $response->assertSee('May 4, 1990');
    }

    public function test_an_email_already_in_use_is_refused(): void
    {
        $this->account('admin', 'taken@example.test');
        $technician = $this->account('technician', 'tech@example.test');
        $this->technicianFor($technician);

        $this->actingAs($technician)
            ->put(route('profile.information'), [
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'contact_number' => '09171234567',
                'email' => 'taken@example.test',
            ])
            ->assertSessionHasErrors(['email' => 'Another account already uses that email address.'], null, 'information');

        $this->assertSame('tech@example.test', $technician->refresh()->email);
    }

    public function test_the_password_changes_only_with_the_current_one(): void
    {
        $admin = $this->account('admin', 'admin@example.test');

        $this->actingAs($admin)
            ->put(route('profile.password'), [
                'current_password' => 'wrong-password',
                'password' => 'a-brand-new-one',
                'password_confirmation' => 'a-brand-new-one',
            ])
            ->assertSessionHasErrors('current_password', null, 'password');

        $this->assertTrue(Hash::check('password', $admin->refresh()->password));

        $this->actingAs($admin)
            ->put(route('profile.password'), [
                'current_password' => 'password',
                'password' => 'a-brand-new-one',
                'password_confirmation' => 'a-brand-new-one',
            ])
            ->assertSessionHas('success', 'Password updated.');

        $this->assertTrue(Hash::check('a-brand-new-one', $admin->refresh()->password));
        $this->assertDatabaseHas('tbl_activity_logs', ['action' => ActivityLog::PASSWORD_CHANGED]);

        // The value itself never reaches the trail.
        $this->assertDatabaseMissing('tbl_activity_logs', ['description' => 'a-brand-new-one']);
    }

    // ------------------------------------------------------------------
    // Specialties
    // ------------------------------------------------------------------

    public function test_a_specialty_request_changes_nothing_until_it_is_approved(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');
        $tech = $this->account('technician', 'tech@example.test');
        $technician = $this->technicianFor($tech, ['Electrical', 'Plumbing']);

        $hvac = Skill::firstOrCreate(['skill_name' => 'HVAC']);
        $electrical = Skill::where('skill_name', 'Electrical')->firstOrFail();

        // Asking for Electrical + HVAC: adds HVAC, drops Plumbing.
        $this->actingAs($tech)
            ->post(route('profile.specialties.request'), [
                'skill_ids' => [$electrical->skill_id, $hvac->skill_id],
            ])
            ->assertSessionHas('success');

        // The approved set is untouched while the request waits.
        $this->assertEqualsCanonicalizing(
            ['Electrical', 'Plumbing'],
            $technician->refresh()->skills->pluck('skill_name')->all()
        );

        $request = SpecialtyRequest::query()->pending()->firstOrFail();
        $this->assertEqualsCanonicalizing(['HVAC'], $request->additions()->all());
        $this->assertEqualsCanonicalizing(['Plumbing'], $request->removals()->all());

        $this->assertDatabaseHas('tbl_activity_logs', ['action' => ActivityLog::SPECIALTY_REQUEST_SUBMITTED]);

        // Every administrator hears about it.
        $this->assertDatabaseHas('tbl_notifications', [
            'user_id' => $owner->id,
            'title' => 'Specialty Request',
        ]);

        // A second request is refused while the first is outstanding.
        $this->actingAs($tech)
            ->post(route('profile.specialties.request'), ['skill_ids' => [$hvac->skill_id]])
            ->assertSessionHas('error', 'You already have a specialty request awaiting approval.');

        $this->assertSame(1, SpecialtyRequest::query()->pending()->count());

        // Approving is what moves them. The decision is taken inside the
        // technician's details dialog, so it answers with JSON.
        $this->actingAs($owner)
            ->putJson(route('super-admin.technicians.specialty-requests.approve', $request))
            ->assertOk()
            ->assertJsonPath('message', 'Specialty request approved.')
            // The dialog redraws from this rather than reloading the page.
            ->assertJsonPath('technician.pending_request', null);

        $this->assertEqualsCanonicalizing(
            ['Electrical', 'HVAC'],
            $technician->refresh()->skills->pluck('skill_name')->all()
        );

        $this->assertSame(SpecialtyRequest::STATUS_APPROVED, $request->refresh()->status);
        $this->assertSame($owner->id, $request->reviewed_by);
        $this->assertDatabaseHas('tbl_activity_logs', ['action' => ActivityLog::SPECIALTY_REQUEST_APPROVED]);
        $this->assertDatabaseHas('tbl_notifications', [
            'user_id' => $tech->id,
            'title' => 'Specialty Update Approved',
        ]);
    }

    public function test_a_rejected_request_leaves_the_specialties_alone(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');
        $tech = $this->account('technician', 'tech@example.test');
        $technician = $this->technicianFor($tech, ['Electrical']);

        $hvac = Skill::firstOrCreate(['skill_name' => 'HVAC']);

        $this->actingAs($tech)
            ->post(route('profile.specialties.request'), ['skill_ids' => [$hvac->skill_id]])
            ->assertSessionHas('success');

        $request = SpecialtyRequest::query()->pending()->firstOrFail();

        $this->actingAs($owner)
            ->putJson(route('super-admin.technicians.specialty-requests.reject', $request))
            ->assertOk()
            ->assertJsonPath('message', 'Specialty request rejected.');

        $this->assertSame(['Electrical'], $technician->refresh()->skills->pluck('skill_name')->all());
        $this->assertSame(SpecialtyRequest::STATUS_REJECTED, $request->refresh()->status);
        $this->assertDatabaseHas('tbl_activity_logs', ['action' => ActivityLog::SPECIALTY_REQUEST_REJECTED]);
        $this->assertDatabaseHas('tbl_notifications', [
            'user_id' => $tech->id,
            'title' => 'Specialty Update Rejected',
        ]);

        // Turned down is not the same as blocked: they may ask again.
        $this->actingAs($tech)
            ->post(route('profile.specialties.request'), ['skill_ids' => [$hvac->skill_id]])
            ->assertSessionHas('success');
    }

    public function test_a_decided_request_cannot_be_decided_twice(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');
        $tech = $this->account('technician', 'tech@example.test');
        $this->technicianFor($tech, ['Electrical']);

        $hvac = Skill::firstOrCreate(['skill_name' => 'HVAC']);

        $this->actingAs($tech)->post(route('profile.specialties.request'), ['skill_ids' => [$hvac->skill_id]]);

        $request = SpecialtyRequest::query()->pending()->firstOrFail();

        $this->actingAs($owner)->put(route('super-admin.technicians.specialty-requests.approve', $request));

        $this->actingAs($owner)
            ->putJson(route('super-admin.technicians.specialty-requests.reject', $request))
            ->assertStatus(422)
            ->assertJsonPath('error', 'That request has already been decided.');
    }

    public function test_only_a_technician_may_ask_and_only_an_administrator_may_decide(): void
    {
        $admin = $this->account('admin', 'admin@example.test');
        $tech = $this->account('technician', 'tech@example.test');
        $this->technicianFor($tech, ['Electrical']);

        $hvac = Skill::firstOrCreate(['skill_name' => 'HVAC']);

        // An admin has no specialties to ask about; the route turns them away.
        $this->actingAs($admin)
            ->post(route('profile.specialties.request'), ['skill_ids' => [$hvac->skill_id]])
            ->assertRedirect();

        $this->assertSame(0, SpecialtyRequest::query()->count());

        $this->actingAs($tech)->post(route('profile.specialties.request'), ['skill_ids' => [$hvac->skill_id]]);
        $request = SpecialtyRequest::query()->pending()->firstOrFail();

        // A technician cannot approve their own request: the whole Super Admin
        // area is closed to them.
        $this->actingAs($tech)
            ->putJson(route('super-admin.technicians.specialty-requests.approve', $request))
            ->assertForbidden();

        $this->assertTrue($request->refresh()->isPending());

        // An Admin can, though - deciding is not a Super Admin privilege.
        $this->actingAs($admin)
            ->putJson(route('super-admin.technicians.specialty-requests.approve', $request))
            ->assertOk();

        $this->assertSame(SpecialtyRequest::STATUS_APPROVED, $request->refresh()->status);
    }

    /**
     * There is no queue page: the Technicians table highlights whoever is
     * waiting, and the decision is taken in their details dialog.
     */
    public function test_the_technicians_table_highlights_who_is_waiting(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');
        $tech = $this->account('technician', 'tech@example.test', [
            'name' => 'Michael Santos',
            'first_name' => 'Michael',
            'last_name' => 'Santos',
        ]);
        $technician = $this->technicianFor($tech, ['Electrical']);

        $hvac = Skill::firstOrCreate(['skill_name' => 'HVAC']);

        $this->actingAs($tech)->post(route('profile.specialties.request'), ['skill_ids' => [$hvac->skill_id]]);

        $this->actingAs($owner)
            ->get(route('super-admin.technicians.index'))
            ->assertOk()
            ->assertSee('Michael Santos')
            ->assertSee('technician-row-pending', escape: false)
            ->assertSee('waiting on a specialty decision')
            // The tab it used to live on is gone.
            ->assertDontSee('specialtyRequestsPane', escape: false);

        // And the dialog behind the eye button carries the request itself.
        $this->actingAs($owner)
            ->getJson(route('super-admin.technicians.show', $technician))
            ->assertOk()
            ->assertJsonPath('pending_request.additions.0', 'HVAC')
            ->assertJsonPath('pending_request.removals.0', 'Electrical');
    }

    public function test_a_technician_with_nothing_pending_is_not_highlighted(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');
        $tech = $this->account('technician', 'tech@example.test');
        $technician = $this->technicianFor($tech, ['Electrical']);

        $this->actingAs($owner)
            ->get(route('super-admin.technicians.index'))
            ->assertOk()
            ->assertDontSee('technician-row-pending', escape: false)
            ->assertDontSee('waiting on a specialty decision');

        $this->actingAs($owner)
            ->getJson(route('super-admin.technicians.show', $technician))
            ->assertOk()
            ->assertJsonPath('pending_request', null);
    }

    // ------------------------------------------------------------------
    // User Management no longer takes a picture
    // ------------------------------------------------------------------

    public function test_creating_a_user_never_asks_for_a_picture(): void
    {
        Storage::fake('uploads');

        $owner = $this->account('super_admin', 'owner@example.test');

        $this->actingAs($owner)
            ->get(route('super-admin.configuration.index'))
            ->assertOk()
            ->assertDontSee('data-photo-input', escape: false)
            ->assertDontSee('Profile Picture');

        $this->actingAs($owner)
            ->postJson(route('super-admin.configuration.users.employees.store'), [
                'first_name' => 'New',
                'last_name' => 'Technician',
                'contact_number' => '09171234567',
                'birthdate' => '1990-05-04',
                'email' => 'new@example.test',
                'role' => 'technician',
                'skill_ids' => [Skill::firstOrCreate(['skill_name' => 'Electrical'])->skill_id],
            ])
            ->assertCreated();

        $created = User::where('email', 'new@example.test')->firstOrFail();

        // No picture, and therefore the default avatar.
        $this->assertNull($created->profile_photo_path);
        $this->assertSame(asset('img/default-avatar.svg'), $created->avatarUrl());
    }

    public function test_an_admin_cannot_create_another_admin(): void
    {
        $admin = $this->account('admin', 'admin@example.test');

        $this->actingAs($admin)
            ->postJson(route('super-admin.configuration.users.employees.store'), [
                'first_name' => 'Second',
                'last_name' => 'Admin',
                'contact_number' => '09171234567',
                'birthdate' => '1990-05-04',
                'email' => 'second@example.test',
                'role' => 'admin',
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'second@example.test']);

        // The role is not even offered by the form. Read from the view's own
        // data rather than the markup: the Activity Logs filter legitimately
        // lists every role, Admin included.
        $response = $this->actingAs($admin)
            ->get(route('super-admin.configuration.index'))
            ->assertOk();

        $this->assertArrayNotHasKey('admin', $response->original->getData()['assignableRoles']);
        $this->assertArrayHasKey('technician', $response->original->getData()['assignableRoles']);

        // A Super Admin still can.
        $owner = $this->account('super_admin', 'owner@example.test');

        $this->actingAs($owner)
            ->postJson(route('super-admin.configuration.users.employees.store'), [
                'first_name' => 'Second',
                'last_name' => 'Admin',
                'contact_number' => '09171234567',
                'birthdate' => '1990-05-04',
                'email' => 'second@example.test',
                'role' => 'admin',
            ])
            ->assertCreated();
    }
}
