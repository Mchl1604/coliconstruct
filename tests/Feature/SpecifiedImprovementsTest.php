<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PendingRegistration;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Skill;
use App\Models\Task;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\User;
use App\Services\ClientProjects;
use App\Services\SystemReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The behaviour the requested improvements added, gathered where the change
 * spans modules rather than sitting inside one of the existing suites.
 *
 * Four themes run through it:
 *
 *   - Registration asks for eleven digits and an accepted agreement.
 *   - A paused project takes no work at all: no team change, no report, no
 *     task edit, no new dates - and it says so rather than failing silently.
 *   - A lead's Reports tab is their own writing; a project's report log is the
 *     project's, whoever wrote it.
 *   - A record belongs to an account by its id. Changing an address moves the
 *     address and nothing else.
 */
class SpecifiedImprovementsTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function employee(string $role, string $email): User
    {
        return User::create([
            'user_code' => strtoupper(substr($role, 0, 3)).'-'.random_int(1000, 9999),
            'name' => ucfirst($role).' Person',
            'first_name' => ucfirst($role),
            'last_name' => 'Person',
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'test-password',
        ]);
    }

    private function technicianFor(User $account): Technician
    {
        return Technician::create(['account_id' => $account->id, 'role' => $account->role]);
    }

    private function project(string $status = 'ongoing', string $clientEmail = 'client@example.test'): Project
    {
        $project = Project::create([
            'name' => 'Some Project',
            'reference_no' => 'REF-'.random_int(1000, 9999),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 100000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Commercial',
            'company_name' => 'Some Holdings',
            'firstname' => 'Client',
            'surname' => 'One',
            'fullname' => 'Client One',
            'email_address' => $clientEmail,
            'contact_number' => '09123456789',
        ]);

        return $project;
    }

    private function assign(Project $project, Technician $technician): ProjectTechnician
    {
        return ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);
    }

    private function book(Project $project, int $fromOffset, int $toOffset): Schedule
    {
        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => Schedule::businessToday()->addDays($fromOffset)->toDateString().' 00:00:00',
            'end_datetime' => Schedule::businessToday()->addDays($toOffset)->toDateString().' 23:59:59',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'status' => 'scheduled',
        ]);

        ProjectTechnician::where('project_id', $project->project_id)
            ->pluck('project_technician_id')
            ->each(fn ($assignmentId) => ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $assignmentId,
            ]));

        return $schedule;
    }

    /**
     * @return array<string, mixed>
     */
    private function registration(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Jose Garcia',
            'email' => 'jose@example.test',
            'contact_number' => '09171234567',
            'birthdate' => '1990-05-04',
            'password' => 'my-own-password',
            'password_confirmation' => 'my-own-password',
            'terms' => '1',
        ], $overrides);
    }

    // ==================================================================
    // Registration
    // ==================================================================

    /**
     * A contact number is eleven digits and nothing else. Anything shorter,
     * longer, or carrying a space, a dash or a country code is refused, and
     * the message says what is wanted rather than "invalid".
     */
    public function test_registration_wants_exactly_eleven_digits(): void
    {
        foreach (['0917123456', '091712345678', '0917 123 4567', '+639171234567', 'oh nine one'] as $number) {
            $this->post(route('auth.register.store'), $this->registration([
                'contact_number' => $number,
                'email' => 'refused@example.test',
            ]))->assertSessionHasErrors('contact_number');

            $this->assertNull(User::where('email', 'refused@example.test')->first());
            $this->assertNull(PendingRegistration::where('email', 'refused@example.test')->first());
        }

        $this->post(route('auth.register.store'), $this->registration())
            ->assertRedirect(route('auth.verify'));

        // Held as a pending registration, not an account - see
        // UserAccountService::startRegistration().
        $this->assertSame(
            '09171234567',
            PendingRegistration::where('email', 'jose@example.test')->value('contact_number')
        );
    }

    /**
     * Nobody registers without accepting the terms, whatever the browser sent.
     */
    public function test_registration_refuses_an_unaccepted_agreement(): void
    {
        $payload = $this->registration();
        unset($payload['terms']);

        $this->post(route('auth.register.store'), $payload)
            ->assertSessionHasErrors('terms');

        $this->assertNull(User::where('email', 'jose@example.test')->first());
        $this->assertNull(PendingRegistration::where('email', 'jose@example.test')->first());
    }

    /**
     * The words are clickable and the terms themselves are on the page, in a
     * dialog rather than behind a link - so opening them cannot cost somebody
     * a half-filled form.
     */
    public function test_the_registration_page_carries_the_terms_in_a_dialog(): void
    {
        $response = $this->get(route('auth.register'))->assertOk();

        $response->assertSee('Terms and Conditions');
        $response->assertSee('data-bs-target="#termsModal"', false);
        $response->assertSee('id="termsModal"', false);
        // A checkbox that has to be ticked, and two columns of fields on a
        // desktop that fold back to one on a phone.
        $response->assertSee('name="terms"', false);
        $response->assertSee('col-md-6', false);
    }

    // ==================================================================
    // A paused project takes no work
    // ==================================================================

    public function test_a_held_project_refuses_a_change_of_team(): void
    {
        $this->actingAsSuperAdmin();

        $lead = $this->technicianFor($this->employee('lead_technician', 'lead@example.test'));
        $other = $this->technicianFor($this->employee('technician', 'other@example.test'));

        $project = $this->project();
        $this->assign($project, $lead);
        $this->book($project, -2, 2);

        $this->put(route('super-admin.projects.hold', $project->project_id));

        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [$other->technician_id],
        ])->assertSessionHas('error');

        // Nobody was added, and nobody was taken off either.
        $this->assertSame(
            [$lead->technician_id],
            ProjectTechnician::where('project_id', $project->project_id)
                ->pluck('technician_id')
                ->all()
        );
    }

    public function test_a_held_project_refuses_a_new_task_and_a_task_edit(): void
    {
        $this->actingAsSuperAdmin();

        $tech = $this->technicianFor($this->employee('technician', 'tech@example.test'));

        $project = $this->project();
        $this->assign($project, $tech);
        $this->book($project, -2, 2);

        $task = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $tech->technician_id,
            'task_title' => 'Pull the wiring',
            'task_description' => 'Description',
            'start_date' => Schedule::businessToday()->toDateString(),
            'due_date' => Schedule::businessToday()->addDay()->toDateString(),
            'status' => 'ongoing',
        ]);

        $this->put(route('super-admin.projects.hold', $project->project_id));

        $this->post(route('super-admin.task.store', $project->project_id), [
            'task_title' => 'Second fix',
            'task_description' => 'Description',
            'technician_id' => $tech->technician_id,
            'start_date' => Schedule::businessToday()->toDateString(),
            'due_date' => Schedule::businessToday()->toDateString(),
        ])->assertSessionHas('error');

        $this->assertSame(1, Task::where('project_id', $project->project_id)->count());

        $this->put(route('super-admin.tasks.update', $task->task_id), [
            'task_title' => 'Renamed',
            'task_description' => 'Description',
            'technician_id' => $tech->technician_id,
            'start_date' => Schedule::businessToday()->toDateString(),
            'due_date' => Schedule::businessToday()->toDateString(),
        ])->assertSessionHas('error');

        $this->assertSame('Pull the wiring', $task->refresh()->task_title);

        $this->delete(route('super-admin.tasks.destroy', $task->task_id))
            ->assertSessionHas('error');

        $this->assertSame(1, Task::where('project_id', $project->project_id)->count());
    }

    public function test_a_held_project_refuses_a_report(): void
    {
        $this->actingAsSuperAdmin();

        $tech = $this->technicianFor($this->employee('technician', 'tech@example.test'));

        $project = $this->project();
        $this->assign($project, $tech);
        $this->book($project, -2, 2);

        $this->put(route('super-admin.projects.hold', $project->project_id));

        $this->post(route('super-admin.technician.reports.store', $project->project_id), [
            'report_type' => 'progress',
            'report_title' => 'Day one',
            'report_description' => 'Everything went fine.',
        ])->assertSessionHas('error');

        $this->assertSame(0, TechnicianReport::where('project_id', $project->project_id)->count());
    }

    /**
     * The page says so rather than leaving somebody to press a button that
     * fails, and everything already on it is still readable.
     */
    public function test_project_details_says_a_held_project_must_be_resumed(): void
    {
        $this->actingAsSuperAdmin();

        $tech = $this->technicianFor($this->employee('technician', 'tech@example.test'));

        $project = $this->project();
        $this->assign($project, $tech);
        $this->book($project, -2, 2);
        $this->put(route('super-admin.projects.hold', $project->project_id));

        $response = $this->get(route('super-admin.projects.show', $project->project_id))->assertOk();

        $response->assertSee('This project is on hold');
        $response->assertSee('Resume it before changing its assigned technicians.');
        $response->assertSee('Resume it before adding reports.');
        $response->assertSee('Resume it before editing tasks.');
        $response->assertSee('Resume it before adding schedules.');

        // The history is still there to read - that is the whole point of
        // keeping it.
        $response->assertSee('Assigned Team');
        $response->assertSee('Project Schedule');
    }

    /**
     * A hold measures each task against the days it leaves the project
     * holding: a date still on a booked day survives, a date on a released
     * day is unassigned.
     */
    public function test_a_hold_unassigns_only_the_task_dates_it_stranded(): void
    {
        $this->actingAsSuperAdmin();

        $tech = $this->technicianFor($this->employee('technician', 'tech@example.test'));

        $project = $this->project();
        $this->assign($project, $tech);
        $this->book($project, -2, 5);

        $worked = Schedule::businessToday()->subDay()->toDateString();
        $released = Schedule::businessToday()->addDays(4)->toDateString();

        $kept = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $tech->technician_id,
            'task_title' => 'First fix',
            'task_description' => 'Description',
            'start_date' => $worked,
            'due_date' => $worked,
            'status' => 'ongoing',
        ]);

        $stranded = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $tech->technician_id,
            'task_title' => 'Second fix',
            'task_description' => 'Description',
            'start_date' => $released,
            'due_date' => $released,
            'status' => 'pending',
        ]);

        $this->put(route('super-admin.projects.hold', $project->project_id));

        // The booking was cut off at today.
        $this->assertSame(
            Schedule::businessToday()->toDateString(),
            Schedule::where('project_id', $project->project_id)->first()->endsOn()->toDateString()
        );

        // A day that was worked is still a day the project holds.
        $this->assertSame($worked, (string) $kept->refresh()->start_date);
        $this->assertSame($worked, (string) $kept->due_date);

        // A day the hold released is not, so the task goes back to
        // Unassigned - keeping its owner.
        $this->assertNull($stranded->refresh()->start_date);
        $this->assertNull($stranded->due_date);
        $this->assertSame($tech->technician_id, $stranded->technician_id);
    }

    // ==================================================================
    // Projects table
    // ==================================================================

    /**
     * A project whose crew includes an account that can no longer sign in is
     * flagged on the row, so nobody has to open every project to find them.
     */
    public function test_the_projects_table_flags_a_deactivated_technician(): void
    {
        $this->actingAsSuperAdmin();

        $account = $this->employee('technician', 'switched.off@example.test');
        $technician = $this->technicianFor($account);

        // The project is given a lead of its own, so the row is flagged for
        // the one reason this test is about. A project with no lead is flagged
        // too, and it would be flagged before the account was ever switched
        // off - see LeadTechnicianRoleChangeTest.
        $lead = $this->technicianFor($this->employee('lead_technician', 'working.lead@example.test'));

        $project = $this->project();
        $this->assign($project, $lead);
        $this->assign($project, $technician);
        $this->book($project, -2, 5);

        $this->get(route('super-admin.projects'))
            ->assertOk()
            ->assertDontSee('project-row-needs-recrew', false);

        $account->forceFill(['status' => User::STATUS_DEACTIVATED])->save();

        $response = $this->get(route('super-admin.projects'))->assertOk();

        $response->assertSee('project-row-needs-recrew', false);
        $response->assertSee('Inactive technician');

        // The warning inside Project Details is kept as well.
        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('This team needs attention.');
    }

    /**
     * Every status tab carries a count, and each project is counted under
     * exactly one of them.
     *
     * The attention tabs beside them are deliberately not part of that sum:
     * they ask what needs doing rather than what state work is in, and one
     * project can need booking and a crew both - see Project::ATTENTION_TABS.
     */
    public function test_every_projects_tab_carries_its_own_count(): void
    {
        $this->actingAsSuperAdmin();

        $this->book($this->project('ongoing'), -2, 5);
        $this->book($this->project('pending'), 3, 6);
        $this->book($this->project('ongoing'), -10, -5);
        $this->project('completed');
        $this->project('cancelled');

        $held = $this->project('ongoing');
        $this->book($held, -2, 2);
        $this->put(route('super-admin.projects.hold', $held->project_id));

        $tabs = collect($this->get(route('super-admin.projects'))->viewData('statusTabs'))
            ->keyBy('key');

        $this->assertSame(6, $tabs['all']['count']);
        $this->assertSame(1, $tabs['pending']['count']);
        $this->assertSame(1, $tabs['ongoing']['count']);
        $this->assertSame(1, $tabs['overdue']['count']);
        $this->assertSame(1, $tabs['on_hold']['count']);
        $this->assertSame(1, $tabs['completed']['count']);
        $this->assertSame(1, $tabs['cancelled']['count']);

        // Every status tab but All adds up to All, which is what "counted
        // once" means.
        $this->assertSame(
            $tabs['all']['count'],
            $tabs
                ->only(array_keys(Project::STATUS_TABS))
                ->reject(fn (array $tab): bool => $tab['key'] === 'all')
                ->sum('count')
        );
    }

    // ==================================================================
    // Reports
    // ==================================================================

    /**
     * A lead's Reports tab is what they wrote. A report an administrator filed
     * against them is not theirs, and does not appear there - but it is still
     * part of the project's own log.
     */
    public function test_a_lead_sees_only_their_own_reports_in_the_reports_tab(): void
    {
        $leadAccount = $this->employee('lead_technician', 'lead@example.test');
        $lead = $this->technicianFor($leadAccount);

        $project = $this->project();
        $this->assign($project, $lead);
        $this->book($project, -2, 5);

        // Filed by the lead.
        $mine = TechnicianReport::create([
            'project_id' => $project->project_id,
            'technician_id' => $lead->technician_id,
            'submitted_by' => $leadAccount->id,
            'report_type' => 'progress',
            'report_title' => 'Written by the lead',
            'report_description' => 'Description',
            'report_date' => now()->toDateString(),
        ]);

        // Filed by an administrator, credited to the lead because the project
        // has no other technician on it.
        $admin = $this->employee('admin', 'admin@example.test');
        $theirs = TechnicianReport::create([
            'project_id' => $project->project_id,
            'technician_id' => $lead->technician_id,
            'submitted_by' => $admin->id,
            'report_type' => 'incident',
            'report_title' => 'Written by an administrator',
            'report_description' => 'Description',
            'report_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($leadAccount)
            ->get(route('technician.reports'))
            ->assertOk();

        $ids = collect($response->viewData('reports'))->pluck('id')->all();

        $this->assertSame([$mine->id], $ids);

        // Project Details is the project's log, not the reader's: both are
        // there, whoever wrote them.
        $inProject = collect(
            $this->actingAs($leadAccount)
                ->get(route('technician.projects.show', $project->project_id))
                ->assertOk()
                ->viewData('reports')
        )->pluck('id')->sort()->values()->all();

        $this->assertSame(
            collect([$mine->id, $theirs->id])->sort()->values()->all(),
            $inProject
        );
    }

    /**
     * A report written before the submitting account was recorded still counts
     * as the technician's own - for those rows, they are who filed it.
     */
    public function test_a_report_with_no_recorded_submitter_stays_with_its_technician(): void
    {
        $leadAccount = $this->employee('lead_technician', 'lead@example.test');
        $lead = $this->technicianFor($leadAccount);

        $project = $this->project();
        $this->assign($project, $lead);

        $legacy = TechnicianReport::create([
            'project_id' => $project->project_id,
            'technician_id' => $lead->technician_id,
            'submitted_by' => null,
            'report_type' => 'progress',
            'report_title' => 'Filed before the column existed',
            'report_description' => 'Description',
            'report_date' => now()->toDateString(),
        ]);

        $ids = collect(
            $this->actingAs($leadAccount)->get(route('technician.reports'))->viewData('reports')
        )->pluck('id')->all();

        $this->assertSame([$legacy->id], $ids);
    }

    /**
     * The Project Report names the day each project was opened, from the
     * project's own timestamp and in the report's own date format.
     */
    public function test_the_project_report_carries_the_day_the_project_was_created(): void
    {
        $project = $this->project('ongoing');
        $project->forceFill(['created_at' => CarbonImmutable::parse('2026-03-04 09:00:00')])->save();

        $report = app(SystemReportService::class)->exportReport(
            'project',
            [
                'start' => CarbonImmutable::parse('2026-01-01')->startOfDay(),
                'end' => CarbonImmutable::parse('2026-12-31')->endOfDay(),
            ]
        );

        $row = $report['sections'][0]['rows']->first();

        $this->assertSame('Mar 4, 2026', $row['created_on']);
    }

    // ==================================================================
    // The calendar keeps a held project's history
    // ==================================================================

    /**
     * A hold releases the dates still to come and keeps the days already
     * worked. Those days stay on the calendar - that is the record of what
     * happened - and the released ones are not brought back by drawing it.
     */
    public function test_a_held_project_shows_its_past_dates_and_not_its_released_ones(): void
    {
        $this->actingAsSuperAdmin();

        $tech = $this->technicianFor($this->employee('technician', 'tech@example.test'));

        $project = $this->project();
        $this->assign($project, $tech);
        $this->book($project, -6, -3);
        $this->book($project, 3, 6);

        $this->put(route('super-admin.projects.hold', $project->project_id));

        $events = collect(
            $this->get(route('super-admin.schedules.index'))->viewData('calendarEvents')
        )->where('extendedProps.projectId', $project->project_id)->values();

        // One booking left: the days that were worked. The future one was
        // released by the hold and is not redrawn.
        $this->assertCount(1, $events);
        $this->assertSame('On Hold', $events->first()['extendedProps']['statusLabel']);
        $this->assertTrue($events->first()['extendedProps']['readOnly']);
        $this->assertSame(
            Schedule::businessToday()->subDays(6)->toDateString(),
            CarbonImmutable::parse($events->first()['start'])->toDateString()
        );
    }

    // ==================================================================
    // Records belong to an account, never to an address
    // ==================================================================

    /**
     * Changing a technician's email address changes their email address. It
     * does not create a second technician, and it does not move a single
     * project, schedule, task, report or availability row off them.
     */
    public function test_changing_a_technician_email_keeps_every_record_on_the_same_account(): void
    {
        $this->actingAsSuperAdmin();

        $account = $this->employee('technician', 'old.address@example.test');
        $technician = $this->technicianFor($account);
        $technician->skills()->sync([Skill::create(['skill_name' => 'Aircon Repair'])->skill_id]);

        $project = $this->project();
        $assignment = $this->assign($project, $technician);
        $schedule = $this->book($project, -2, 5);

        $task = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
            'task_title' => 'Pull the wiring',
            'task_description' => 'Description',
            'start_date' => Schedule::businessToday()->toDateString(),
            'due_date' => Schedule::businessToday()->addDay()->toDateString(),
            'status' => 'ongoing',
        ]);

        $report = TechnicianReport::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
            'submitted_by' => $account->id,
            'report_type' => 'progress',
            'report_title' => 'Day one',
            'report_description' => 'Description',
            'report_date' => now()->toDateString(),
        ]);

        $technicianCountBefore = Technician::count();

        $this->post(route('super-admin.configuration.users.employees.update', $account->id), [
            'first_name' => $account->first_name,
            'last_name' => $account->last_name,
            'contact_number' => '09171234567',
            'email' => 'new.address@example.test',
            'role' => 'technician',
            'skill_ids' => $technician->skills()->pluck('tbl_skills.skill_id')->all(),
        ])->assertOk();

        $account->refresh();

        $this->assertSame('new.address@example.test', $account->email);
        // One account, one technician record - not a second of either.
        $this->assertSame($technicianCountBefore, Technician::count());
        $this->assertSame($technician->technician_id, $account->refresh()->technicianId());

        // Every record still points at the same technician id it always did.
        $this->assertDatabaseHas('tbl_project_technicians', [
            'project_technician_id' => $assignment->project_technician_id,
            'technician_id' => $technician->technician_id,
            'project_id' => $project->project_id,
        ]);
        $this->assertDatabaseHas('tbl_schedule_technicians', [
            'schedule_id' => $schedule->schedule_id,
            'project_technician_id' => $assignment->project_technician_id,
        ]);
        $this->assertSame($technician->technician_id, $task->refresh()->technician_id);
        $this->assertSame($technician->technician_id, $report->refresh()->technician_id);
        $this->assertSame(['Aircon Repair'], $technician->refresh()->skill_names);

        // And the project is still theirs to open.
        $this->actingAs($account)
            ->get(route('technician.projects.show', $project->project_id))
            ->assertOk();
    }

    /**
     * The same for a client. Their work is held by the account id; the address
     * on the project contact is moved with them so outgoing mail still reaches
     * them, and nothing is reassigned.
     */
    public function test_a_client_keeps_their_projects_when_their_address_changes(): void
    {
        $client = User::create([
            'user_code' => 'CLI-0001',
            'name' => 'Client One',
            'first_name' => 'Client',
            'last_name' => 'One',
            'email' => 'client@example.test',
            'role' => User::ROLE_CLIENT,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'password' => 'test-password',
        ]);

        $project = $this->project('ongoing', 'client@example.test');

        Client::where('project_id', $project->project_id)->update(['user_id' => $client->id]);

        $this->assertSame(
            [$project->project_id],
            app(ClientProjects::class)->forUser($client)->pluck('project_id')->all()
        );

        // The address moves; the link does not depend on it.
        $client->forceFill(['email' => 'moved@example.test'])->save();

        $this->assertSame(
            [$project->project_id],
            app(ClientProjects::class)->forUser($client->refresh())->pluck('project_id')->all()
        );

        $this->actingAs($client)
            ->get(route('public.projects.show', $project->project_id))
            ->assertOk();
    }

    // ==================================================================
    // Profile
    // ==================================================================

    /**
     * Specialties read as a list with an Edit button, and the editor is a
     * dialog offering the approved specialty catalogue. Submitting it still
     * asks an administrator rather than changing anything.
     */
    public function test_the_profile_edits_specialties_in_a_dialog(): void
    {
        $account = $this->employee('technician', 'tech@example.test');
        $technician = $this->technicianFor($account);

        $aircon = Skill::create(['skill_name' => 'Aircon Repair']);
        $wiring = Skill::create(['skill_name' => 'Electrical Wiring']);
        $technician->skills()->sync([$aircon->skill_id]);

        $response = $this->actingAs($account)->get(route('profile.edit'))->assertOk();

        $response->assertSee('Approved Specialties');
        $response->assertSee('Aircon Repair');
        $response->assertSee('data-bs-target="#specialtiesModal"', false);
        $response->assertSee('id="specialtiesModal"', false);
        // Every specialty in the catalogue is selectable inside the dialog.
        $response->assertSee('value="'.$wiring->skill_id.'"', false);

        // Saving goes through the existing approval workflow: nothing changes
        // until an administrator decides.
        $this->actingAs($account)
            ->post(route('profile.specialties.request'), [
                'skill_ids' => [$aircon->skill_id, $wiring->skill_id],
            ])
            ->assertSessionHas('success');

        $this->assertSame(['Aircon Repair'], $technician->refresh()->skill_names);
        $this->assertDatabaseHas('tbl_specialty_requests', [
            'technician_id' => $technician->technician_id,
            'status' => 'pending',
        ]);

        // With a decision outstanding the dialog is closed off rather than
        // offering a second proposal.
        $this->actingAs($account)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Pending Approval');
    }

    /**
     * Every password field on the profile page carries the show/hide eye, and
     * the script that drives it is on the page.
     */
    public function test_the_profile_password_fields_carry_a_show_hide_eye(): void
    {
        $account = $this->employee('technician', 'tech@example.test');
        $this->technicianFor($account);

        $response = $this->actingAs($account)->get(route('profile.edit'))->assertOk();

        $this->assertSame(
            3,
            substr_count($response->getContent(), 'data-password-field')
        );
        $this->assertSame(
            3,
            substr_count($response->getContent(), 'data-password-toggle')
        );
        $response->assertSee('/js/passwordField.js', false);
    }
}
