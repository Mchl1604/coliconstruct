<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Skill;
use App\Models\SpecialtyRequest;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
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
            'password' => 'password',
        ], $attributes));
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

    public function test_a_guest_header_offers_sign_up_and_login(): void
    {
        $this->get(route('landing.home'))
            ->assertOk()
            // Sign Up opens an account; Login is for people who have one.
            ->assertSee('Sign Up')
            ->assertSee(route('auth.register'), escape: false)
            ->assertSee('Login')
            ->assertSee(route('auth.login'), escape: false)
            ->assertSee('btn-sign-up', escape: false);
    }

    public function test_a_client_header_carries_a_name_and_never_a_picture(): void
    {
        $client = $this->account('client', 'client@example.test');

        $this->actingAs($client)
            ->get(route('landing.home'))
            ->assertOk()
            ->assertSee('public-profile-link', escape: false)
            ->assertSee(route('profile.edit'), escape: false)
            ->assertSee('client@example.test')
            ->assertDontSee('default-avatar.svg');
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
        Storage::fake('public');

        $admin = $this->account('admin', 'admin@example.test');

        $this->actingAs($admin)
            ->post(route('profile.photo.update'), [
                'profile_photo' => UploadedFile::fake()->image('me.jpg'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Profile picture uploaded.');

        $first = $admin->refresh()->profile_photo_path;
        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first);
        $this->assertDatabaseHas('tbl_activity_logs', ['action' => ActivityLog::PROFILE_PHOTO_UPLOADED]);

        // Replacing keeps exactly one picture: the old file goes.
        $this->actingAs($admin)
            ->post(route('profile.photo.update'), [
                'profile_photo' => UploadedFile::fake()->image('newer.png'),
            ])
            ->assertSessionHas('success', 'Profile picture changed.');

        $second = $admin->refresh()->profile_photo_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        $this->assertDatabaseHas('tbl_activity_logs', ['action' => ActivityLog::PROFILE_PHOTO_CHANGED]);

        $this->actingAs($admin)
            ->delete(route('profile.photo.destroy'))
            ->assertSessionHas('success', 'Profile picture removed.');

        $this->assertNull($admin->refresh()->profile_photo_path);
        Storage::disk('public')->assertMissing($second);
        $this->assertDatabaseHas('tbl_activity_logs', ['action' => ActivityLog::PROFILE_PHOTO_REMOVED]);

        // And with none set, the default avatar stands in.
        $this->assertSame(asset('img/default-avatar.svg'), $admin->avatarUrl());
    }

    public function test_a_client_has_no_picture_to_set(): void
    {
        Storage::fake('public');

        $client = $this->account('client', 'client@example.test');

        $this->assertFalse($client->usesProfilePhoto());
        $this->assertNull($client->avatarUrl());

        $this->actingAs($client)
            ->post(route('profile.photo.update'), [
                'profile_photo' => UploadedFile::fake()->image('me.jpg'),
            ])
            ->assertSessionHas('error', 'Client accounts do not use profile pictures.');

        $this->assertNull($client->refresh()->profile_photo_path);
    }

    // ------------------------------------------------------------------
    // Personal information and password
    // ------------------------------------------------------------------

    public function test_a_user_updates_their_own_name_and_email(): void
    {
        $technician = $this->account('technician', 'tech@example.test');
        $this->technicianFor($technician);

        $this->actingAs($technician)
            ->put(route('profile.information'), [
                'first_name' => 'Maria',
                'middle_name' => 'Santos',
                'last_name' => 'Reyes',
                'email' => 'maria@example.test',
            ])
            ->assertSessionHas('success', 'Profile updated.');

        $technician->refresh();

        $this->assertSame('Maria Santos Reyes', $technician->fullName());
        // `name` is what the topbar and every listing read, so it has to keep
        // up with the parts.
        $this->assertSame('Maria Santos Reyes', $technician->name);
        $this->assertSame('maria@example.test', $technician->email);

        $this->assertDatabaseHas('tbl_activity_logs', ['action' => ActivityLog::PROFILE_NAME_UPDATED]);
        $this->assertDatabaseHas('tbl_activity_logs', ['action' => ActivityLog::PROFILE_EMAIL_UPDATED]);
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

        // Approving is what moves them.
        $this->actingAs($owner)
            ->put(route('super-admin.technicians.specialty-requests.approve', $request))
            ->assertSessionHas('success', 'Specialty request approved.');

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
            ->put(route('super-admin.technicians.specialty-requests.reject', $request))
            ->assertSessionHas('success', 'Specialty request rejected.');

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
            ->put(route('super-admin.technicians.specialty-requests.reject', $request))
            ->assertSessionHas('error', 'That request has already been decided.');
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
            ->put(route('super-admin.technicians.specialty-requests.approve', $request))
            ->assertRedirect();

        $this->assertTrue($request->refresh()->isPending());

        // An Admin can, though - deciding is not a Super Admin privilege.
        $this->actingAs($admin)
            ->put(route('super-admin.technicians.specialty-requests.approve', $request))
            ->assertSessionHas('success');

        $this->assertSame(SpecialtyRequest::STATUS_APPROVED, $request->refresh()->status);
    }

    public function test_the_queue_shows_a_pending_request_to_administrators(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');
        $tech = $this->account('technician', 'tech@example.test', [
            'name' => 'Michael Santos',
            'first_name' => 'Michael',
            'last_name' => 'Santos',
        ]);
        $this->technicianFor($tech, ['Electrical']);

        $hvac = Skill::firstOrCreate(['skill_name' => 'HVAC']);

        $this->actingAs($tech)->post(route('profile.specialties.request'), ['skill_ids' => [$hvac->skill_id]]);

        $this->actingAs($owner)
            ->get(route('super-admin.technicians.index'))
            ->assertOk()
            ->assertSee('Pending Specialty Requests')
            ->assertSee('Michael Santos')
            ->assertSee('Requested Changes')
            ->assertSee('HVAC');

        // The notification links at the queue rather than at the account.
        $notification = Notification::query()->where('user_id', $owner->id)->firstOrFail();
        $this->assertStringContainsString('tab=specialty-requests', (string) $notification->url);
    }

    // ------------------------------------------------------------------
    // User Management no longer takes a picture
    // ------------------------------------------------------------------

    public function test_creating_a_user_never_asks_for_a_picture(): void
    {
        Storage::fake('public');

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
                'email' => 'second@example.test',
                'role' => 'admin',
            ])
            ->assertCreated();
    }
}
