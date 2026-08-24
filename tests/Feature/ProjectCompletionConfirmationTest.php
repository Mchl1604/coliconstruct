<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Document;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\ProjectType;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\SystemContent;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use App\Services\DashboardMetrics;
use App\Services\TechnicianAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Completing a project no longer closes it: it hands it to the client, who has
 * seven days to confirm before the system closes it for them. An administrator
 * may reopen it in the meantime, onto a new schedule; once it is Completed,
 * nobody may reopen it at all.
 *
 * The schedule half of this is the part that was already right and must stay
 * right: every date past the completion date is released, across every range
 * the project holds, and the days already worked are kept.
 */
class ProjectCompletionConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_EMAIL = 'owner@example.test';

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function technician(string $name, string $role = 'technician'): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => $role])->save();

        return Technician::create(['account_id' => $user->id, 'role' => $role]);
    }

    private function project(string $status = 'ongoing', string $email = self::CLIENT_EMAIL): Project
    {
        $project = Project::create([
            'name' => 'Confirmation Project',
            'reference_no' => 'REF-'.strtoupper(substr(md5($email.microtime()), 0, 8)),
            'status' => $status,
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

        // One finished task, because a project that is ready to be completed
        // has work behind it: the completion rules refuse a project with
        // nothing recorded on it, and an administrator getting past that needs
        // a stated override. These tests are about what completion does to the
        // dates and the client, so they start from a project the rules are
        // happy with - the override itself is covered separately.
        Task::create([
            'project_id' => $project->project_id,
            'task_title' => 'Work that was carried out',
            'task_description' => 'Description',
            'status' => 'completed',
            'completed_at' => CarbonImmutable::now(),
        ]);

        return $project;
    }

    /**
     * A booking, with the project's whole team on it - which is the row
     * availability actually reads.
     */
    private function schedule(Project $project, int $startOffset, int $endOffset): Schedule
    {
        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => CarbonImmutable::today()->addDays($startOffset)->startOfDay(),
            'end_datetime' => CarbonImmutable::today()->addDays($endOffset)->endOfDay(),
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'status' => 'scheduled',
        ]);

        ProjectTechnician::where('project_id', $project->project_id)
            ->pluck('project_technician_id')
            ->each(fn ($id) => ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $id,
            ]));

        return $schedule;
    }

    private function assign(Project $project, Technician $technician): ProjectTechnician
    {
        return ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);
    }

    /**
     * A valid payload for the edit-project endpoint.
     *
     * Every field is required and at least one project type must be given, so
     * a half-filled payload would be refused by validation and tell us nothing
     * about whether the read-only guard works. This one would genuinely save.
     *
     * @return array<string, mixed>
     */
    private function editPayload(string $address): array
    {
        $type = ProjectType::firstOrCreate(['type_name' => 'Aircon Installation']);

        return [
            'first_name' => 'Owner',
            'last_name' => 'Person',
            'address' => $address,
            'contact_number' => '09123456789',
            'email_address' => self::CLIENT_EMAIL,
            'quotation' => 2000,
            'project_description' => 'Updated description',
            'project_types' => [$type->type_id],
        ];
    }

    private function clientAccount(string $email = self::CLIENT_EMAIL): User
    {
        $user = User::factory()->create(['name' => 'Owner Person', 'email' => $email]);

        $user->forceFill(['role' => User::ROLE_CLIENT, 'status' => User::STATUS_ACTIVE])->save();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function completionPayload(?string $date = null): array
    {
        return [
            'completion_date' => $date ?? CarbonImmutable::today()->toDateString(),
            'completion_summary' => 'Everything on site is finished.',
        ];
    }

    /**
     * Put a project into Awaiting Client Confirmation the way the application
     * does, so every test below starts from a real state rather than a
     * hand-written row.
     */
    private function requestCompletion(Project $project, ?string $date = null): Project
    {
        $this->post(
            route('super-admin.projects.complete', $project->project_id),
            $this->completionPayload($date)
        )->assertRedirect();

        return $project->refresh();
    }

    // ==================================================================
    // Releasing the dates
    // ==================================================================

    /**
     * The rule, on the shape it was specified with: several ranges, one of
     * them straddling the completion date.
     *
     *   Aug 6-10, Aug 15-25, Sep 1-5, completed Aug 8
     *     -> Aug 6-8 kept, everything else gone
     */
    public function test_completion_releases_every_date_after_the_completion_date(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Ana Cruz'));

        $straddling = $this->schedule($project, -2, 2);   // running, ends ahead
        $future = $this->schedule($project, 7, 17);       // wholly ahead
        $further = $this->schedule($project, 24, 28);     // wholly ahead

        $this->requestCompletion($project);

        // Both ranges ahead are gone; the running one is kept but cut short.
        $this->assertDatabaseMissing('tbl_schedule', ['schedule_id' => $future->schedule_id]);
        $this->assertDatabaseMissing('tbl_schedule', ['schedule_id' => $further->schedule_id]);
        $this->assertDatabaseHas('tbl_schedule', ['schedule_id' => $straddling->schedule_id]);

        $kept = Schedule::find($straddling->schedule_id);

        $this->assertSame(1, $project->schedules()->count());
        $this->assertSame(
            CarbonImmutable::today()->subDays(2)->toDateString(),
            $kept->startsOn()->toDateString(),
            'The days already worked must not be touched.'
        );
        $this->assertSame(
            CarbonImmutable::today()->toDateString(),
            $kept->endsOn()->toDateString(),
            'The running range must be cut short at the completion date.'
        );
    }

    /**
     * Only the range ahead is released, not merely the next one - a project
     * booked in several separate stretches has to be freed in all of them.
     */
    public function test_release_covers_ranges_beyond_the_next_one(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Bea Reyes'));

        $this->schedule($project, 3, 4);
        $this->schedule($project, 40, 45);
        $this->schedule($project, 90, 95);

        $this->requestCompletion($project);

        $this->assertSame(0, $project->schedules()->count());
    }

    /**
     * Releasing the dates has to let go of the technicians booked on them, or
     * the crew stays busy for work nobody is going to do.
     */
    public function test_released_dates_free_the_technicians_booked_on_them(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Carlo Diaz'));

        $future = $this->schedule($project, 10, 12);

        $this->assertDatabaseHas('tbl_schedule_technicians', ['schedule_id' => $future->schedule_id]);

        $this->requestCompletion($project);

        $this->assertDatabaseMissing('tbl_schedule_technicians', ['schedule_id' => $future->schedule_id]);
    }

    /**
     * The days that were kept keep their crew: that is the audit record of who
     * actually worked them.
     */
    public function test_kept_dates_keep_their_technician_assignments(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Dina Lim'));

        $running = $this->schedule($project, -3, 5);

        $this->requestCompletion($project);

        $this->assertDatabaseHas('tbl_schedule_technicians', ['schedule_id' => $running->schedule_id]);
        $this->assertDatabaseHas('tbl_project_technicians', ['project_id' => $project->project_id]);
    }

    /**
     * A completion date in the future would release nothing at all, since
     * every booked day would fall before the cutoff. It is refused rather than
     * quietly defeating the whole mechanism.
     */
    public function test_a_future_completion_date_is_refused(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Elle Tan'));
        $future = $this->schedule($project, 5, 9);

        $this->post(
            route('super-admin.projects.complete', $project->project_id),
            $this->completionPayload(CarbonImmutable::today()->addDays(3)->toDateString())
        )->assertSessionHasErrors('completion_date');

        $this->assertSame('ongoing', $project->refresh()->status);
        $this->assertDatabaseHas('tbl_schedule', ['schedule_id' => $future->schedule_id]);
    }

    /**
     * A date freed by completion is genuinely free: another project's crew can
     * be booked over it.
     */
    public function test_a_released_date_stops_blocking_the_technician(): void
    {
        $this->actingAsSuperAdmin();

        $technician = $this->technician('Fay Uy');

        $project = $this->project();
        $this->assign($project, $technician);
        $this->schedule($project, 10, 14);

        $range = [[
            'start' => CarbonImmutable::today()->addDays(11),
            'end' => CarbonImmutable::today()->addDays(12),
        ]];

        $availability = app(TechnicianAvailabilityService::class);

        $this->assertTrue(
            $availability->findConflicts([$technician->technician_id], $range)->isNotEmpty(),
            'The technician should be busy while the project still holds those dates.'
        );

        $this->requestCompletion($project);

        $this->assertTrue(
            $availability->findConflicts([$technician->technician_id], $range)->isEmpty(),
            'Releasing the dates must free the technician for other work.'
        );
    }

    // ==================================================================
    // The new status
    // ==================================================================

    public function test_completing_sends_the_project_for_client_confirmation(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Gia Ong'));
        $this->schedule($project, -5, -1);

        $this->requestCompletion($project);

        $this->assertSame(Project::STATUS_AWAITING_CLIENT_CONFIRMATION, $project->status);
        $this->assertNotNull($project->completion_requested_at);
        $this->assertSame($admin->id, (int) $project->completion_requested_by);
        $this->assertNull($project->client_confirmed_at);
        $this->assertNull($project->completion_method);

        // The completion report itself is untouched by the split.
        $this->assertSame('Everything on site is finished.', $project->completion_summary);
        $this->assertSame(CarbonImmutable::today()->toDateString(), $project->completed_at->toDateString());
    }

    public function test_the_completion_request_is_recorded_in_the_activity_log(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Hana Sy'));
        $this->schedule($project, -5, -1);

        $this->requestCompletion($project);

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::PROJECT_COMPLETION_REQUESTED,
            'record_type' => 'Project',
            'record_id' => $project->project_id,
        ]);
    }

    /**
     * The whole point of the new status: the project is locked, and the lock
     * is enforced by the server rather than by which buttons were drawn.
     */
    public function test_an_awaiting_project_rejects_edits_from_the_backend(): void
    {
        $this->actingAsSuperAdmin();

        $lead = $this->technician('Ivan Roy', 'lead_technician');
        $project = $this->project();
        $this->assign($project, $lead);
        $schedule = $this->schedule($project, -5, -1);

        $this->requestCompletion($project);

        // Project information. The payload is a valid one, so what refuses it
        // is the lock rather than a validation error.
        $this->put(
            route('super-admin.projects.update', $project->project_id),
            $this->editPayload('Elsewhere')
        )->assertRedirect();

        $this->assertSame('Address', $project->refresh()->address);

        // The assigned team.
        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [],
        ]);

        // The schedule.
        $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [[
                'schedule_id' => $schedule->schedule_id,
                'start_date' => CarbonImmutable::today()->addDay()->toDateString(),
                'end_date' => CarbonImmutable::today()->addDays(4)->toDateString(),
            ]],
        ])->assertRedirect();

        // Untouched: the range already ended before completion, so the lock
        // is the only thing that could have stopped it moving forward.
        $this->assertSame(
            CarbonImmutable::today()->subDay()->toDateString(),
            Schedule::find($schedule->schedule_id)->endsOn()->toDateString(),
            'The schedule must not be editable while the project is locked.'
        );

        // And it is still awaiting, not quietly moved on by any of that.
        $this->assertSame(Project::STATUS_AWAITING_CLIENT_CONFIRMATION, $project->refresh()->status);
    }

    public function test_an_awaiting_project_cannot_be_cancelled(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Jade Ho'));
        $this->schedule($project, -5, -1);

        $this->requestCompletion($project);

        $this->post(route('super-admin.projects.cancel', $project->project_id), [
            'cancellation_date' => CarbonImmutable::today()->toDateString(),
            'cancellation_reason' => 'Changed our minds.',
        ])->assertRedirect();

        $this->assertSame(Project::STATUS_AWAITING_CLIENT_CONFIRMATION, $project->refresh()->status);
    }

    // ==================================================================
    // The client confirming
    // ==================================================================

    public function test_the_owning_client_can_confirm_completion(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Kim Lao'));
        $this->schedule($project, -5, -1);
        $this->requestCompletion($project);

        $client = $this->clientAccount();
        $this->actingAs($client);

        $this->post(route('public.projects.confirm', $project->project_id))
            ->assertRedirect(route('public.projects.show', $project->project_id));

        $project->refresh();

        $this->assertSame('completed', $project->status);
        $this->assertSame(Project::METHOD_CLIENT_CONFIRMED, $project->completion_method);
        $this->assertNotNull($project->client_confirmed_at);
        $this->assertSame($client->id, (int) $project->client_confirmed_by);

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::PROJECT_COMPLETION_CONFIRMED,
            'record_id' => $project->project_id,
        ]);
    }

    /**
     * Confirming keeps the schedule history exactly as completion left it.
     */
    public function test_confirming_does_not_touch_the_schedule(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Lita Go'));
        $this->schedule($project, -6, -2);
        $this->requestCompletion($project);

        $before = $project->schedules()->pluck('schedule_id')->all();

        $this->actingAs($this->clientAccount());
        $this->post(route('public.projects.confirm', $project->project_id));

        $this->assertSame($before, $project->refresh()->schedules()->pluck('schedule_id')->all());
    }

    public function test_another_client_cannot_confirm_somebody_elses_project(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Mio Sun'));
        $this->schedule($project, -5, -1);
        $this->requestCompletion($project);

        // A perfectly valid client account, just not this project's.
        $this->actingAs($this->clientAccount('someone.else@example.test'));

        $this->post(route('public.projects.confirm', $project->project_id))->assertNotFound();

        $this->assertSame(
            Project::STATUS_AWAITING_CLIENT_CONFIRMATION,
            $project->refresh()->status
        );
    }

    public function test_a_project_that_is_not_awaiting_cannot_be_confirmed(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Noel Bay'));
        $this->schedule($project, -5, -1);

        $this->actingAs($this->clientAccount());

        $this->post(route('public.projects.confirm', $project->project_id))->assertRedirect();

        $this->assertSame('ongoing', $project->refresh()->status);
    }

    // ==================================================================
    // The seven day clock
    // ==================================================================

    public function test_the_day_five_reminder_goes_out_once_and_does_not_complete_anything(): void
    {
        $this->actingAsSuperAdmin();

        $client = $this->clientAccount();
        $project = $this->project();
        $this->assign($project, $this->technician('Ola Pua'));
        $this->schedule($project, -8, -6);
        $this->requestCompletion($project);

        // Five days ago, so the reminder is due and the deadline is not.
        $project->forceFill([
            'completion_requested_at' => CarbonImmutable::now()->subDays(Project::COMPLETION_REMINDER_DAYS),
        ])->save();

        $this->artisan('projects:process-completion-confirmations')->assertSuccessful();

        $project->refresh();

        $this->assertNotNull($project->completion_reminder_sent_at);
        $this->assertSame(
            Project::STATUS_AWAITING_CLIENT_CONFIRMATION,
            $project->status,
            'A reminder must not complete the project.'
        );

        $reminders = fn (): int => Notification::query()
            ->where('user_id', $client->id)
            ->where('title', 'Reminder: Confirm Your Completed Project')
            ->count();

        $this->assertSame(1, $reminders());

        // Running again the same day adds nothing: the stamp is what stops it.
        $this->artisan('projects:process-completion-confirmations')->assertSuccessful();

        $this->assertSame(1, $reminders());
    }

    /**
     * The reminder must not move the deadline it is warning about.
     */
    public function test_the_reminder_does_not_reset_the_clock(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Pia Roq'));
        $this->schedule($project, -8, -6);
        $this->requestCompletion($project);

        $requestedAt = CarbonImmutable::now()->subDays(Project::COMPLETION_REMINDER_DAYS);
        $project->forceFill(['completion_requested_at' => $requestedAt])->save();

        $this->artisan('projects:process-completion-confirmations');

        $this->assertSame(
            $requestedAt->toDateTimeString(),
            CarbonImmutable::parse($project->refresh()->completion_requested_at)->toDateTimeString()
        );
    }

    public function test_a_project_completes_itself_after_seven_days(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Quin Val'));
        $this->schedule($project, -10, -8);
        $this->requestCompletion($project);

        $project->forceFill([
            'completion_requested_at' => CarbonImmutable::now()->subDays(Project::COMPLETION_CONFIRMATION_DAYS),
        ])->save();

        $this->artisan('projects:process-completion-confirmations')->assertSuccessful();

        $project->refresh();

        $this->assertSame('completed', $project->status);
        $this->assertSame(Project::METHOD_AUTO_COMPLETED, $project->completion_method);
        $this->assertNotNull($project->client_confirmed_at);
        $this->assertNull($project->client_confirmed_by, 'Nobody clicked, so nobody is recorded.');

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::PROJECT_AUTO_COMPLETED,
            'record_id' => $project->project_id,
        ]);
    }

    public function test_a_project_inside_the_window_is_left_alone(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Rey Cua'));
        $this->schedule($project, -3, -1);
        $this->requestCompletion($project);

        $this->artisan('projects:process-completion-confirmations')->assertSuccessful();

        $project->refresh();

        $this->assertSame(Project::STATUS_AWAITING_CLIENT_CONFIRMATION, $project->status);
        $this->assertNull($project->completion_reminder_sent_at);
    }

    /**
     * Work closed under the old rules carries no completion_requested_at, and
     * must not be swept up by a clock that did not exist when it finished.
     */
    public function test_projects_completed_before_the_workflow_are_ignored(): void
    {
        $project = $this->project('completed');

        $this->artisan('projects:process-completion-confirmations')->assertSuccessful();

        $this->assertSame('completed', $project->refresh()->status);
    }

    // ==================================================================
    // Reopening
    // ==================================================================

    public function test_an_administrator_can_reopen_an_awaiting_project_onto_a_new_schedule(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $project = $this->project();
        $technician = $this->technician('Sam Ilo');
        $this->assign($project, $technician);
        $kept = $this->schedule($project, -4, -1);
        $this->requestCompletion($project);

        $this->post(route('super-admin.projects.reopen', $project->project_id), [
            'reopen_reason' => 'Additional installation work is required.',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => CarbonImmutable::today()->addDays(10)->toDateString(),
            'end_date' => CarbonImmutable::today()->addDays(14)->toDateString(),
        ])->assertRedirect();

        $project->refresh();

        $this->assertSame('ongoing', $project->status);
        $this->assertNotNull($project->reopened_at);
        $this->assertSame($admin->id, (int) $project->reopened_by);
        $this->assertSame('Additional installation work is required.', $project->reopen_reason);
        // The clock stops.
        $this->assertNull($project->completion_requested_at);

        // The history stays, and the new work sits beside it.
        $this->assertSame(2, $project->schedules()->count());
        $this->assertDatabaseHas('tbl_schedule', ['schedule_id' => $kept->schedule_id]);

        // The relation orders by start_datetime, so "the new one" is the last
        // of them rather than the highest id.
        $new = $project->schedules()->get()->last();

        $this->assertSame(
            CarbonImmutable::today()->addDays(10)->toDateString(),
            $new->startsOn()->toDateString()
        );

        // The crew is booked onto the new dates, not merely on the team.
        $this->assertDatabaseHas('tbl_schedule_technicians', ['schedule_id' => $new->schedule_id]);

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::PROJECT_REOPENED,
            'record_id' => $project->project_id,
        ]);
    }

    /**
     * The released dates are gone for good - somebody else may have taken
     * them. Reopening creates new work rather than restoring old claims.
     */
    public function test_reopening_does_not_restore_the_released_schedules(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Tess Uy'));
        $this->schedule($project, -4, -1);
        $released = $this->schedule($project, 20, 25);

        $this->requestCompletion($project);
        $this->assertDatabaseMissing('tbl_schedule', ['schedule_id' => $released->schedule_id]);

        $this->post(route('super-admin.projects.reopen', $project->project_id), [
            'reopen_reason' => 'More work was found on site.',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => CarbonImmutable::today()->addDays(30)->toDateString(),
            'end_date' => CarbonImmutable::today()->addDays(32)->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseMissing('tbl_schedule', ['schedule_id' => $released->schedule_id]);
        $this->assertSame(2, $project->refresh()->schedules()->count());
    }

    public function test_a_completed_project_can_never_be_reopened(): void
    {
        // Kept rather than re-created: actingAsSuperAdmin() writes a fresh
        // EMP-0001 each time, and calling it twice in one test collides.
        $admin = $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Uma Vil'));
        $this->schedule($project, -5, -2);
        $this->requestCompletion($project);

        $this->actingAs($this->clientAccount());
        $this->post(route('public.projects.confirm', $project->project_id));

        $this->assertSame('completed', $project->refresh()->status);

        $this->actingAs($admin);

        $this->post(route('super-admin.projects.reopen', $project->project_id), [
            'reopen_reason' => 'We would like it back please.',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => CarbonImmutable::today()->addDays(5)->toDateString(),
            'end_date' => CarbonImmutable::today()->addDays(6)->toDateString(),
        ])->assertRedirect();

        $project->refresh();

        $this->assertSame('completed', $project->status);
        $this->assertSame(1, $project->schedules()->count(), 'No schedule may be created for a completed project.');
    }

    public function test_reopening_requires_a_reason(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Vic Wan'));
        $this->schedule($project, -5, -2);
        $this->requestCompletion($project);

        $this->post(route('super-admin.projects.reopen', $project->project_id), [
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => CarbonImmutable::today()->addDays(5)->toDateString(),
            'end_date' => CarbonImmutable::today()->addDays(6)->toDateString(),
        ])->assertSessionHasErrors('reopen_reason');

        $this->assertSame(
            Project::STATUS_AWAITING_CLIENT_CONFIRMATION,
            $project->refresh()->status
        );
    }

    /**
     * The new schedule goes through the same availability rules as every other
     * booking - and a failure must leave nothing behind.
     */
    public function test_reopening_is_refused_when_the_team_is_already_booked(): void
    {
        $this->actingAsSuperAdmin();

        $technician = $this->technician('Wes Yap');

        $project = $this->project();
        $this->assign($project, $technician);
        $this->schedule($project, -5, -2);
        $this->requestCompletion($project);

        // The same technician, busy on live work over the dates being asked for.
        $other = $this->project('ongoing', 'other.client@example.test');
        $this->assign($other, $technician);
        $this->schedule($other, 10, 14);

        $this->post(route('super-admin.projects.reopen', $project->project_id), [
            'reopen_reason' => 'Additional work is required on site.',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => CarbonImmutable::today()->addDays(11)->toDateString(),
            'end_date' => CarbonImmutable::today()->addDays(12)->toDateString(),
        ])->assertRedirect();

        $project->refresh();

        // Nothing was written: not the status, and not a stray schedule row.
        $this->assertSame(Project::STATUS_AWAITING_CLIENT_CONFIRMATION, $project->status);
        $this->assertSame(1, $project->schedules()->count());
        $this->assertNull($project->reopened_at);
    }

    /**
     * The dialog says which days it cannot book before anything is typed.
     *
     * The pickers used to be plain date fields with a floor of today, so every
     * day the team was already spoken for looked as pickable as any other and
     * the refusal only arrived after the button was pressed. The days the
     * reopen would refuse now ride in on the form and are greyed out - drawn
     * from the same availability service the reopen itself is checked by.
     */
    public function test_the_reopen_dialog_greys_out_days_the_team_is_booked_elsewhere(): void
    {
        $this->actingAsSuperAdmin();

        $technician = $this->technician('Ivy Rale');

        $project = $this->project();
        $this->assign($project, $technician);
        $this->schedule($project, -5, -2);
        $this->requestCompletion($project);

        // The same technician, on live work ahead of today.
        $other = $this->project('ongoing', 'other.client@example.test');
        $this->assign($other, $technician);
        $this->schedule($other, 10, 12);

        $response = $this->get(route('super-admin.projects.show', $project->project_id));

        $response->assertOk();

        $blocked = $this->blockedReopenDates($response->getContent());

        $this->assertContains(CarbonImmutable::today()->addDays(10)->toDateString(), $blocked);
        $this->assertContains(CarbonImmutable::today()->addDays(11)->toDateString(), $blocked);
        $this->assertContains(CarbonImmutable::today()->addDays(12)->toDateString(), $blocked);
        // A day either side of that booking is still free work.
        $this->assertNotContains(CarbonImmutable::today()->addDays(9)->toDateString(), $blocked);
        $this->assertNotContains(CarbonImmutable::today()->addDays(13)->toDateString(), $blocked);
    }

    /**
     * A project is never its own blocker.
     *
     * Asking for completion released every day past the completion date, and
     * those days were free for anybody from that moment - including for this
     * project, reopening onto them. What it may not do is book over the days
     * it kept, which are days the crew actually worked. Both halves are
     * visible in the dialog: the kept day is greyed out, the released ones are
     * not.
     */
    public function test_the_reopen_dialog_frees_the_days_the_project_released_and_keeps_the_days_it_worked(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Jed Mora'));
        // Straddles today, so completing it keeps up to today and releases the
        // rest - the same technician was booked across the whole of it.
        $this->schedule($project, -1, 5);
        $this->requestCompletion($project);

        $response = $this->get(route('super-admin.projects.show', $project->project_id));

        $response->assertOk();

        $blocked = $this->blockedReopenDates($response->getContent());

        // Worked, and still the project's own: not bookable again.
        $this->assertContains(CarbonImmutable::today()->toDateString(), $blocked);

        // Released at completion, and free again - the project's own former
        // claim on them is not allowed to read as a clash.
        $this->assertNotContains(CarbonImmutable::today()->addDays(1)->toDateString(), $blocked);
        $this->assertNotContains(CarbonImmutable::today()->addDays(5)->toDateString(), $blocked);
    }

    /**
     * Whatever the picker offered, the reopen is checked again on the way in -
     * so a date typed past a greyed-out one changes nothing.
     */
    public function test_a_greyed_out_date_is_still_refused_when_it_is_posted_anyway(): void
    {
        $this->actingAsSuperAdmin();

        $technician = $this->technician('Kip Vale');

        $project = $this->project();
        $this->assign($project, $technician);
        $this->schedule($project, -5, -2);
        $this->requestCompletion($project);

        $other = $this->project('ongoing', 'other.client@example.test');
        $this->assign($other, $technician);
        $this->schedule($other, 10, 12);

        $response = $this->get(route('super-admin.projects.show', $project->project_id));
        $blocked = $this->blockedReopenDates($response->getContent());

        $refused = CarbonImmutable::today()->addDays(11)->toDateString();
        $this->assertContains($refused, $blocked);

        $this->post(route('super-admin.projects.reopen', $project->project_id), [
            'reopen_reason' => 'Additional work is required on site.',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => $refused,
            'end_date' => $refused,
        ])->assertRedirect();

        $project->refresh();

        $this->assertSame(Project::STATUS_AWAITING_CLIENT_CONFIRMATION, $project->status);
        $this->assertNull($project->reopened_at);
    }

    /**
     * A range whose ends are both free but which crosses a booked day is
     * refused too - the picker greys the crossed day out, and the server
     * refuses the range - so the two must agree about that day.
     */
    public function test_a_range_spanning_a_booked_day_is_greyed_out_and_refused(): void
    {
        $this->actingAsSuperAdmin();

        $technician = $this->technician('Lia Nuno');

        $project = $this->project();
        $this->assign($project, $technician);
        $this->schedule($project, -5, -2);
        $this->requestCompletion($project);

        $other = $this->project('ongoing', 'other.client@example.test');
        $this->assign($other, $technician);
        $this->schedule($other, 11, 11);

        $response = $this->get(route('super-admin.projects.show', $project->project_id));
        $blocked = $this->blockedReopenDates($response->getContent());

        // The ends are free; the day between them is not.
        $this->assertNotContains(CarbonImmutable::today()->addDays(10)->toDateString(), $blocked);
        $this->assertContains(CarbonImmutable::today()->addDays(11)->toDateString(), $blocked);
        $this->assertNotContains(CarbonImmutable::today()->addDays(12)->toDateString(), $blocked);

        $this->post(route('super-admin.projects.reopen', $project->project_id), [
            'reopen_reason' => 'Additional work is required on site.',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => CarbonImmutable::today()->addDays(10)->toDateString(),
            'end_date' => CarbonImmutable::today()->addDays(12)->toDateString(),
        ])->assertRedirect();

        $this->assertSame(
            Project::STATUS_AWAITING_CLIENT_CONFIRMATION,
            $project->refresh()->status
        );
    }

    /**
     * An Admin sees the same greyed-out calendar a Super Admin does: both may
     * reopen, so both have to be told the same thing about the dates.
     */
    public function test_an_admin_gets_the_same_blocked_dates_as_a_super_admin(): void
    {
        $this->actingAsSuperAdmin();

        $technician = $this->technician('Mo Sarte');

        $project = $this->project();
        $this->assign($project, $technician);
        $this->schedule($project, -5, -2);
        $this->requestCompletion($project);

        $other = $this->project('ongoing', 'other.client@example.test');
        $this->assign($other, $technician);
        $this->schedule($other, 10, 12);

        $admin = User::factory()->create(['email' => 'the.admin@example.test']);
        $admin->forceFill(['role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE])->save();

        $response = $this->actingAs($admin)
            ->get(route('super-admin.projects.show', $project->project_id));

        $response->assertOk();

        $this->assertContains(
            CarbonImmutable::today()->addDays(11)->toDateString(),
            $this->blockedReopenDates($response->getContent())
        );
    }

    /**
     * The dialog wears the same blue every other dialog in the portal wears.
     *
     * It was the only one in black, which read as a warning rather than as the
     * ordinary administrative action it is.
     */
    public function test_the_reopen_dialog_uses_the_blue_theme(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Nia Bolt'));
        $this->schedule($project, -5, -2);
        $this->requestCompletion($project);

        $response = $this->get(route('super-admin.projects.show', $project->project_id));

        $response->assertOk();
        $response->assertSee('<div class="modal-header bg-primary text-white">', false);
        $response->assertSee('<button type="submit" class="btn btn-primary">', false);
        $response->assertDontSee('modal-header bg-dark text-white', false);
    }

    /**
     * The whole-day list the Reopen form carries to its date pickers.
     *
     * @return array<int, string>
     */
    private function blockedReopenDates(string $html): array
    {
        preg_match('/data-reopen-blocked-whole-day="([^"]*)"/', $html, $matches);

        $decoded = json_decode(html_entity_decode($matches[1] ?? '[]'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A reopened project is genuinely live again, which is the whole point.
     */
    public function test_a_reopened_project_is_editable_again(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Xena Zar'));
        $this->schedule($project, -5, -2);
        $this->requestCompletion($project);

        $this->post(route('super-admin.projects.reopen', $project->project_id), [
            'reopen_reason' => 'Additional installation work is required.',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => CarbonImmutable::today()->addDays(3)->toDateString(),
            'end_date' => CarbonImmutable::today()->addDays(5)->toDateString(),
        ])->assertRedirect();

        $this->assertFalse($project->refresh()->isReadOnly());

        $this->put(
            route('super-admin.projects.update', $project->project_id),
            $this->editPayload('A new address')
        )->assertRedirect();

        $this->assertSame('A new address', $project->refresh()->address);
    }

    // ==================================================================
    // Where the status shows up
    // ==================================================================

    /**
     * Finished work is finished work: the client's list files it under
     * Completed rather than inventing a tab for it.
     */
    public function test_the_client_list_files_an_awaiting_project_under_completed(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Yuri Abe'));
        $this->schedule($project, -5, -2);
        $this->requestCompletion($project);

        $this->actingAs($this->clientAccount());

        $response = $this->get(route('public.projects'));

        $response->assertOk();
        $response->assertSee('data-status="completed"', false);
        $response->assertSee('Please confirm this completed project.', false);
    }

    /**
     * The client's own project page is where the confirming happens, so it has
     * to carry the report they are being asked to review, the deadline, and
     * both buttons - support included, because a client who is unhappy needs
     * that as plainly as the one who is happy.
     */
    public function test_the_client_project_page_offers_confirmation_and_support(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Bess Doy'));
        $this->schedule($project, -5, -2);
        $this->requestCompletion($project);

        $this->actingAs($this->clientAccount());

        $response = $this->get(route('public.projects.show', $project->project_id));

        $response->assertOk();
        $response->assertSee('Ready for your confirmation');
        $response->assertSee('Confirm Completion');
        $response->assertSee('Contact Support');
        // The report under review, and the deadline it is under.
        $response->assertSee('Everything on site is finished.');
        $response->assertSee($project->confirmationDeadline()->format('F j, Y'));
        $response->assertSee(route('public.projects.confirm', $project->project_id), false);
    }

    /**
     * Contact Support is a link into the site, whatever is configured.
     *
     * It used to become a mailto: as soon as a support address was published,
     * and a mailto: is not a page: the browser answers it by asking to hand
     * the click to whatever application claims mail, which is a prompt on a
     * machine that has one and silence on a machine that does not. Either way
     * the client never arrives anywhere.
     */
    public function test_contact_support_links_to_the_contact_page_and_opens_no_application(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Dara Sim'));
        $this->schedule($project, -5, -2);
        $this->requestCompletion($project);

        // A published support address is exactly the case that used to switch
        // the button over to mailto:.
        SystemContent::create([
            'content_key' => 'contact.email',
            'content_value' => 'support@example.test',
            'content_type' => 'text',
            'section' => 'contact',
        ]);

        $this->actingAs($this->clientAccount());

        $response = $this->get(route('public.projects.show', $project->project_id));

        $response->assertOk();
        $response->assertSee('Contact Support');
        $response->assertSee('href="'.route('public.contact').'"', false);
        $response->assertDontSee('mailto:', false);
    }

    /**
     * Once it is closed, the asking stops: no buttons, no countdown.
     */
    public function test_the_confirmation_prompt_disappears_once_confirmed(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Cleo Eng'));
        $this->schedule($project, -5, -2);
        $this->requestCompletion($project);

        $this->actingAs($this->clientAccount());
        $this->post(route('public.projects.confirm', $project->project_id));

        $response = $this->get(route('public.projects.show', $project->project_id));

        $response->assertOk();
        $response->assertDontSee('This project is ready for your confirmation');
        $response->assertDontSee('Confirm Completion');
        // The report itself stays readable - it is the record of the work.
        $response->assertSee('Everything on site is finished.');
        $response->assertSee('Confirmed by the client');
    }

    /**
     * The client reads their documents in the same grouped cards the office
     * does, and does not read the task list at all - that is how the company
     * organises its own crew, and the reports are what a client follows.
     */
    public function test_the_client_project_page_groups_documents_and_hides_tasks(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $technician = $this->technician('Dane Fox');
        $this->assign($project, $technician);
        $this->schedule($project, -5, -2);

        Document::create([
            'project_id' => $project->project_id,
            'document_type' => 'quotation',
            'document_name' => 'quotation-page-one.pdf',
            'document_path' => 'uploads/quotation/one.pdf',
            'uploaded_at' => now(),
        ]);

        Document::create([
            'project_id' => $project->project_id,
            'document_type' => 'quotation',
            'document_name' => 'quotation-page-two.pdf',
            'document_path' => 'uploads/quotation/two.pdf',
            'uploaded_at' => now(),
        ]);

        Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
            'task_title' => 'Internal crew task',
            'task_description' => 'Not the client\'s business.',
            'status' => 'pending',
        ]);

        $this->actingAs($this->clientAccount());

        $response = $this->get(route('public.projects.show', $project->project_id));

        $response->assertOk();

        // Grouped cards, with the count, and every file reachable by its own
        // name rather than as "Quotation 1" / "Quotation 2".
        $response->assertSee('project-document-groups', false);
        $response->assertSee('project-document-count', false);
        $response->assertSee('quotation-page-one.pdf');
        $response->assertSee('quotation-page-two.pdf');

        // A type the project holds nothing for is left out entirely.
        $response->assertDontSee('>Assessment<', false);

        // Removing a document is never a client's to do.
        $response->assertDontSee('data-document-remove', false);

        // And the task list is gone.
        $response->assertDontSee('Internal crew task');
        $response->assertDontSee('public-task-list', false);
    }

    /**
     * Awaiting work counts as completed in the headline figures, so the
     * dashboard cannot disagree with the Completed tab it links to.
     */
    public function test_the_dashboard_counts_awaiting_work_as_completed(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Zack Bo'));
        $this->schedule($project, -5, -2);
        $this->requestCompletion($project);

        DashboardMetrics::flush();

        $counts = app(DashboardMetrics::class)->projectCounts();

        $this->assertSame(1, $counts['completed']);
        $this->assertSame(1, $counts['awaiting_confirmation']);
    }

    /**
     * The tasks board drops a project whose work is done, exactly as it drops
     * a completed one - there is no board left to run.
     */
    public function test_an_awaiting_project_takes_no_new_tasks(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Abe Cid'));
        $this->schedule($project, -5, -2);
        $this->requestCompletion($project);

        $this->post(route('super-admin.task.store', $project->project_id), [
            'task_title' => 'One more thing',
            'task_description' => 'Should not be accepted.',
        ])->assertRedirect();

        // The finished task the project was completed on is still there; what
        // must not appear is a new one.
        $this->assertSame(0, Task::where('project_id', $project->project_id)
            ->where('task_title', 'One more thing')
            ->count());
    }
}
