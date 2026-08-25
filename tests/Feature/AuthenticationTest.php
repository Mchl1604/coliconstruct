<?php

namespace Tests\Feature;

use App\Mail\OtpCodeMail;
use App\Models\ActivityLog;
use App\Models\OtpVerification;
use App\Models\PendingRegistration;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\User;
use App\Services\UserAccountService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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
            // An account that already exists has a proved address; the
            // verification workflow is for self-registration alone.
            'email_verified_at' => now(),
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
            'client' => 'landing.home',
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
     * A deactivated account is refused, and is told why - see
     * SystemSettingsTest for the message itself and for the reason it gives
     * nothing away. All that matters here is that it does not get in.
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
     * Registering creates no account at all. The details wait in
     * tbl_pending_registrations with a code sent to the address, and `users`
     * is untouched until that code comes back - so an address typed by
     * mistake takes nothing and holds nothing.
     */
    public function test_registering_creates_no_account_until_the_code_comes_back(): void
    {
        Mail::fake();

        $response = $this->post(route('auth.register.store'), [
            'full_name' => 'Jose Garcia',
            'email' => 'jose@example.test',
            'contact_number' => '09175551234',
            'birthdate' => '1990-05-04',
            'password' => 'my-own-password',
            'password_confirmation' => 'my-own-password',
            'terms' => '1',
        ]);

        $response->assertRedirect(route('auth.verify'));

        // Nothing in users, and none of what goes with an account either.
        $this->assertNull(User::where('email', 'jose@example.test')->first());
        $this->assertDatabaseMissing('tbl_activity_logs', [
            'action' => ActivityLog::CLIENT_CREATED,
        ]);
        $this->assertGuest();

        $pending = PendingRegistration::where('email', 'jose@example.test')->firstOrFail();

        $this->assertSame('Jose Garcia', $pending->full_name);
        $this->assertSame('09175551234', $pending->contact_number);
        $this->assertSame('1990-05-04', $pending->birthdate->toDateString());
        // Hashed here too: the row is worth no more than the account would be.
        $this->assertNotSame('my-own-password', $pending->password);
        $this->assertTrue(Hash::check('my-own-password', $pending->password));

        Mail::assertSent(OtpCodeMail::class, fn (OtpCodeMail $mail): bool => $mail->hasTo('jose@example.test')
            && $mail->purpose === OtpVerification::PURPOSE_REGISTRATION);

        $this->assertDatabaseHas('tbl_otp_verifications', [
            'email' => 'jose@example.test',
            'purpose' => OtpVerification::PURPOSE_REGISTRATION,
        ]);
    }

    /**
     * Filling the form in again replaces the registration rather than being
     * refused as a duplicate. This is the point of the pending table: an
     * abandoned attempt must never hold an address against the person who
     * owns it.
     */
    public function test_registering_again_replaces_the_pending_registration(): void
    {
        Mail::fake();

        $this->travelTo(now()->subMinutes(5));

        $this->post(route('auth.register.store'), [
            'full_name' => 'Jose Gracia',
            'email' => 'jose@example.test',
            'contact_number' => '09175551234',
            'birthdate' => '1990-05-04',
            'password' => 'first-password',
            'password_confirmation' => 'first-password',
            'terms' => '1',
        ]);

        $this->travelBack();

        $this->post(route('auth.register.store'), [
            'full_name' => 'Jose Garcia',
            'email' => 'jose@example.test',
            'contact_number' => '09175559999',
            'birthdate' => '1990-05-04',
            'password' => 'second-password',
            'password_confirmation' => 'second-password',
            'terms' => '1',
        ])->assertRedirect(route('auth.verify'));

        $this->assertSame(1, PendingRegistration::where('email', 'jose@example.test')->count());

        $pending = PendingRegistration::where('email', 'jose@example.test')->firstOrFail();

        $this->assertSame('Jose Garcia', $pending->full_name);
        $this->assertSame('09175559999', $pending->contact_number);
        $this->assertTrue(Hash::check('second-password', $pending->password));
    }

    /**
     * A registration nobody finished is swept up, and the address it was
     * holding is free again.
     */
    public function test_a_lapsed_registration_is_swept_away(): void
    {
        Mail::fake();

        $this->post(route('auth.register.store'), [
            'full_name' => 'Jose Garcia',
            'email' => 'jose@example.test',
            'contact_number' => '09175551234',
            'birthdate' => '1990-05-04',
            'password' => 'my-own-password',
            'password_confirmation' => 'my-own-password',
            'terms' => '1',
        ]);

        $this->travel(PendingRegistration::VALID_HOURS + 1)->hours();

        $this->assertNull(PendingRegistration::liveFor('jose@example.test'));
        $this->assertSame(1, app(UserAccountService::class)->purgeLapsedRegistrations());
        $this->assertSame(0, PendingRegistration::count());
    }

    /**
     * The code is what turns a registration into an account that can sign in.
     */
    public function test_the_emailed_code_verifies_the_address_and_signs_the_client_in(): void
    {
        Mail::fake();

        $this->post(route('auth.register.store'), [
            'full_name' => 'Jose Garcia',
            'email' => 'jose@example.test',
            'contact_number' => '09175551234',
            'birthdate' => '1990-05-04',
            'password' => 'my-own-password',
            'password_confirmation' => 'my-own-password',
            'terms' => '1',
        ]);

        $code = $this->issuedCode();

        $this->post(route('auth.verify.store'), ['code' => $code])
            ->assertRedirect(route('landing.home'));

        $user = User::where('email', 'jose@example.test')->firstOrFail();

        // Everything that used to happen at submit happens here instead.
        $this->assertSame(User::ROLE_CLIENT, $user->role);
        $this->assertStringStartsWith('CLI-', (string) $user->user_code);
        // Their own password, so there is nothing to force them to replace.
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('my-own-password', $user->password));

        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue($user->canLogin());
        $this->assertAuthenticatedAs($user);

        // The pending row is spent, not left behind.
        $this->assertNull(PendingRegistration::where('email', 'jose@example.test')->first());

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::REGISTRATION_VERIFIED,
        ]);
    }

    /**
     * The page a registration lands on: the address it went to, a field for
     * the code, and a resend that is on a timer rather than a free button.
     */
    public function test_the_verification_page_shows_the_address_and_offers_a_resend(): void
    {
        Mail::fake();

        $this->post(route('auth.register.store'), [
            'full_name' => 'Jose Garcia',
            'email' => 'jose@example.test',
            'contact_number' => '09175551234',
            'birthdate' => '1990-05-04',
            'password' => 'my-own-password',
            'password_confirmation' => 'my-own-password',
            'terms' => '1',
        ]);

        $response = $this->get(route('auth.verify'));

        $response->assertOk();
        $response->assertSee('jose@example.test');
        $response->assertSee('name="code"', false);
        $response->assertSee(route('auth.verify.resend'), false);
        // A code was issued a moment ago, so the resend starts on its timer.
        $response->assertSee('Resend code in');
    }

    /**
     * Reaching the verification page without having registered gives nothing
     * away: there is no address in the session to be about.
     */
    public function test_the_verification_page_needs_a_registration_behind_it(): void
    {
        $this->get(route('auth.verify'))->assertRedirect(route('auth.login'));
    }

    /**
     * A wrong code changes nothing, and costs an attempt.
     */
    public function test_a_wrong_code_leaves_the_account_unverified(): void
    {
        Mail::fake();

        $this->post(route('auth.register.store'), [
            'full_name' => 'Jose Garcia',
            'email' => 'jose@example.test',
            'contact_number' => '09175551234',
            'birthdate' => '1990-05-04',
            'password' => 'my-own-password',
            'password_confirmation' => 'my-own-password',
            'terms' => '1',
        ]);

        $this->post(route('auth.verify.store'), ['code' => '000000'])
            ->assertSessionHas('error');

        // Still nothing in users, and the registration is still waiting.
        $this->assertNull(User::where('email', 'jose@example.test')->first());
        $this->assertNotNull(PendingRegistration::liveFor('jose@example.test'));
        $this->assertGuest();

        $this->assertDatabaseHas('tbl_otp_verifications', [
            'email' => 'jose@example.test',
            'attempts' => 1,
        ]);
    }

    /**
     * Signing in with an address that was never verified does not fail as a
     * bad password - it resumes the registration where it stopped.
     */
    public function test_an_unverified_account_is_sent_back_to_verification(): void
    {
        Mail::fake();

        $client = $this->account('client', [
            'email' => 'unverified@example.test',
            'email_verified_at' => null,
        ]);

        $this->post(route('auth.login.attempt'), [
            'email' => $client->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('auth.verify'));

        $this->assertGuest();

        Mail::assertSent(OtpCodeMail::class);
    }

    /**
     * The accounts that predate tbl_pending_registrations.
     *
     * Registration no longer leaves an unverified account behind, but every
     * database deployed before it does hold some. They must still be able to
     * finish: signing in resends the code, and the code marks the account they
     * already have rather than trying to create a second one.
     */
    public function test_an_account_left_unverified_by_the_old_flow_can_still_finish(): void
    {
        Mail::fake();

        $legacy = $this->account('client', [
            'email' => 'legacy@example.test',
            'email_verified_at' => null,
        ]);

        $this->post(route('auth.login.attempt'), [
            'email' => $legacy->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('auth.verify'));

        $this->post(route('auth.verify.store'), ['code' => $this->issuedCode()]);

        $legacy->refresh();

        $this->assertTrue($legacy->hasVerifiedEmail());
        $this->assertAuthenticatedAs($legacy);

        // No second account, and nothing parked in the pending table for it.
        $this->assertSame(1, User::where('email', 'legacy@example.test')->count());
        $this->assertSame(0, PendingRegistration::count());
    }

    /**
     * The six digits, read back from the message that carried them - the only
     * place they exist after being generated.
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
     * The role is never read from the request, so no crafted field can turn a
     * public sign-up into a staff account.
     */
    public function test_registration_cannot_be_talked_into_making_an_employee(): void
    {
        Mail::fake();

        $this->post(route('auth.register.store'), [
            'full_name' => 'Sneaky Person',
            'email' => 'sneaky@example.test',
            'contact_number' => '09175551234',
            'birthdate' => '1990-05-04',
            'password' => 'my-own-password',
            'password_confirmation' => 'my-own-password',
            'terms' => '1',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        // The crafted fields are not even carried as far as the account: the
        // pending table has no column that could hold one.
        $this->post(route('auth.verify.store'), ['code' => $this->issuedCode()]);

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
            'birthdate' => '1990-05-04',
            'password' => 'my-own-password',
            'password_confirmation' => 'my-own-password',
            'terms' => '1',
        ])->assertSessionHasErrors('email');
    }

    /**
     * Nobody under 18 may hold an account, so the birthdate is asked for and
     * checked rather than merely collected.
     */
    public function test_registration_refuses_anybody_under_eighteen(): void
    {
        $underage = CarbonImmutable::today()->subYears(17)->toDateString();

        $this->post(route('auth.register.store'), [
            'full_name' => 'Young Person',
            'email' => 'young@example.test',
            'contact_number' => '09175551234',
            'birthdate' => $underage,
            'password' => 'my-own-password',
            'password_confirmation' => 'my-own-password',
            'terms' => '1',
        ])->assertSessionHasErrors('birthdate');

        $this->assertSame(0, User::where('email', 'young@example.test')->count());

        // The form asks for it in the first place.
        $this->get(route('auth.register'))
            ->assertOk()
            ->assertSee('name="birthdate"', escape: false)
            ->assertSee('at least 18 years old', escape: false);
    }

    /**
     * Somebody who turns 18 today is old enough, and the date is kept.
     */
    public function test_registration_accepts_somebody_who_is_exactly_eighteen(): void
    {
        Mail::fake();

        $birthdate = CarbonImmutable::today()->subYears(18)->toDateString();

        $this->post(route('auth.register.store'), [
            'full_name' => 'Just Eighteen',
            'email' => 'eighteen@example.test',
            'contact_number' => '09175551234',
            'birthdate' => $birthdate,
            'password' => 'my-own-password',
            'password_confirmation' => 'my-own-password',
            'terms' => '1',
        ])->assertRedirect(route('auth.verify'));

        $this->post(route('auth.verify.store'), ['code' => $this->issuedCode()]);

        $user = User::where('email', 'eighteen@example.test')->firstOrFail();

        $this->assertSame($birthdate, $user->birthdate->toDateString());
    }

    public function test_registration_requires_matching_passwords(): void
    {
        $this->post(route('auth.register.store'), [
            'full_name' => 'Jose Garcia',
            'email' => 'jose@example.test',
            'contact_number' => '09175551234',
            'birthdate' => '1990-05-04',
            'password' => 'my-own-password',
            'password_confirmation' => 'something-else',
            'terms' => '1',
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

        $this->get(route('technician.schedule'))->assertRedirect(route('landing.home'));
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

        // Tasks shows the whole board for the projects you are on rather than
        // only your own slice, so it loses the "My".
        $leadPage = $this->get(route('technician.schedule'));
        $leadPage->assertOk();
        $leadPage->assertSee('My Schedule');
        $leadPage->assertSee('My Projects');
        $leadPage->assertSee('>Tasks<', false);
        $leadPage->assertSee('>Reports<', false);

        $this->post(route('auth.logout'));

        $this->actingAs($this->account('technician', ['email' => 'tech@example.test']));

        // A technician gets the same sidebar bar Reports.
        $techPage = $this->get(route('technician.schedule'));
        $techPage->assertOk();
        $techPage->assertSee('My Schedule');
        $techPage->assertSee('My Projects');
        $techPage->assertSee('>Tasks<', false);
        $techPage->assertDontSee('>Reports<', false);
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

    /**
     * Signing out lands on the public website, where the header offers a way
     * back in again - not on the sign-in form itself.
     */
    public function test_signing_out_ends_the_session(): void
    {
        $this->actingAs($this->account('admin'));

        $this->post(route('auth.logout'))->assertRedirect(route('landing.home'));

        $this->assertGuest();
        $this->get(route('super-admin.dashboard'))->assertRedirect(route('auth.login'));
    }

    public function test_the_page_reached_after_signing_out_offers_a_way_back_in(): void
    {
        $this->actingAs($this->account('admin'));

        $this->post(route('auth.logout'));

        $this->get(route('landing.home'))
            ->assertOk()
            // The guest header's one button, which leads to Login and from
            // there to the registration form.
            ->assertSee('Get Started')
            ->assertSee(route('auth.login'), escape: false)
            // No signed-in chrome survives the sign-out.
            ->assertDontSee('data-notification-bell', escape: false);
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
