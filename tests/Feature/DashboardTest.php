<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\SpecialtyRequest;
use App\Models\Technician;
use App\Models\User;
use App\Services\DashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Admin and Super Admin dashboard.
 *
 * The figures matter more than the layout: a dashboard that double-counts an
 * overdue project, or shows an Admin the archive, is worse than no dashboard.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role, string $email): User
    {
        return User::create([
            'user_code' => strtoupper(substr($role, 0, 3)).'-'.random_int(1000, 9999),
            'name' => ucfirst($role).' Person',
            'first_name' => ucfirst($role),
            'last_name' => 'Person',
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'is_archived' => false,
            'must_change_password' => false,
            'password' => 'password',
        ]);
    }

    /**
     * @param  array{start?: string, end?: string}  $schedule
     */
    private function project(string $status, array $schedule = [], array $attributes = []): Project
    {
        $project = Project::create(array_merge([
            'reference_no' => 'PRJ-'.random_int(1000, 9999),
            'name' => 'Dela Cruz',
            'status' => $status,
            'quotation' => 1000,
            'address' => '12 Mabini Street',
            'description' => 'Work',
        ], $attributes));

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Residential',
            'firstname' => 'Juan',
            'surname' => 'Dela Cruz',
            'fullname' => 'Juan Dela Cruz',
            'email_address' => 'client@example.test',
            'contact_number' => '09171234567',
        ]);

        if ($schedule !== []) {
            Schedule::create([
                'project_id' => $project->project_id,
                'start_datetime' => $schedule['start'],
                'end_datetime' => $schedule['end'],
                'status' => 'scheduled',
            ]);
        }

        return $project->refresh();
    }

    // ------------------------------------------------------------------
    // The page
    // ------------------------------------------------------------------

    public function test_the_dashboard_greets_the_reader_and_shows_its_sections(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');

        // Four sections and nothing else: the figures, the work that is
        // coming, the ring, who is carrying it, and what just happened.
        $this->actingAs($owner)
            ->get(route('super-admin.dashboard'))
            ->assertOk()
            ->assertSee('Welcome back, Super_admin')
            ->assertSee('Super Admin')
            ->assertSee(now()->format('l, F j, Y'))
            ->assertSee('New Project')
            ->assertSee('Upcoming Work')
            ->assertSee('Project Status')
            ->assertSee('Technician Workload')
            ->assertSee('Recent Activity');
    }

    public function test_a_technician_cannot_reach_the_dashboard(): void
    {
        $tech = $this->account('technician', 'tech@example.test');

        $this->actingAs($tech)->get(route('super-admin.dashboard'))->assertRedirect();
        $this->actingAs($tech)->getJson(route('super-admin.dashboard.summary'))->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Summary figures
    // ------------------------------------------------------------------

    public function test_the_summary_counts_each_status_once(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');

        $this->project('ongoing', ['start' => now()->subDays(3), 'end' => now()->addDays(3)]);
        $this->project('pending', ['start' => now()->addDays(5), 'end' => now()->addDays(9)]);
        $this->project('completed');
        $this->project('cancelled');

        // Its last scheduled day has passed but it is still open: overdue, and
        // it must not also be counted as ongoing.
        $this->project('ongoing', ['start' => now()->subDays(20), 'end' => now()->subDays(5)]);

        $cards = collect(app(DashboardMetrics::class)->summary($owner))->keyBy('key');

        $this->assertSame(5, $cards['total_projects']['value']);
        $this->assertSame(1, $cards['ongoing']['value']);
        $this->assertSame(1, $cards['pending']['value']);
        $this->assertSame(1, $cards['overdue']['value']);
        $this->assertSame(1, $cards['completed']['value']);
    }

    public function test_an_admin_is_not_given_the_archive_figures(): void
    {
        $admin = $this->account('admin', 'admin@example.test');
        $owner = $this->account('super_admin', 'owner@example.test');

        $archived = $this->project('archived');
        $archived->update(['is_archived' => true, 'archived_at' => now()]);

        $metrics = app(DashboardMetrics::class);

        $adminKeys = collect($metrics->summary($admin))->pluck('key');
        $ownerKeys = collect($metrics->summary($owner))->pluck('key');

        foreach (['archived_projects', 'archived_accounts'] as $key) {
            $this->assertFalse($adminKeys->contains($key), $key.' should not reach an Admin.');
            $this->assertTrue($ownerKeys->contains($key), $key.' should reach a Super Admin.');
        }
    }

    public function test_the_specialty_card_appears_only_when_something_is_waiting(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');
        $metrics = app(DashboardMetrics::class);

        $this->assertFalse(
            collect($metrics->summary($owner))->pluck('key')->contains('specialty_requests')
        );

        $tech = $this->account('technician', 'tech@example.test');
        $technician = Technician::create(['account_id' => $tech->id, 'role' => 'technician']);

        SpecialtyRequest::create([
            'technician_id' => $technician->technician_id,
            'status' => SpecialtyRequest::STATUS_PENDING,
            'requested_skill_ids' => [],
            'current_skill_ids' => [],
            'requested_by' => $tech->id,
        ]);

        $this->assertTrue(
            collect($metrics->summary($owner))->pluck('key')->contains('specialty_requests')
        );
    }

    /**
     * The figures are cached; a write has to clear that cache or the dashboard
     * reports yesterday's numbers.
     */
    public function test_creating_a_project_updates_the_figures_at_once(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');
        $metrics = app(DashboardMetrics::class);

        $before = collect($metrics->summary($owner))->keyBy('key')['total_projects']['value'];

        $this->project('pending');

        $after = collect($metrics->summary($owner))->keyBy('key')['total_projects']['value'];

        $this->assertSame($before + 1, $after);
    }

    // ------------------------------------------------------------------
    // The ring
    // ------------------------------------------------------------------

    /**
     * The ring's numbers are rendered as text, so they are readable before any
     * script runs - and a status nobody is in does not get a legend row.
     */
    public function test_the_status_breakdown_is_a_share_of_the_whole(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');

        $this->project('ongoing', ['start' => now()->subDay(), 'end' => now()->addDay()]);
        $this->project('completed');
        $this->project('completed');
        $this->project('completed');

        $breakdown = collect(app(DashboardMetrics::class)->statusBreakdown())->keyBy('key');

        $this->assertSame(75, $breakdown['completed']['percent']);
        $this->assertSame(25, $breakdown['ongoing']['percent']);
        $this->assertFalse($breakdown->has('cancelled'));

        $this->actingAs($owner)
            ->get(route('super-admin.dashboard'))
            ->assertOk()
            ->assertSee('75%')
            ->assertSee('25%');
    }

    public function test_workload_counts_ongoing_projects_busiest_first(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');

        $busyAccount = $this->account('technician', 'busy@example.test');
        $busy = Technician::create(['account_id' => $busyAccount->id, 'role' => 'technician']);

        $freeAccount = $this->account('technician', 'free@example.test');
        Technician::create(['account_id' => $freeAccount->id, 'role' => 'technician']);

        $project = $this->project('ongoing', ['start' => now(), 'end' => now()->addDay()]);
        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $busy->technician_id,
        ]);

        $workload = app(DashboardMetrics::class)->technicianWorkload();

        $this->assertSame($busyAccount->fullName(), $workload->first()['name']);
        $this->assertSame(1, $workload->first()['value']);
        // Their picture rides along, since the list shows faces.
        $this->assertSame(asset('img/default-avatar.svg'), $workload->first()['avatar_url']);

        $this->actingAs($owner)
            ->get(route('super-admin.dashboard'))
            ->assertOk()
            ->assertSee($busyAccount->fullName());
    }

    // ------------------------------------------------------------------
    // Lists
    // ------------------------------------------------------------------

    public function test_the_schedule_lists_only_work_that_is_still_to_come(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');

        $soon = $this->project('pending', ['start' => now()->addDays(2), 'end' => now()->addDays(4)]);
        $done = $this->project('completed', ['start' => now()->addDays(1), 'end' => now()->addDays(3)]);
        $past = $this->project('ongoing', ['start' => now()->subDays(9), 'end' => now()->subDays(4)]);

        $rows = app(DashboardMetrics::class)->upcomingSchedule();
        $ids = $rows->pluck('project_id');

        $this->assertTrue($ids->contains($soon->project_id));
        // A finished project is not upcoming, and neither is a past range.
        $this->assertFalse($ids->contains($done->project_id));
        $this->assertFalse($ids->contains($past->project_id));
    }

    public function test_an_admin_does_not_see_another_administrators_activity(): void
    {
        $admin = $this->account('admin', 'admin@example.test');
        $owner = $this->account('super_admin', 'owner@example.test');

        // Something a Super Admin did: visible to them, not to an Admin.
        $this->actingAs($owner)->post(route('super-admin.projects.create'), []);

        $this->actingAs($admin)
            ->get(route('super-admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Super_admin Person');
    }
}
