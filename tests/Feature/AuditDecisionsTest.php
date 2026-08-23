<?php

namespace Tests\Feature;

use App\Mail\OtpCodeMail;
use App\Models\Client;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Technician;
use App\Models\User;
use App\Services\ClientProjects;
use App\Services\ProfileService;
use App\Services\SystemReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The four audit findings whose fix was a business decision rather than a
 * correction, each pinned to the rule that was chosen.
 */
class AuditDecisionsTest extends TestCase
{
    use RefreshDatabase;

    private function technician(string $name, string $role = 'technician'): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => mb_strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => $role, 'status' => User::STATUS_ACTIVE])->save();

        return Technician::create(['account_id' => $user->id, 'role' => $role]);
    }

    private function project(array $technicians = [], string $status = 'ongoing', string $email = 'owner@example.test'): Project
    {
        $project = Project::create([
            'name' => 'Decision Project '.uniqid(),
            'reference_no' => 'REF-'.strtoupper(uniqid()),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 1000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Commercial',
            'company_name' => 'Decision Holdings',
            'firstname' => 'Owner',
            'surname' => 'Person',
            'fullname' => 'Owner Person',
            'email_address' => $email,
            'contact_number' => '09123456789',
        ]);

        foreach ($technicians as $technician) {
            ProjectTechnician::create([
                'project_id' => $project->project_id,
                'technician_id' => $technician->technician_id,
            ]);
        }

        return $project->fresh();
    }

    private function book(Project $project, int $from, int $to): Schedule
    {
        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => Schedule::businessToday()->addDays($from)->startOfDay(),
            'end_datetime' => Schedule::businessToday()->addDays($to)->endOfDay(),
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

    /**
     * @param  array<int, array<string, mixed>>  $ranges
     */
    private function saveSchedule(Project $project, array $ranges)
    {
        return $this->put(route('super-admin.schedules.update', $project->project_id), ['ranges' => $ranges]);
    }

    // ==================================================================
    // BUG-015 - a saved booking may not be moved into the past
    // ==================================================================

    public function test_a_future_booking_cannot_be_dragged_backwards(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([$this->technician('Drag Person')]);
        $schedule = $this->book($project, 2, 4);

        $this->saveSchedule($project, [[
            'schedule_id' => $schedule->schedule_id,
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => '2020-01-01',
            'end_date' => '2020-01-05',
        ]]);

        $stored = Schedule::find($schedule->schedule_id);

        $this->assertSame(
            Schedule::businessToday()->addDays(2)->toDateString(),
            $stored->startsOn()->toDateString(),
            'The booking stayed where it was.'
        );
        $this->assertStringContainsString('Choose a start date of today or later.', (string) session('error'));
    }

    /**
     * The exemption that has to survive: a row already in the past is
     * resubmitted untouched every time another row on the same project is
     * edited, and refusing it would make the form unsaveable.
     */
    public function test_a_booking_already_in_the_past_may_be_left_where_it_is(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([$this->technician('Untouched Person')]);
        $past = $this->book($project, -8, -6);
        $future = $this->book($project, 3, 5);

        $this->saveSchedule($project, [
            [
                'schedule_id' => $past->schedule_id,
                'scheduling_mode' => Schedule::MODE_DATE_BASED,
                'start_date' => $past->startsOn()->toDateString(),
                'end_date' => $past->endsOn()->toDateString(),
            ],
            [
                'schedule_id' => $future->schedule_id,
                'scheduling_mode' => Schedule::MODE_DATE_BASED,
                'start_date' => Schedule::businessToday()->addDays(4)->toDateString(),
                'end_date' => Schedule::businessToday()->addDays(6)->toDateString(),
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Schedule::where('project_id', $project->project_id)->count());
        $this->assertSame(
            Schedule::businessToday()->addDays(4)->toDateString(),
            Schedule::find($future->schedule_id)->startsOn()->toDateString()
        );
    }

    // ==================================================================
    // BUG-016 - open work belongs to every period it was open in
    // ==================================================================

    public function test_unbooked_work_appears_in_every_period_it_was_open_in(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([], 'unscheduled');
        $project->forceFill(['created_at' => CarbonImmutable::parse('2026-05-04')])->save();

        $reports = app(SystemReportService::class);

        $may = $reports->exportReport('project', $reports->resolveExportPeriod('monthly', 5, 2026));
        $august = $reports->exportReport('project', $reports->resolveExportPeriod('monthly', 8, 2026));
        $april = $reports->exportReport('project', $reports->resolveExportPeriod('monthly', 4, 2026));

        $this->assertCount(1, $may['sections'][0]['rows'], 'The month it was opened.');
        $this->assertCount(1, $august['sections'][0]['rows'], 'Still open, so still carried.');
        $this->assertCount(0, $april['sections'][0]['rows'], 'It did not exist yet.');
    }

    public function test_work_closed_before_the_period_is_not_carried_into_it(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([], 'completed');
        $project->forceFill([
            'created_at' => CarbonImmutable::parse('2026-05-04'),
            'completed_at' => CarbonImmutable::parse('2026-05-20'),
        ])->save();

        $reports = app(SystemReportService::class);

        $this->assertCount(
            1,
            $reports->exportReport('project', $reports->resolveExportPeriod('monthly', 5, 2026))['sections'][0]['rows']
        );
        $this->assertCount(
            0,
            $reports->exportReport('project', $reports->resolveExportPeriod('monthly', 8, 2026))['sections'][0]['rows']
        );
    }

    public function test_the_new_projects_report_counts_what_arrived_that_month(): void
    {
        $this->actingAsSuperAdmin();

        $opened = $this->project([], 'unscheduled');
        $opened->forceFill(['created_at' => CarbonImmutable::parse('2026-08-06')])->save();

        $carriedOver = $this->project([], 'unscheduled');
        $carriedOver->forceFill(['created_at' => CarbonImmutable::parse('2026-05-04')])->save();

        $reports = app(SystemReportService::class);
        $august = $reports->resolveExportPeriod('monthly', 8, 2026);

        $intake = $reports->exportReport('new_projects', $august)['sections'][0];

        // Only the one that arrived in August, not the one being carried.
        $this->assertCount(1, $intake['rows']);
        $this->assertSame($opened->reference_no, $intake['rows'][0]['reference_no']);
        $this->assertSame('Projects Opened', $intake['title']);

        // While the Project Report, asking a different question, carries both.
        $this->assertCount(2, $reports->exportReport('project', $august)['sections'][0]['rows']);
    }

    public function test_the_new_projects_report_is_offered_and_exports(): void
    {
        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.reports.index'))
            ->assertOk()
            ->assertSee('New Projects Report');

        $this->post(route('super-admin.reports.export'), [
            'report_type' => 'new_projects',
            'period' => 'monthly',
            'month' => (int) CarbonImmutable::today()->format('n'),
            'year' => (int) CarbonImmutable::today()->format('Y'),
        ])->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    // ==================================================================
    // BUG-018 - projects belong to the account, not to the address
    // ==================================================================

    public function test_a_client_keeps_their_projects_after_changing_their_email(): void
    {
        $client = User::factory()->create(['name' => 'Moving Client', 'email' => 'before@example.test']);
        $client->forceFill(['role' => User::ROLE_CLIENT, 'status' => User::STATUS_ACTIVE])->save();

        $project = $this->project([], 'ongoing', 'before@example.test');
        Client::where('project_id', $project->project_id)->update(['user_id' => $client->id]);

        $projects = app(ClientProjects::class);
        $this->assertCount(1, $projects->forUser($client->fresh()));

        // The address moves; the work does not go anywhere.
        app(ProfileService::class);
        $client->forceFill(['email' => 'after@example.test'])->save();
        Client::where('user_id', $client->id)->update(['email_address' => 'after@example.test']);

        $this->assertCount(1, $projects->forUser($client->fresh()));
        $this->assertSame(
            'after@example.test',
            Client::where('project_id', $project->project_id)->value('email_address'),
            'The project contact moved with them.'
        );
    }

    /**
     * The link is what holds the work, so it holds it even before the contact
     * row's address catches up.
     */
    public function test_the_account_link_outranks_the_stored_address(): void
    {
        $client = User::factory()->create(['name' => 'Linked Client', 'email' => 'new@example.test']);
        $client->forceFill(['role' => User::ROLE_CLIENT, 'status' => User::STATUS_ACTIVE])->save();

        $project = $this->project([], 'ongoing', 'stale@example.test');
        Client::where('project_id', $project->project_id)->update(['user_id' => $client->id]);

        $this->assertCount(1, app(ClientProjects::class)->forUser($client->fresh()));
    }

    /**
     * And the fallback that must survive: work booked for somebody who has not
     * registered yet is theirs the moment they do.
     */
    public function test_registering_claims_work_already_booked_under_that_address(): void
    {
        $project = $this->project([], 'ongoing', 'newcomer@example.test');

        $this->assertNull(Client::where('project_id', $project->project_id)->value('user_id'));

        Mail::fake();

        $this->post(route('auth.register.store'), [
            'full_name' => 'New Comer',
            'contact_number' => '09123456789',
            'birthdate' => '1990-01-01',
            'email' => 'newcomer@example.test',
            'password' => 'a-good-password',
            'password_confirmation' => 'a-good-password',
            'terms' => '1',
        ]);

        // The account - and so the claim - is made by the code, not the form.
        $code = null;
        Mail::assertSent(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        $this->post(route('auth.verify.store'), ['code' => (string) $code]);

        $client = User::where('email', 'newcomer@example.test')->first();

        $this->assertNotNull($client);
        $this->assertSame(
            $client->id,
            (int) Client::where('project_id', $project->project_id)->value('user_id'),
            'Registering claimed the project booked under their address.'
        );
    }

    public function test_a_client_still_cannot_reach_somebody_elses_project(): void
    {
        $mine = User::factory()->create(['name' => 'Mine', 'email' => 'mine@example.test']);
        $mine->forceFill(['role' => User::ROLE_CLIENT, 'status' => User::STATUS_ACTIVE])->save();

        $theirs = User::factory()->create(['name' => 'Theirs', 'email' => 'theirs@example.test']);
        $theirs->forceFill(['role' => User::ROLE_CLIENT, 'status' => User::STATUS_ACTIVE])->save();

        $project = $this->project([], 'ongoing', 'theirs@example.test');
        Client::where('project_id', $project->project_id)->update(['user_id' => $theirs->id]);

        $this->assertCount(0, app(ClientProjects::class)->forUser($mine));
        $this->assertNull(app(ClientProjects::class)->findForUser($mine, $project->project_id));

        $this->actingAs($mine)
            ->get('/my-projects/'.$project->project_id)
            ->assertNotFound();
    }

    // ==================================================================
    // BUG-019 - bookings are kept, and the project says so
    // ==================================================================

    public function test_deactivating_a_technician_keeps_their_bookings_and_flags_the_project(): void
    {
        $this->actingAsSuperAdmin();

        $technician = $this->technician('Switched Off');
        $project = $this->project([$technician]);
        $schedule = $this->book($project, 1, 3);

        $this->putJson(route('super-admin.configuration.users.status', $technician->account), [
            'status' => User::STATUS_DEACTIVATED,
        ])->assertOk();

        // Nothing was released.
        $this->assertDatabaseHas('tbl_schedule', ['schedule_id' => $schedule->schedule_id]);
        $this->assertDatabaseHas('tbl_project_technicians', [
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);

        // And the project says who can no longer work it.
        $flagged = Project::with('projectTechnicians.technician.account')->find($project->project_id);

        $this->assertTrue($flagged->needsRecrew());
        $this->assertSame('Switched Off', $flagged->inactiveCrew()->first()->technician->name);
    }

    public function test_the_administrators_are_told_which_work_was_left_behind(): void
    {
        $this->actingAsSuperAdmin();

        $other = User::create([
            'user_code' => 'EMP-0600', 'name' => 'Other Admin', 'first_name' => 'Other',
            'last_name' => 'Admin', 'email' => 'other.admin@example.test', 'role' => 'admin',
            'status' => User::STATUS_ACTIVE, 'password' => 'secret-password',
        ]);

        $technician = $this->technician('Switched Off');
        $project = $this->project([$technician]);
        $this->book($project, 1, 3);

        $this->putJson(route('super-admin.configuration.users.status', $technician->account), [
            'status' => User::STATUS_DEACTIVATED,
        ])->assertOk();

        $notice = Notification::forUser($other)
            ->where('title', 'Deactivated Technician Still Assigned')
            ->first();

        $this->assertNotNull($notice);
        $this->assertStringContainsString($project->reference_no, (string) $notice->message);
    }

    public function test_the_project_page_shows_the_recrew_warning(): void
    {
        $this->actingAsSuperAdmin();

        $lead = $this->technician('Working Lead', 'lead_technician');
        $technician = $this->technician('Switched Off');
        $project = $this->project([$lead, $technician]);
        $this->book($project, 1, 3);

        $technician->account->forceFill(['status' => User::STATUS_DEACTIVATED])->save();

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('This team needs attention')
            ->assertSee('Account inactive');
    }

    /**
     * The flag corrects itself - it is derived, not stored.
     */
    public function test_the_warning_clears_when_the_account_comes_back(): void
    {
        $this->actingAsSuperAdmin();

        // Given a lead of its own, so this is measuring the one thing it means
        // to measure. A project with no lead needs re-crewing for that reason
        // alone, and the flag would never clear however the crew's accounts
        // came and went - see LeadTechnicianRoleChangeTest.
        $lead = $this->technician('Working Lead', 'lead_technician');
        $technician = $this->technician('Switched Off');
        $project = $this->project([$lead, $technician]);
        $this->book($project, 1, 3);

        $technician->account->forceFill(['status' => User::STATUS_DEACTIVATED])->save();
        $this->assertTrue(Project::find($project->project_id)->needsRecrew());

        $technician->account->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $this->assertFalse(Project::find($project->project_id)->needsRecrew());
    }
}
