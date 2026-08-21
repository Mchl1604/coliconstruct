<?php

namespace Tests\Feature;

use App\Mail\AccountStatusMail;
use App\Mail\ClientProjectInvitationMail;
use App\Mail\OtpCodeMail;
use App\Mail\ProjectUpdateMail;
use App\Mail\SpecialtyDecisionMail;
use App\Mail\TemporaryCredentialsMail;
use App\Models\Client;
use App\Models\OtpVerification;
use App\Models\Project;
use App\Models\Skill;
use App\Models\SpecialtyRequest;
use App\Models\Technician;
use App\Models\User;
use App\Services\EmailService;
use App\Services\OtpService;
use App\Services\ProfileService;
use App\Services\ProjectEmails;
use App\Services\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * The system-wide email layer: the one-time code module, the branded shell
 * every message is dressed in, and the events that actually put something in
 * somebody's inbox.
 *
 * The rules worth holding onto are the ones a configuration change must never
 * quietly break: a code is six digits, lives ten minutes, is stored hashed,
 * survives five guesses and works once; and nobody is emailed about work that
 * did not happen.
 */
class EmailSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        // The deliverability guard reads this; without it the flows that check
        // it would refuse to send at all.
        config(['mail.default' => 'smtp']);
    }

    private function otp(): OtpService
    {
        return app(OtpService::class);
    }

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
        ], $attributes));
    }

    /**
     * A project with one client contact at the given address.
     */
    private function projectFor(string $email, array $overrides = []): Project
    {
        $project = Project::create(array_merge([
            'reference_no' => 'PRJ-'.random_int(10000, 99999),
            'name' => 'Greenfield Offices',
            'status' => 'pending',
            'address' => '123 Sample Street',
            'description' => 'Aircon installation',
            'quotation' => '1250.00',
        ], $overrides));

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Residential',
            'firstname' => 'Maria',
            'surname' => 'Santos',
            'fullname' => 'Maria Santos',
            'email_address' => $email,
            'contact_number' => '09171234567',
        ]);

        return $project->refresh();
    }

    private function issuedCode(): string
    {
        $code = null;

        Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        return (string) $code;
    }

    // ------------------------------------------------------------------
    // The one-time code module
    // ------------------------------------------------------------------

    public function test_a_code_is_six_digits_and_is_never_stored_in_the_clear(): void
    {
        $this->otp()->issue('someone@example.test', OtpVerification::PURPOSE_REGISTRATION);

        $code = $this->issuedCode();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $record = OtpVerification::firstOrFail();

        // What is stored is a hash: the digits themselves are nowhere in the
        // row, and the hash still matches them.
        $this->assertNotSame($code, $record->otp_code);
        $this->assertTrue(Hash::check($code, $record->otp_code));
    }

    public function test_a_code_expires_after_ten_minutes(): void
    {
        $this->otp()->issue('someone@example.test', OtpVerification::PURPOSE_REGISTRATION);

        $code = $this->issuedCode();
        $record = OtpVerification::firstOrFail();

        $this->assertEqualsWithDelta(
            OtpService::VALID_MINUTES * 60,
            $record->secondsRemaining(),
            5
        );

        $this->travel(OtpService::VALID_MINUTES + 1)->minutes();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('That code has expired.');

        $this->otp()->verify('someone@example.test', OtpVerification::PURPOSE_REGISTRATION, $code);
    }

    public function test_a_code_works_exactly_once(): void
    {
        $this->otp()->issue('someone@example.test', OtpVerification::PURPOSE_REGISTRATION);
        $code = $this->issuedCode();

        $verified = $this->otp()->verify('someone@example.test', OtpVerification::PURPOSE_REGISTRATION, $code);
        $this->assertTrue($verified->isVerified());

        // The same digits a second time find a spent row, not a live one.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No verification code is waiting');

        $this->otp()->verify('someone@example.test', OtpVerification::PURPOSE_REGISTRATION, $code);
    }

    public function test_five_wrong_guesses_burn_the_code(): void
    {
        $this->otp()->issue('someone@example.test', OtpVerification::PURPOSE_REGISTRATION);
        $code = $this->issuedCode();

        for ($attempt = 0; $attempt < OtpService::MAX_ATTEMPTS; $attempt++) {
            try {
                $this->otp()->verify('someone@example.test', OtpVerification::PURPOSE_REGISTRATION, '000000');
            } catch (RuntimeException) {
                // Expected: each wrong guess costs an attempt.
            }
        }

        $this->assertSame(OtpService::MAX_ATTEMPTS, OtpVerification::firstOrFail()->attempts);

        // Even the right digits are refused once the attempts are gone.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Too many incorrect attempts.');

        $this->otp()->verify('someone@example.test', OtpVerification::PURPOSE_REGISTRATION, $code);
    }

    public function test_another_code_cannot_be_asked_for_within_sixty_seconds(): void
    {
        $this->otp()->issue('someone@example.test', OtpVerification::PURPOSE_REGISTRATION);

        try {
            $this->otp()->issue('someone@example.test', OtpVerification::PURPOSE_REGISTRATION);
            $this->fail('A second code was issued inside the cooldown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Wait 60 seconds', $exception->getMessage());
        }

        $this->travel(OtpService::RESEND_COOLDOWN_SECONDS + 1)->seconds();

        $this->otp()->issue('someone@example.test', OtpVerification::PURPOSE_REGISTRATION);

        Mail::assertQueued(OtpCodeMail::class, 2);
    }

    public function test_a_new_code_invalidates_the_previous_one(): void
    {
        $this->otp()->issue('someone@example.test', OtpVerification::PURPOSE_REGISTRATION);
        $first = $this->issuedCode();

        $this->travel(OtpService::RESEND_COOLDOWN_SECONDS + 1)->seconds();

        $this->otp()->issue('someone@example.test', OtpVerification::PURPOSE_REGISTRATION);

        // Exactly one live code exists at a time.
        $this->assertDatabaseCount('tbl_otp_verifications', 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('That code is incorrect.');

        $this->otp()->verify('someone@example.test', OtpVerification::PURPOSE_REGISTRATION, $first);
    }

    /**
     * A code for one workflow is not a code for another, so proving you own an
     * address for a password reset cannot be replayed against a registration.
     */
    public function test_codes_do_not_cross_between_purposes(): void
    {
        $this->otp()->issue('someone@example.test', OtpVerification::PURPOSE_FORGOT_PASSWORD);
        $code = $this->issuedCode();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No verification code is waiting');

        $this->otp()->verify('someone@example.test', OtpVerification::PURPOSE_EMAIL_CHANGE, $code);
    }

    public function test_long_expired_codes_are_swept_away(): void
    {
        $this->otp()->issue('someone@example.test', OtpVerification::PURPOSE_REGISTRATION);

        $this->assertDatabaseCount('tbl_otp_verifications', 1);

        $this->travel(2)->days();

        $this->assertSame(1, $this->otp()->purgeExpired());
        $this->assertDatabaseCount('tbl_otp_verifications', 0);
    }

    // ------------------------------------------------------------------
    // The shared template
    // ------------------------------------------------------------------

    /**
     * Every message carries the same header, logo and footer, whatever it is
     * about - that is the whole point of the shared layout.
     */
    public function test_every_email_carries_the_company_branding(): void
    {
        config([
            'company.name' => 'ColiConstruct',
            'company.phone' => '(02) 8123 4567',
            'company.email' => 'support@coliconstruct.test',
            'company.address' => '123 Sample Street, Sample City',
        ]);

        $account = $this->account('client', 'client@example.test');

        $rendered = (new TemporaryCredentialsMail($account, 'Temp1234pass'))->render();

        $this->assertStringContainsString('ColiConstruct', $rendered);
        $this->assertStringContainsString('123 Sample Street, Sample City', $rendered);
        $this->assertStringContainsString('(02) 8123 4567', $rendered);
        $this->assertStringContainsString('support@coliconstruct.test', $rendered);
        // The logo is absolute: a mail client has no page to resolve a path
        // against.
        $this->assertStringContainsString(config('app.url').'/img/', $rendered);
        // And the action button is a real link to the sign-in page.
        $this->assertStringContainsString(route('auth.login'), $rendered);
    }

    /**
     * The action button appears only when there is somewhere useful to send
     * the reader. Telling somebody their access has just been withdrawn and
     * then offering them a Sign in button would be nonsense.
     */
    public function test_a_deactivation_email_offers_no_sign_in_button(): void
    {
        $account = $this->account('client', 'client@example.test');

        $deactivated = (new AccountStatusMail($account, AccountStatusMail::DEACTIVATED))->render();
        $reactivated = (new AccountStatusMail($account, AccountStatusMail::ACTIVATED))->render();

        $this->assertStringNotContainsString(route('auth.login'), $deactivated);
        $this->assertStringContainsString('temporarily deactivated', $deactivated);

        $this->assertStringContainsString(route('auth.login'), $reactivated);
        $this->assertStringContainsString('reactivated', $reactivated);
    }

    public function test_a_code_email_shows_the_digits_and_the_expiry(): void
    {
        $rendered = (new OtpCodeMail('123456', OtpVerification::PURPOSE_REGISTRATION, 10, 'Juan'))->render();

        $this->assertStringContainsString('123456', $rendered);
        $this->assertStringContainsString('10 minutes', $rendered);
        $this->assertStringContainsString('Verify your email address', $rendered);
    }

    // ------------------------------------------------------------------
    // Account lifecycle
    // ------------------------------------------------------------------

    public function test_a_new_employee_account_is_emailed_its_credentials(): void
    {
        $this->actingAsSuperAdmin();

        $skill = Skill::create(['skill_name' => 'Aircon Installation']);

        $this->post(route('super-admin.configuration.users.employees.store'), [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'contact_number' => '09171234567',
            'birthdate' => '1990-05-04',
            'email' => 'ana@example.test',
            'role' => 'technician',
            'skill_ids' => [$skill->skill_id],
        ])->assertCreated();

        Mail::assertQueued(TemporaryCredentialsMail::class, fn (TemporaryCredentialsMail $mail): bool => $mail->hasTo('ana@example.test')
            && $mail->isReset === false
            && $mail->temporaryPassword !== '');
    }

    public function test_an_administrator_reset_emails_the_new_password(): void
    {
        $this->actingAsSuperAdmin();

        $technician = $this->account('technician', 'tech@example.test');

        $this->put(route('super-admin.configuration.users.password.reset', $technician))
            ->assertOk();

        Mail::assertQueued(TemporaryCredentialsMail::class, fn (TemporaryCredentialsMail $mail): bool => $mail->hasTo('tech@example.test') && $mail->isReset === true);

        // And the account is held on the change-password screen at next sign-in.
        $this->assertTrue((bool) $technician->refresh()->must_change_password);
    }

    public function test_deactivating_and_reactivating_an_account_tells_its_holder(): void
    {
        $this->actingAsSuperAdmin();

        $technician = $this->account('technician', 'tech@example.test');
        $accounts = app(UserAccountService::class);

        $accounts->setStatus($technician, false);

        Mail::assertQueued(AccountStatusMail::class, fn (AccountStatusMail $mail): bool => $mail->hasTo('tech@example.test')
            && $mail->change === AccountStatusMail::DEACTIVATED);

        $accounts->setStatus($technician->refresh(), true);

        Mail::assertQueued(AccountStatusMail::class, fn (AccountStatusMail $mail): bool => $mail->hasTo('tech@example.test')
            && $mail->change === AccountStatusMail::ACTIVATED);
    }

    public function test_a_specialty_decision_reaches_the_technician(): void
    {
        $reviewer = $this->actingAsSuperAdmin();

        $account = $this->account('technician', 'tech@example.test');
        $technician = Technician::create(['account_id' => $account->id, 'role' => 'technician']);
        $skill = Skill::create(['skill_name' => 'Ducting']);

        $request = SpecialtyRequest::create([
            'technician_id' => $technician->technician_id,
            'status' => SpecialtyRequest::STATUS_PENDING,
            'requested_skill_ids' => [$skill->skill_id],
            'current_skill_ids' => [],
            'requested_by' => $account->id,
        ]);

        app(ProfileService::class)->approveSpecialtyRequest($request, $reviewer);

        Mail::assertQueued(SpecialtyDecisionMail::class, fn (SpecialtyDecisionMail $mail): bool => $mail->hasTo('tech@example.test') && $mail->approved === true);
    }

    // ------------------------------------------------------------------
    // Projects
    // ------------------------------------------------------------------

    /**
     * Somebody with no account is told to register with the address the
     * project was booked under - that match is the whole reason for the email.
     */
    public function test_a_new_project_invites_its_client_to_register(): void
    {
        $project = $this->projectFor('maria@example.test');

        app(ProjectEmails::class)->projectCreated($project);

        Mail::assertQueued(ClientProjectInvitationMail::class, function (ClientProjectInvitationMail $mail) use ($project): bool {
            $rendered = $mail->render();

            return $mail->hasTo('maria@example.test')
                && $mail->hasAccount === false
                && str_contains($rendered, (string) $project->reference_no)
                && str_contains($rendered, 'Maria Santos')
                && str_contains($rendered, route('auth.register'));
        });
    }

    public function test_a_client_who_already_has_an_account_is_told_to_sign_in(): void
    {
        $this->account('client', 'maria@example.test');

        app(ProjectEmails::class)->projectCreated($this->projectFor('maria@example.test'));

        Mail::assertQueued(ClientProjectInvitationMail::class, fn (ClientProjectInvitationMail $mail): bool => $mail->hasAccount === true
            && str_contains($mail->render(), route('auth.login')));
    }

    public function test_project_status_changes_reach_the_client(): void
    {
        $project = $this->projectFor('maria@example.test');
        $emails = app(ProjectEmails::class);

        $emails->projectCompleted($project);
        $emails->projectCancelled($project);
        $emails->projectPutOnHold($project);
        $emails->projectResumed($project);

        foreach ([
            ProjectUpdateMail::COMPLETED,
            ProjectUpdateMail::CANCELLED,
            ProjectUpdateMail::ON_HOLD,
            ProjectUpdateMail::RESUMED,
        ] as $event) {
            Mail::assertQueued(ProjectUpdateMail::class, fn (ProjectUpdateMail $mail): bool => $mail->hasTo('maria@example.test') && $mail->event === $event);
        }
    }

    public function test_an_uploaded_document_is_announced_to_the_client(): void
    {
        $project = $this->projectFor('maria@example.test');

        app(ProjectEmails::class)->documentUploaded($project, 'quotation');

        Mail::assertQueued(ProjectUpdateMail::class, fn (ProjectUpdateMail $mail): bool => $mail->event === ProjectUpdateMail::QUOTATION_UPLOADED);

        // An internal document type has no client-facing wording, so nothing
        // vague is sent instead.
        Mail::assertQueued(ProjectUpdateMail::class, 1);

        app(ProjectEmails::class)->documentUploaded($project, 'internal-note');

        Mail::assertQueued(ProjectUpdateMail::class, 1);
    }

    /**
     * A contact address that is not an address at all costs the message, not
     * the request that triggered it.
     */
    public function test_a_malformed_client_address_is_skipped_rather_than_thrown(): void
    {
        $project = $this->projectFor('not-an-email-address');

        app(ProjectEmails::class)->projectCompleted($project);

        Mail::assertNothingQueued();
    }

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    /**
     * Until a real mailer is configured the interface must not claim anybody
     * has been emailed - the log driver writes a file, it does not deliver.
     */
    public function test_the_log_driver_does_not_count_as_deliverable(): void
    {
        config(['mail.default' => 'log']);
        $this->assertFalse(app(EmailService::class)->isDeliverable());

        config(['mail.default' => 'smtp']);
        $this->assertTrue(app(EmailService::class)->isDeliverable());
    }
}
