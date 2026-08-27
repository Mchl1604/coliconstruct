<?php

namespace Tests\Feature;

use App\Mail\OtpCodeMail;
use App\Mail\ProjectUpdateMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\CompletionConfirmability;
use App\Services\ProfileService;
use App\Services\ProjectEmails;
use App\Services\ProjectRegisteredUser;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Two facts the confirmation workflow used to get wrong, and one it got right
 * and must keep getting right.
 *
 * The first: a project may be finished for a client who has never registered,
 * and that must not stop it being completed - but it must not be quietly
 * pretended away either. Nobody can press Confirm, the project waits out its
 * window looking exactly like one whose client is deliberating, and it closes
 * itself. So the state is named rather than inferred, it is shown, and there
 * is now a way to end the wait honestly when the client confirms by telephone.
 *
 * The second: the address a project is booked to and the address its client
 * signs in with are different things, both legitimate, and neither may
 * overwrite the other on its own. What was missing was only that anybody could
 * see when they had come apart - and that the person who has to press Confirm
 * was reachable at all.
 */
class ProjectClientConfirmationReachTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_EMAIL = 'office@company.test';

    private const ACCOUNT_EMAIL = 'maria@personal.test';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['mail.default' => 'smtp']);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function project(string $email = self::PROJECT_EMAIL): Project
    {
        $project = Project::create([
            'name' => 'Reachability Project',
            'reference_no' => 'REF-'.strtoupper(substr(md5($email.microtime()), 0, 8)),
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 1000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Residential',
            'firstname' => 'Owner',
            'surname' => 'Person',
            'fullname' => 'Owner Person',
            'email_address' => $email,
            'contact_number' => '09123456789',
        ]);

        // The completion rules refuse a project with nothing recorded on it,
        // and these tests are not about that refusal.
        Task::create([
            'project_id' => $project->project_id,
            'task_title' => 'Work that was carried out',
            'task_description' => 'Description',
            'status' => 'completed',
        ]);

        return $project->refresh();
    }

    private function clientAccount(string $email = self::ACCOUNT_EMAIL): User
    {
        $user = User::factory()->create(['name' => 'Maria Santos', 'email' => $email]);

        $user->forceFill([
            'role' => User::ROLE_CLIENT,
            'status' => User::STATUS_ACTIVE,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
        ] + $this->acceptedTerms())->save();

        return $user;
    }

    private function link(Project $project, User $account): void
    {
        Client::query()
            ->where('project_id', $project->project_id)
            ->update(['user_id' => $account->id, 'user_unlinked_at' => null]);

        $project->refresh()->unsetRelation('clients');
    }

    /**
     * A booked range on the project. The listings derive status from the dates
     * (see ProjectStatusRules), so a project with none of these is Unscheduled
     * and no completion dialog is drawn for it at all.
     */
    private function schedule(Project $project, int $startOffset, int $endOffset): Schedule
    {
        return Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => CarbonImmutable::today()->addDays($startOffset)->startOfDay(),
            'end_datetime' => CarbonImmutable::today()->addDays($endOffset)->endOfDay(),
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'status' => 'scheduled',
        ]);
    }

    private function requestCompletion(Project $project): Project
    {
        $this->post(route('super-admin.projects.complete', $project->project_id), [
            'completion_date' => BusinessTime::today()->toDateString(),
            'completion_summary' => 'Everything on site is finished.',
        ])->assertRedirect();

        return $project->refresh();
    }

    /**
     * @return array<string, string>
     */
    private function confirmationPayload(array $overrides = []): array
    {
        return $overrides + [
            'client_confirmation_channel' => 'call',
            'client_confirmation_date' => BusinessTime::today()->toDateString(),
            'client_confirmation_note' => 'Client confirmed completion by phone to Ana Mendoza.',
        ];
    }

    private function confirmability(): CompletionConfirmability
    {
        return app(CompletionConfirmability::class);
    }

    /**
     * A client changing their own address, through the real flow: the address
     * is parked, a code is emailed to it, and confirming the code is what
     * makes it the account's own. Driven through the service rather than by
     * writing the column, because what is under test is what that flow does to
     * the projects on the way past.
     */
    private function changeAccountEmail(User $account, string $newEmail): void
    {
        $profile = app(ProfileService::class);

        $profile->requestEmailChange($account, $newEmail);

        $code = null;

        // Sent inline rather than queued: a code the mailer would not take
        // must not be announced as sent - see EmailService::sendNow().
        Mail::assertSent(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        $this->assertNotNull($code, 'No verification code was sent.');

        $profile->confirmEmailChange($account->refresh(), $code);

    }

    // ==================================================================
    // Completion is never refused for want of a registered client
    // ==================================================================

    public function test_a_project_with_no_registered_client_still_reaches_awaiting_confirmation(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->requestCompletion($this->project());

        $this->assertSame(Project::STATUS_AWAITING_CLIENT_CONFIRMATION, $project->status);
        // Emphatically not closed on the spot: the client may yet register and
        // confirm, and the window is what gives them the chance.
        $this->assertNotSame('completed', $project->status);
        $this->assertNull($project->completion_method);
        $this->assertNotNull($project->confirmationDeadline());
    }

    public function test_the_completion_rules_do_not_object_to_a_missing_registered_client(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();

        $blockers = app(ProjectPolicy::class)->blockersFor($project, auth()->user());

        $this->assertSame([], $blockers);
    }

    // ==================================================================
    // The three states, and the fact that they move
    // ==================================================================

    public function test_a_project_with_an_assigned_account_is_linked(): void
    {
        $project = $this->project();
        $this->link($project, $this->clientAccount());

        $this->assertSame(CompletionConfirmability::LINKED, $this->confirmability()->state($project));
        $this->assertFalse($this->confirmability()->isUnreachable($project));
    }

    /**
     * No assignment, but an account exists under the address the job was
     * booked with - which is exactly what ClientProjects claims a project by,
     * so that person can already confirm it.
     */
    public function test_a_project_whose_contact_address_has_an_account_is_claimable(): void
    {
        $project = $this->project();
        $this->clientAccount(self::PROJECT_EMAIL);

        $this->assertSame(CompletionConfirmability::CLAIMABLE, $this->confirmability()->state($project));
    }

    public function test_a_project_nobody_holds_is_unreachable(): void
    {
        $project = $this->project();

        $this->assertSame(CompletionConfirmability::UNREACHABLE, $this->confirmability()->state($project));
        $this->assertTrue($this->confirmability()->isUnreachable($project));
        $this->assertNotNull($this->confirmability()->hint($project));
    }

    /**
     * The whole reason this is derived on every read rather than decided once:
     * a client registering mid-window turns an unanswerable project into an
     * answerable one with nobody touching the project at all.
     */
    public function test_a_client_registering_later_makes_an_unreachable_project_claimable(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->requestCompletion($this->project());

        $this->assertSame(CompletionConfirmability::UNREACHABLE, $this->confirmability()->state($project));

        $this->clientAccount(self::PROJECT_EMAIL);

        $this->assertSame(
            CompletionConfirmability::CLAIMABLE,
            $this->confirmability()->state($project->refresh()->unsetRelation('clients'))
        );
    }

    /**
     * An administrator who took the account off decided that it does NOT own
     * this project. The address must not overrule them here any more than it
     * does behind My Projects.
     */
    public function test_a_deliberately_unlinked_contact_is_not_claimable_by_address(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $account = $this->clientAccount(self::PROJECT_EMAIL);
        $this->link($project, $account);

        app(ProjectRegisteredUser::class)->remove($project);

        $this->assertSame(
            CompletionConfirmability::UNREACHABLE,
            $this->confirmability()->state($project->refresh()->unsetRelation('clients'))
        );
    }

    // ==================================================================
    // Recording a confirmation the client gave elsewhere
    // ==================================================================

    public function test_an_administrator_can_record_a_confirmation_given_off_the_website(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $project = $this->requestCompletion($this->project());
        $confirmedOn = BusinessTime::today()->toDateString();

        $this->post(
            route('super-admin.projects.client-confirmation', $project->project_id),
            $this->confirmationPayload(['client_confirmation_date' => $confirmedOn])
        )->assertRedirect();

        $project->refresh();

        $this->assertSame('completed', $project->status);
        $this->assertSame(Project::METHOD_ADMIN_CONFIRMED, $project->completion_method);
        $this->assertSame('call', $project->client_confirmation_channel);
        $this->assertSame(
            'Client confirmed completion by phone to Ana Mendoza.',
            $project->client_confirmation_note
        );
        $this->assertSame($admin->id, $project->client_confirmation_recorded_by);
        $this->assertNotNull($project->client_confirmation_recorded_at);
        $this->assertSame($confirmedOn, $project->client_confirmed_at->toDateString());

        // Who confirmed and who wrote it down are two different people, and the
        // client's own column is not filled with the administrator.
        $this->assertNull($project->client_confirmed_by);

        $this->assertSame(
            'Confirmed by an administrator on behalf of the client',
            $project->completionMethodLabel()
        );
        $this->assertTrue($project->wasConfirmedByAdministrator());
    }

    public function test_recording_a_confirmation_does_not_wait_out_the_automatic_window(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->requestCompletion($this->project());

        $this->post(
            route('super-admin.projects.client-confirmation', $project->project_id),
            $this->confirmationPayload()
        )->assertRedirect();

        // The sweep must find nothing left to do: the project is closed, so it
        // can no longer be auto-completed or reminded about.
        $this->artisan('projects:process-completion-confirmations')->assertSuccessful();

        $project->refresh();

        $this->assertSame(Project::METHOD_ADMIN_CONFIRMED, $project->completion_method);
        $this->assertNull($project->completion_reminder_sent_at);
    }

    public function test_recording_a_confirmation_does_not_ask_the_client_to_confirm_again(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->requestCompletion($this->project());

        Mail::fake();

        $this->post(
            route('super-admin.projects.client-confirmation', $project->project_id),
            $this->confirmationPayload()
        )->assertRedirect();

        Mail::assertNotQueued(fn (ProjectUpdateMail $mail): bool => $mail->event === ProjectUpdateMail::AWAITING_CONFIRMATION);
        Mail::assertNotQueued(fn (ProjectUpdateMail $mail): bool => $mail->event === ProjectUpdateMail::CONFIRMATION_REMINDER);
        Mail::assertQueued(fn (ProjectUpdateMail $mail): bool => $mail->event === ProjectUpdateMail::CONFIRMED);
    }

    public function test_recording_a_confirmation_is_written_to_the_activity_trail(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $project = $this->requestCompletion($this->project());

        $this->post(
            route('super-admin.projects.client-confirmation', $project->project_id),
            $this->confirmationPayload()
        )->assertRedirect();

        $entry = ActivityLog::query()
            ->where('action', ActivityLog::PROJECT_COMPLETION_RECORDED_BY_ADMIN)
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertStringContainsString($project->reference_no, $entry->description);
        $this->assertStringContainsString('call', $entry->description);
        $this->assertStringContainsString('Ana Mendoza', $entry->description);
    }

    /**
     * A Registered User is turned away before the controller is reached at
     * all - the portal admits two roles and they are not one of them - so what
     * is asserted here is the guarantee that matters: the project is untouched.
     * The controller asks the same question again for the same reason every
     * other administrators' endpoint does; see guardAdministratorAction().
     */
    public function test_only_administrators_may_record_a_confirmation(): void
    {
        $project = $this->requestCompletionAsAdmin();

        $this->actingAs($this->clientAccount());

        $this->post(
            route('super-admin.projects.client-confirmation', $project->project_id),
            $this->confirmationPayload()
        )->assertRedirect();

        $project->refresh();

        $this->assertSame(Project::STATUS_AWAITING_CLIENT_CONFIRMATION, $project->status);
        $this->assertNull($project->completion_method);
        $this->assertNull($project->client_confirmation_recorded_by);
    }

    public function test_a_confirmation_cannot_be_recorded_on_a_project_that_is_not_waiting(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();

        $this->post(
            route('super-admin.projects.client-confirmation', $project->project_id),
            $this->confirmationPayload()
        )->assertRedirect();

        $this->assertSame('ongoing', $project->refresh()->status);
    }

    public function test_the_reason_is_required(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->requestCompletion($this->project());

        $this->post(
            route('super-admin.projects.client-confirmation', $project->project_id),
            $this->confirmationPayload(['client_confirmation_note' => ''])
        )->assertSessionHasErrors('client_confirmation_note');

        $this->assertSame(
            Project::STATUS_AWAITING_CLIENT_CONFIRMATION,
            $project->refresh()->status
        );
    }

    public function test_a_client_cannot_have_confirmed_before_being_asked(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->requestCompletion($this->project());

        $this->post(
            route('super-admin.projects.client-confirmation', $project->project_id),
            $this->confirmationPayload([
                'client_confirmation_date' => CarbonImmutable::parse($project->completion_requested_at)
                    ->subDay()
                    ->toDateString(),
            ])
        )->assertSessionHasErrors('client_confirmation_date');
    }

    public function test_a_confirmation_date_in_the_future_is_refused(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->requestCompletion($this->project());

        $this->post(
            route('super-admin.projects.client-confirmation', $project->project_id),
            $this->confirmationPayload([
                'client_confirmation_date' => BusinessTime::today()->addDay()->toDateString(),
            ])
        )->assertSessionHasErrors('client_confirmation_date');
    }

    // ==================================================================
    // What the pages actually say
    // ==================================================================

    /**
     * The whole point of naming the state: a project waiting on a reply that
     * cannot arrive must not read like one whose client is deliberating.
     */
    public function test_the_project_page_says_when_nobody_can_confirm(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->requestCompletion($this->project());

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('No registered client can confirm this online.', false)
            ->assertSee('Record Client Confirmation', false);
    }

    public function test_the_project_page_says_nothing_of_the_sort_when_somebody_can_confirm(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->link($project, $this->clientAccount());
        $project = $this->requestCompletion($project);

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertDontSee('No registered client can confirm this online.', false);
    }

    /**
     * Said before the project starts waiting rather than after, when it would
     * be a window too late to act on.
     *
     * Asserted on the projects listing, which is where the completion dialog
     * is drawn for every project. The dialog on a project's own page carries
     * the identical notice, but that page only draws one for an overdue
     * project - see the note in the test below.
     */
    public function test_the_completion_dialog_warns_before_the_project_starts_waiting(): void
    {
        $this->actingAsSuperAdmin();

        // Running today, so the project is Ongoing and the listing draws a
        // completion dialog for it.
        $this->schedule($this->project(), -2, 2);

        $this->get(route('super-admin.projects'))
            ->assertOk()
            ->assertSee('No registered client can currently confirm this project.', false);
    }

    /**
     * The same notice in the project page's own completion dialog.
     *
     * The project is made overdue to reach it, because that page draws a
     * completion dialog only inside its overdue banner - a pre-existing quirk
     * of the page, untouched here, and the reason the check above uses the
     * listing instead.
     */
    public function test_the_project_pages_own_completion_dialog_carries_the_same_notice(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();

        $this->schedule($project, -10, -3);

        $this->assertTrue($project->refresh()->isOverdue());

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('No registered client can currently confirm this project.', false);
    }

    public function test_the_listing_flags_a_project_nobody_can_confirm(): void
    {
        $this->actingAsSuperAdmin();

        $this->requestCompletion($this->project());

        $this->get(route('super-admin.projects'))
            ->assertOk()
            ->assertSee('No client to confirm', false);
    }

    public function test_the_project_page_shows_the_two_addresses_when_they_differ(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->link($project, $this->clientAccount());

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee("This registered user's email doesn't match the project's.", false)
            ->assertSee('Use account email', false);
    }

    public function test_the_project_page_is_silent_when_the_addresses_agree(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->link($project, $this->clientAccount(self::PROJECT_EMAIL));

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertDontSee("This registered user's email doesn't match the project's.", false);
    }

    /**
     * The recorded confirmation has to be readable afterwards, or the entry it
     * leaves behind is a claim the page cannot support.
     */
    public function test_a_recorded_confirmation_is_shown_on_the_completed_project(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $project = $this->requestCompletion($this->project());

        $this->post(
            route('super-admin.projects.client-confirmation', $project->project_id),
            $this->confirmationPayload()
        )->assertRedirect();

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('Confirmed by an administrator on behalf of the client', false)
            ->assertSee('Call', false)
            ->assertSee($admin->fullName(), false)
            ->assertSee('Client confirmed completion by phone to Ana Mendoza.', false);
    }

    // ==================================================================
    // The two addresses stay two addresses
    // ==================================================================

    public function test_assigning_a_registered_user_does_not_touch_the_project_contact_email(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $account = $this->clientAccount();

        $this->put(route('super-admin.projects.registered-user.update', $project->project_id), [
            'registered_user_id' => $account->id,
        ])->assertRedirect();

        $this->assertSame(self::PROJECT_EMAIL, $project->refresh()->clients->first()->email_address);
        $this->assertSame(self::ACCOUNT_EMAIL, $account->refresh()->email);
    }

    public function test_editing_the_project_contact_email_does_not_touch_the_account(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $account = $this->clientAccount();
        $this->link($project, $account);

        $this->put(route('super-admin.projects.update', $project->project_id), [
            'first_name' => 'Owner',
            'last_name' => 'Person',
            'address' => 'Address',
            'contact_number' => '09123456789',
            'email_address' => 'new.office@company.test',
            'quotation' => 1000,
            'project_description' => 'Description',
            'project_types' => [ProjectType::create(['type_name' => 'Roofing'])->type_id],
        ])->assertRedirect();

        $this->assertSame('new.office@company.test', $project->refresh()->clients->first()->email_address);
        $this->assertSame(self::ACCOUNT_EMAIL, $account->refresh()->email);
    }

    public function test_a_difference_between_the_two_addresses_is_reported(): void
    {
        $project = $this->project();
        $this->link($project, $this->clientAccount());

        $this->assertTrue(app(ProjectRegisteredUser::class)->accountEmailDiffers($project));
    }

    /**
     * Capitals and stray spaces are not a difference. Reporting them as one
     * would put a notice on a project where nothing is wrong.
     */
    public function test_capitalisation_alone_is_not_a_difference(): void
    {
        $project = $this->project('Office@Company.test');
        $this->link($project, $this->clientAccount(self::PROJECT_EMAIL));

        $this->assertFalse(app(ProjectRegisteredUser::class)->accountEmailDiffers($project));
    }

    public function test_an_administrator_can_move_the_project_onto_the_account_address(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $project = $this->project();
        $account = $this->clientAccount();
        $this->link($project, $account);

        $this->put(route('super-admin.projects.contact-email.account', $project->project_id))
            ->assertRedirect();

        // The project moved.
        $this->assertSame(self::ACCOUNT_EMAIL, $project->refresh()->clients->first()->email_address);
        // The account did not. This is the half that must never break: the
        // address here is a login credential.
        $this->assertSame(self::ACCOUNT_EMAIL, $account->refresh()->email);

        $entry = ActivityLog::query()
            ->where('action', ActivityLog::PROJECT_CONTACT_EMAIL_UPDATED)
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertStringContainsString(self::PROJECT_EMAIL, $entry->description);
        $this->assertStringContainsString(self::ACCOUNT_EMAIL, $entry->description);
    }

    public function test_only_administrators_may_move_the_project_onto_the_account_address(): void
    {
        $project = $this->project();
        $account = $this->clientAccount();
        $this->link($project, $account);

        $this->actingAs($account);

        $this->put(route('super-admin.projects.contact-email.account', $project->project_id))
            ->assertRedirect();

        $this->assertSame(self::PROJECT_EMAIL, $project->refresh()->clients->first()->email_address);
    }

    // ==================================================================
    // A client changing their own address
    // ==================================================================

    /**
     * The case that must not happen: a contact address deliberately set to
     * something else - a company mailbox, a site office - is nothing to do
     * with which inbox the client signs in from, and changing one must not
     * silently rewrite the other.
     */
    public function test_a_client_changing_their_email_leaves_a_different_project_address_alone(): void
    {
        $project = $this->project();
        $account = $this->clientAccount();
        $this->link($project, $account);

        $this->changeAccountEmail($account, 'maria@newmail.test');

        $this->assertSame(self::PROJECT_EMAIL, $project->refresh()->clients->first()->email_address);
        $this->assertSame('maria@newmail.test', $account->refresh()->email);
    }

    /**
     * Where the two were the same address they were not two facts but one, so
     * they stay one. This is what the carry-over exists for.
     */
    public function test_a_client_changing_their_email_carries_a_matching_project_address(): void
    {
        $project = $this->project();
        $account = $this->clientAccount(self::PROJECT_EMAIL);
        $this->link($project, $account);

        $this->changeAccountEmail($account, 'maria@newmail.test');

        $this->assertSame('maria@newmail.test', $project->refresh()->clients->first()->email_address);
    }

    public function test_the_carry_over_recognises_the_old_address_written_differently(): void
    {
        $project = $this->project('Office@Company.test');
        $account = $this->clientAccount(self::PROJECT_EMAIL);
        $this->link($project, $account);

        $this->changeAccountEmail($account, 'maria@newmail.test');

        $this->assertSame('maria@newmail.test', $project->refresh()->clients->first()->email_address);
    }

    /**
     * Another client's project is never touched, whatever it is addressed to.
     */
    public function test_a_client_changing_their_email_never_touches_another_clients_project(): void
    {
        $mine = $this->project();
        $account = $this->clientAccount(self::PROJECT_EMAIL);
        $this->link($mine, $account);

        $theirs = $this->project('someone.else@example.test');

        $this->changeAccountEmail($account, 'maria@newmail.test');

        $this->assertSame('someone.else@example.test', $theirs->refresh()->clients->first()->email_address);
    }

    // ==================================================================
    // Who actually receives the message
    // ==================================================================

    /**
     * The failure this fixes: "please confirm this project" went to the office
     * mailbox, while the only person who could press Confirm was Maria - who
     * was never told.
     */
    public function test_project_updates_reach_both_the_contact_and_the_account(): void
    {
        $project = $this->project();
        $this->link($project, $this->clientAccount());

        Mail::fake();

        app(ProjectEmails::class)->projectAwaitingConfirmation($project);

        Mail::assertQueued(ProjectUpdateMail::class, 2);

        foreach ([self::PROJECT_EMAIL, self::ACCOUNT_EMAIL] as $address) {
            Mail::assertQueued(
                ProjectUpdateMail::class,
                fn (ProjectUpdateMail $mail): bool => $mail->hasTo($address)
            );
        }
    }

    public function test_one_address_receives_exactly_one_copy(): void
    {
        $project = $this->project();
        // The account signs in with the address the project was booked to,
        // which is the ordinary case and must not be mailed twice.
        $this->link($project, $this->clientAccount(self::PROJECT_EMAIL));

        Mail::fake();

        app(ProjectEmails::class)->projectAwaitingConfirmation($project);

        Mail::assertQueued(ProjectUpdateMail::class, 1);
    }

    public function test_the_same_address_written_differently_is_still_one_copy(): void
    {
        $project = $this->project('Office@Company.test');
        $this->link($project, $this->clientAccount(self::PROJECT_EMAIL));

        Mail::fake();

        app(ProjectEmails::class)->projectAwaitingConfirmation($project);

        Mail::assertQueued(ProjectUpdateMail::class, 1);
    }

    public function test_an_unusable_contact_address_does_not_stop_the_account_being_told(): void
    {
        $project = $this->project('not-an-address');
        $this->link($project, $this->clientAccount());

        Mail::fake();

        app(ProjectEmails::class)->projectAwaitingConfirmation($project);

        Mail::assertQueued(ProjectUpdateMail::class, 1);
        Mail::assertQueued(
            ProjectUpdateMail::class,
            fn (ProjectUpdateMail $mail): bool => $mail->hasTo(self::ACCOUNT_EMAIL)
        );
    }

    public function test_a_project_with_no_account_still_reaches_its_contact(): void
    {
        $project = $this->project();

        Mail::fake();

        app(ProjectEmails::class)->projectAwaitingConfirmation($project);

        Mail::assertQueued(ProjectUpdateMail::class, 1);
        Mail::assertQueued(
            ProjectUpdateMail::class,
            fn (ProjectUpdateMail $mail): bool => $mail->hasTo(self::PROJECT_EMAIL)
        );
    }

    // ------------------------------------------------------------------
    // The dialog the completion buttons open
    // ------------------------------------------------------------------

    /**
     * The header's Complete Project button has a dialog to open.
     *
     * It is drawn on everything completable that is NOT overdue - the overdue
     * banner carries its own button, and two for one dialog on one page is one
     * too many. So a dialog defined inside that banner is defined on exactly
     * the projects the header button is never drawn for, and the header button
     * on exactly the projects the dialog does not exist for: pressing it did
     * nothing at all. Both buttons name one id, so the element has to be
     * rendered wherever completion is offered, not only where the work is late.
     */
    public function test_the_header_completion_button_has_its_dialog_on_a_project_that_is_not_late(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        // Straddles today, so the project is under way and not yet late.
        $this->schedule($project, -2, 5);
        $project->refresh();

        $this->assertFalse($project->isOverdue());
        $this->assertTrue($project->isCompletableBy(auth()->user()));

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('id="completeProjectModal"', false)
            ->assertSee(route('super-admin.projects.complete', $project->project_id), false);
    }

    /**
     * And exactly one of it, however the project is offered completion:
     * two elements with one id would give Bootstrap a choice to make.
     */
    public function test_the_completion_dialog_is_rendered_once_on_a_late_project(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->schedule($project, -10, -5);
        $project->refresh();

        $this->assertTrue($project->isOverdue());

        $response = $this->get(route('super-admin.projects.show', $project->project_id));

        $response->assertOk();
        $response->assertSee('Mark as Complete');
        $this->assertSame(
            1,
            substr_count($response->getContent(), 'id="completeProjectModal"')
        );
    }

    /**
     * Whichever button opened it, it is the same dialog posting the same
     * fields to the same endpoint as the one on the Projects page - so
     * completing a project reads and behaves identically from either place.
     */
    public function test_the_dialog_collects_what_the_projects_page_dialog_collects(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->schedule($project, -2, 5);

        $details = $this->get(route('super-admin.projects.show', $project->project_id));
        $listing = $this->get(route('super-admin.projects'));

        $details->assertOk();
        $listing->assertOk();

        $fields = [
            'name="completion_date"',
            'name="completion_summary"',
            'name="completion_remarks"',
            'name="completion_photos[]"',
            'enctype="multipart/form-data"',
        ];

        foreach ($fields as $field) {
            $details->assertSee($field, false);
            $listing->assertSee($field, false);
        }

        // And both post to the one endpoint, which asks the completion rules
        // again before it writes anything.
        $details->assertSee(route('super-admin.projects.complete', $project->project_id), false);
        $listing->assertSee(route('super-admin.projects.complete', $project->project_id), false);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * A project put into Awaiting Client Confirmation by an administrator, for
     * a test that then signs in as somebody else.
     */
    private function requestCompletionAsAdmin(): Project
    {
        $this->actingAsSuperAdmin();

        $project = $this->requestCompletion($this->project());

        return $project;
    }
}
