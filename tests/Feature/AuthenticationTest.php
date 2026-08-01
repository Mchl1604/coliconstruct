<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Signing in, self-registration, the forced password change, and which role
 * may reach which portal.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The sign-in throttle is keyed per address and survives between
        // tests otherwise.
        RateLimiter::clear('login|user@example.test|127.0.0.1');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function account(string $role, array $overrides = []): User
    {
        $sequence = User::count() + 1;

        return User::create(array_merge([
            'user_code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => 'Test Person',
            'first_name' => 'Test',
            'last_name' => 'Person',
            'email' => 'user@example.test',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ], $overrides));
    }

    // ------------------------------------------------------------------
    // Signing in
    // ------------------------------------------------------------------

    public function test_the_sign_in_page_is_public(): void
    {
        $response = $this->get(route('auth.login'));

        $response->assertOk();
        $response->assertSee('Sign In');
        $response->assertSee('Register here');
    }

    public function test_correct_credentials_open_a_session(): void
    {
        $user = $this->account('admin');

        $response = $this->post(route('auth.login.attempt'), [
            'email' => 'user@example.test',
            'password' => 'correct-password',
        ]);

        $response->assertRedirect(route('super-admin.dashboard'));
        $this->assertAuthenticatedAs($user);
        // The sign-in is stamped so the account record can report it.
        $this->assertNotNull($user->refresh()->last_login_at);
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $this->account('admin');

        $response = $this->post(route('auth.login.attempt'), [
            'email' => 'user@example.test',
            'password' => 'not-the-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /**
     * Each role lands in its own portal.
     */
    public function test_every_role_is_sent_to_its_own_portal(): void
    {
        foreach ([
            'super_admin' => 'super-admin.dashboard',
            'admin' => 'super-admin.dashboard',
            'lead_technician' => 'technician.schedule',
            'technician' => 'technician.schedule',
            'client' => 'client.dashboard',
        ] as $role => $home) {
            $this->account($role, ['email' => $role.'@example.test']);

            $response = $this->post(route('auth.login.attempt'), [
                'email' => $role.'@example.test',
                'password' => 'correct-password',
            ]);

            $response->assertRedirect(route($home));

            $this->post(route('auth.logout'));
        }
    }

    // ------------------------------------------------------------------
    // Accounts that may not sign in
    // ------------------------------------------------------------------

    /**
     * A deactivated account fails exactly like a wrong password, so nothing
     * here tells an attacker which addresses exist and are merely switched off.
     */
    public function test_a_deactivated_account_cannot_sign_in(): void
    {
        $this->account('technician', ['status' => User::STATUS_DEACTIVATED]);

        $response = $this->post(route('auth.login.attempt'), [
            'email' => 'user@example.test',
            'password' => 'correct-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_an_archived_account_cannot_sign_in(): void
    {
        $this->account('technician', ['is_archived' => true]);

        $this->post(route('auth.login.attempt'), [
            'email' => 'user@example.test',
            'password' => 'correct-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * Deactivating someone who is already signed in ends their session at the
     * next request rather than waiting for it to expire.
     */
    public function test_an_open_session_is_closed_once_the_account_is_deactivated(): void
    {
        $user = $this->account('admin');

        $this->actingAs($user);
        $this->get(route('super-admin.dashboard'))->assertOk();

        $user->forceFill(['status' => User::STATUS_DEACTIVATED])->save();

        $this->get(route('super-admin.dashboard'))->assertRedirect(route('auth.login'));
        $this->assertGuest();
    }

    public function test_repeated_failures_are_throttled(): void
    {
        $this->account('admin');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('auth.login.attempt'), [
                'email' => 'user@example.test',
                'password' => 'wrong',
            ]);
        }

        $response = $this->post(route('auth.login.attempt'), [
            'email' => 'user@example.test',
            'password' => 'correct-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    // ------------------------------------------------------------------
    // Registration
    // ------------------------------------------------------------------

    /**
     * Self-registration produces a client and nothing else.
     */
    public function test_registering_creates_an_active_client_account(): void
    {
        $response = $this->post(route('auth.register.store'), [
            'full_name' => 'Jose Garcia',
            'email' => 'jose@example.test',
            'contact_number' => '09175551234',
            'password' => 'my-own-password',
            'password_confirmation' => 'my-own-password',
        ]);

        $response->assertRedirect(route('client.dashboard'));

        $user = User::where('email', 'jose@example.test')->firstOrFail();

        $this->assertSame(User::ROLE_CLIENT, $user->role);
        $this->assertStringStartsWith('CLI-', (string) $user->user_code);
        $this->assertTrue($user->canLogin());
        // Their own password, so there is nothing to force them to replace.
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('my-own-password', $user->password));
        $this->assertAuthenticatedAs($user);
    }

    /**
     * The role is never read from the request, so no crafted field can turn a
     * public sign-up into a staff account.
     */
    public function test_registration_cannot_be_talked_into_making_an_employee(): void
    {
        $this->post(route('auth.register.store'), [
            'full_name' => 'Sneaky Person',
            'email' => 'sneaky@example.test',
            'contact_number' => '09175551234',
            'password' => 'my-own-password',
            'password_confirmation' => 'my-own-password',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $user = User::where('email', 'sneaky@example.test')->firstOrFail();

        $this->assertSame(User::ROLE_CLIENT, $user->role);
        $this->assertSame(0, User::where('role', 'super_admin')->count());
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        $this->account('client', ['email' => 'taken@example.test']);

        $this->post(route('auth.register.store'), [
            'full_name' => 'Jose Garcia',
            'email' => 'taken@example.test',
            'contact_number' => '09175551234',
            'password' => 'my-own-password',
            'password_confirmation' => 'my-own-password',
        ])->assertSessionHasErrors('email');
    }

    public function test_registration_requires_matching_passwords(): void
    {
        $this->post(route('auth.register.store'), [
            'full_name' => 'Jose Garcia',
            'email' => 'jose@example.test',
            'contact_number' => '09175551234',
            'password' => 'my-own-password',
            'password_confirmation' => 'something-else',
        ])->assertSessionHasErrors('password');

        $this->assertSame(0, User::where('email', 'jose@example.test')->count());
    }

    // ------------------------------------------------------------------
    // Forced first password change
    // ------------------------------------------------------------------

    public function test_an_issued_password_must_be_replaced_before_anything_else(): void
    {
        $user = $this->account('admin', ['must_change_password' => true]);

        $this->post(route('auth.login.attempt'), [
            'email' => 'user@example.test',
            'password' => 'correct-password',
        ])->assertRedirect(route('auth.password.change'));

        // Every other page bounces back to the change screen.
        $this->get(route('super-admin.dashboard'))->assertRedirect(route('auth.password.change'));
        $this->get(route('auth.password.change'))->assertOk();

        $this->post(route('auth.password.update'), [
            'current_password' => 'correct-password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect(route('super-admin.dashboard'));

        $user->refresh();

        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('a-brand-new-password', $user->password));

        // And the portal opens normally from here on.
        $this->get(route('super-admin.dashboard'))->assertOk();
    }

    public function test_the_new_password_cannot_be_the_issued_one(): void
    {
        $this->actingAs($this->account('admin', ['must_change_password' => true]));

        $this->post(route('auth.password.update'), [
            'current_password' => 'correct-password',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
        ])->assertSessionHasErrors('password');
    }

    public function test_the_current_password_has_to_be_right(): void
    {
        $this->actingAs($this->account('admin', ['must_change_password' => true]));

        $this->post(route('auth.password.update'), [
            'current_password' => 'not-it',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasErrors('current_password');
    }

    // ------------------------------------------------------------------
    // Who may reach what
    // ------------------------------------------------------------------

    public function test_the_administrative_portal_is_closed_to_guests(): void
    {
        foreach ([
            'super-admin.dashboard',
            'super-admin.projects',
            'super-admin.configuration.index',
            'technician.schedule',
            'client.dashboard',
        ] as $name) {
            $this->get(route($name))->assertRedirect(route('auth.login'));
        }
    }

    public function test_a_technician_cannot_reach_the_administrative_portal(): void
    {
        $this->actingAs($this->account('technician'));

        $this->get(route('super-admin.configuration.index'))
            ->assertRedirect(route('technician.schedule'));

        $this->get(route('super-admin.projects'))
            ->assertRedirect(route('technician.schedule'));
    }

    public function test_a_client_cannot_reach_the_technician_portal(): void
    {
        $this->actingAs($this->account('client'));

        $this->get(route('technician.schedule'))->assertRedirect(route('client.dashboard'));
    }

    /**
     * Admin shares the super admin's portal entirely.
     */
    public function test_an_admin_reaches_every_administrative_page(): void
    {
        $this->actingAs($this->account('admin'));

        $this->get(route('super-admin.dashboard'))->assertOk();
        $this->get(route('super-admin.configuration.index'))->assertOk();
        $this->get(route('super-admin.projects'))->assertOk();
    }

    /**
     * My Reports is the one page a lead has and a technician does not.
     */
    public function test_only_a_lead_technician_reaches_my_reports(): void
    {
        $this->actingAs($this->account('lead_technician', ['email' => 'lead@example.test']));
        $this->get(route('technician.reports'))->assertOk();

        $this->post(route('auth.logout'));

        $this->actingAs($this->account('technician', ['email' => 'tech@example.test']));
        $this->get(route('technician.reports'))->assertRedirect(route('technician.schedule'));
    }

    // ------------------------------------------------------------------
    // Sidebars
    // ------------------------------------------------------------------

    public function test_each_portal_shows_its_own_sidebar(): void
    {
        $lead = $this->account('lead_technician', ['email' => 'lead@example.test']);
        $this->actingAs($lead);

        $leadPage = $this->get(route('technician.schedule'));
        $leadPage->assertOk();
        $leadPage->assertSee('My Schedule');
        $leadPage->assertSee('My Projects');
        $leadPage->assertSee('My Tasks');
        $leadPage->assertSee('My Reports');

        $this->post(route('auth.logout'));

        $this->actingAs($this->account('technician', ['email' => 'tech@example.test']));

        $techPage = $this->get(route('technician.schedule'));
        $techPage->assertOk();
        $techPage->assertSee('My Schedule');
        $techPage->assertSee('My Projects');
        $techPage->assertSee('My Tasks');
        // A technician has no reports page, so the link is not drawn.
        $techPage->assertDontSee('My Reports');
    }

    public function test_the_admin_sidebar_matches_the_super_admin_one(): void
    {
        $this->actingAs($this->account('admin'));

        $response = $this->get(route('super-admin.dashboard'));

        $response->assertOk();

        foreach (['Dashboard', 'Projects', 'Schedules', 'Task', 'Technicians', 'Reports', 'Configuration'] as $item) {
            $response->assertSee($item);
        }
    }

    // ------------------------------------------------------------------
    // Portal contents
    // ------------------------------------------------------------------

    /**
     * A technician's portal shows their own work and no one else's.
     */
    public function test_the_technician_portal_only_shows_its_own_work(): void
    {
        $mine = $this->account('technician', ['email' => 'mine@example.test']);
        $theirs = $this->account('technician', ['email' => 'theirs@example.test']);

        $myTechnician = Technician::create(['account_id' => $mine->id, 'role' => 'technician']);
        $theirTechnician = Technician::create(['account_id' => $theirs->id, 'role' => 'technician']);

        $myProject = $this->project('My Project');
        $theirProject = $this->project('Their Project');

        ProjectTechnician::create([
            'project_id' => $myProject->project_id,
            'technician_id' => $myTechnician->technician_id,
        ]);
        ProjectTechnician::create([
            'project_id' => $theirProject->project_id,
            'technician_id' => $theirTechnician->technician_id,
        ]);

        Schedule::create([
            'project_id' => $myProject->project_id,
            'start_datetime' => CarbonImmutable::today()->toDateString().' 00:00:00',
            'end_datetime' => CarbonImmutable::today()->addDays(3)->toDateString().' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        Task::create([
            'project_id' => $myProject->project_id,
            'technician_id' => $myTechnician->technician_id,
            'task_title' => 'My Task',
            'task_description' => 'Mine',
            'start_date' => CarbonImmutable::today()->toDateString(),
            'due_date' => CarbonImmutable::today()->addDay()->toDateString(),
            'status' => 'ongoing',
        ]);

        Task::create([
            'project_id' => $theirProject->project_id,
            'technician_id' => $theirTechnician->technician_id,
            'task_title' => 'Their Task',
            'task_description' => 'Theirs',
            'start_date' => CarbonImmutable::today()->toDateString(),
            'due_date' => CarbonImmutable::today()->addDay()->toDateString(),
            'status' => 'ongoing',
        ]);

        $this->actingAs($mine);

        $projects = $this->get(route('technician.projects'));
        $projects->assertOk();
        $projects->assertSee('My Project');
        $projects->assertDontSee('Their Project');

        $schedule = $this->get(route('technician.schedule'));
        $schedule->assertOk();
        $schedule->assertSee('My Project');

        $tasks = $this->get(route('technician.tasks'));
        $tasks->assertOk();
        $tasks->assertSee('My Task');
        $tasks->assertDontSee('Their Task');
    }

    public function test_a_lead_only_sees_reports_filed_under_their_own_name(): void
    {
        $lead = $this->account('lead_technician', ['email' => 'lead@example.test']);
        $other = $this->account('technician', ['email' => 'other@example.test']);

        $leadTechnician = Technician::create(['account_id' => $lead->id, 'role' => 'lead_technician']);
        $otherTechnician = Technician::create(['account_id' => $other->id, 'role' => 'technician']);

        $project = $this->project('Some Project');

        TechnicianReport::create([
            'project_id' => $project->project_id,
            'technician_id' => $leadTechnician->technician_id,
            'report_type' => 'progress',
            'report_title' => 'My Own Report',
            'report_description' => 'Mine',
            'report_date' => CarbonImmutable::today()->toDateString(),
        ]);

        TechnicianReport::create([
            'project_id' => $project->project_id,
            'technician_id' => $otherTechnician->technician_id,
            'report_type' => 'progress',
            'report_title' => 'Someone Elses Report',
            'report_description' => 'Theirs',
            'report_date' => CarbonImmutable::today()->toDateString(),
        ]);

        $this->actingAs($lead);

        $response = $this->get(route('technician.reports'));

        $response->assertOk();
        $response->assertSee('My Own Report');
        $response->assertDontSee('Someone Elses Report');
    }

    // ------------------------------------------------------------------
    // Signing out
    // ------------------------------------------------------------------

    public function test_signing_out_ends_the_session(): void
    {
        $this->actingAs($this->account('admin'));

        $this->post(route('auth.logout'))->assertRedirect(route('auth.login'));

        $this->assertGuest();
        $this->get(route('super-admin.dashboard'))->assertRedirect(route('auth.login'));
    }

    private function project(string $name): Project
    {
        return Project::create([
            'name' => $name,
            'reference_no' => 'REF-'.strtoupper(substr(md5($name.microtime()), 0, 8)),
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 100000,
        ]);
    }
}
