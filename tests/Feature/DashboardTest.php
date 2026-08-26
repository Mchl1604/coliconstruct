<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\SpecialtyRequest;
use App\Models\Technician;
use App\Models\User;
use App\Services\DashboardMetrics;
use App\Support\BusinessTime;
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
            ->assertSee(now()->format('l, '.BusinessTime::DATE))
            ->assertSee('New Project')
            ->assertSee('Upcoming Work')
            ->assertSee('Urgent Actions')
            ->assertSee('Active Technicians Today')
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

        $cards = collect(app(DashboardMetrics::class)->summary())->keyBy('key');

        $this->assertSame(5, $cards['total_projects']['value']);
        $this->assertSame(1, $cards['ongoing']['value']);
        $this->assertSame(1, $cards['pending']['value']);
        $this->assertSame(1, $cards['overdue']['value']);
        $this->assertSame(1, $cards['completed']['value']);
    }

    /**
     * The strip is project figures only: headcounts and the archive were
     * taken off it, since neither is something anyone checks daily.
     */
    public function test_the_summary_is_project_figures_only(): void
    {
        $keys = collect(app(DashboardMetrics::class)->summary())->pluck('key');

        foreach (['total_projects', 'active_today', 'ongoing', 'pending', 'overdue', 'completed'] as $key) {
            $this->assertTrue($keys->contains($key), $key.' should be on the strip.');
        }

        foreach (['clients', 'technicians', 'archived_projects', 'archived_accounts'] as $key) {
            $this->assertFalse($keys->contains($key), $key.' should be off the strip.');
        }
    }

    /**
     * Active Today is the work with a crew on it right now: an open project
     * whose booked range covers today.
     */
    public function test_active_today_counts_work_scheduled_across_today(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');

        // Running today, both a range that started earlier and one starting
        // today.
        $this->project('ongoing', ['start' => now()->subDays(2), 'end' => now()->addDays(2)]);
        $this->project('ongoing', ['start' => now(), 'end' => now()->addDay()]);

        // Not today: one still to come, one already finished.
        $this->project('pending', ['start' => now()->addDays(5), 'end' => now()->addDays(8)]);
        $this->project('ongoing', ['start' => now()->subDays(9), 'end' => now()->subDays(4)]);

        // Closed work is never "active", even if its dates cover today.
        $this->project('completed', ['start' => now()->subDay(), 'end' => now()->addDay()]);

        $cards = collect(app(DashboardMetrics::class)->summary())->keyBy('key');

        $this->assertSame(2, $cards['active_today']['value']);

        $this->actingAs($owner)
            ->get(route('super-admin.dashboard'))
            ->assertOk()
            ->assertSee('Active Today');
    }

    public function test_the_specialty_card_appears_only_when_something_is_waiting(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');
        $metrics = app(DashboardMetrics::class);

        $this->assertFalse(
            collect($metrics->summary())->pluck('key')->contains('specialty_requests')
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
            collect($metrics->summary())->pluck('key')->contains('specialty_requests')
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

        $before = collect($metrics->summary())->keyBy('key')['total_projects']['value'];

        $this->project('pending');

        $after = collect($metrics->summary())->keyBy('key')['total_projects']['value'];

        $this->assertSame($before + 1, $after);
    }

    // ------------------------------------------------------------------
    // Urgent Actions
    // ------------------------------------------------------------------

    /**
     * Nothing to do means nothing listed. The section is not a menu, so with
     * every backlog clear it says so rather than drawing six zeroes.
     */
    public function test_urgent_actions_lists_nothing_when_nothing_needs_attention(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');

        $this->actingAs($owner)
            ->get(route('super-admin.dashboard'))
            ->assertOk()
            ->assertSee('Urgent Actions')
            ->assertSee('Nothing needs attention right now.')
            ->assertViewHas('urgentActions', []);
    }

    /**
     * Each entry says how many and opens the page already filtered to them.
     * Both administrative roles see the same list: every figure here is about
     * work, which neither role is kept out of.
     */
    public function test_urgent_actions_count_the_work_waiting_and_link_to_it(): void
    {
        // Booked, so neither overdue nor unscheduled - but nobody is on it,
        // which is the one thing wrong with it.
        $this->project('ongoing', ['start' => now()->subDay(), 'end' => now()->addDay()]);

        // No dates at all.
        $this->project('unscheduled');

        // Last day gone by, still open.
        $this->project('ongoing', ['start' => now()->subDays(9), 'end' => now()->subDays(2)]);

        foreach (['super_admin', 'admin'] as $role) {
            $actions = collect(
                $this->actingAs($this->account($role, $role.'@example.test'))
                    ->get(route('super-admin.dashboard'))
                    ->assertOk()
                    ->viewData('urgentActions')
            )->keyBy('key');

            $this->assertSame('1 Unscheduled Project', $actions['unscheduled_projects']['label']);
            $this->assertSame(
                route('super-admin.projects').'?status=unscheduled',
                $actions['unscheduled_projects']['url']
            );

            $this->assertSame('1 Overdue Project', $actions['overdue_projects']['label']);
            $this->assertSame(
                route('super-admin.projects').'?status=overdue',
                $actions['overdue_projects']['url']
            );

            // All three carry no crew, the unscheduled one included.
            $this->assertSame(
                '3 Projects Without Technicians',
                $actions['projects_without_technicians']['label']
            );
            $this->assertSame(
                route('super-admin.projects').'?status=no_technicians',
                $actions['projects_without_technicians']['url']
            );

            // Nothing is waiting on either of these, so neither is listed.
            $this->assertFalse($actions->has('specialty_requests'));
            $this->assertFalse($actions->has('pending_inquiries'));
        }
    }

    /**
     * An entry is one sentence, and the number agrees with the noun.
     */
    public function test_an_urgent_action_says_one_project_rather_than_one_projects(): void
    {
        $this->project('unscheduled');
        $this->project('unscheduled');

        $actions = collect(
            $this->actingAs($this->account('super_admin', 'owner@example.test'))
                ->get(route('super-admin.dashboard'))
                ->viewData('urgentActions')
        )->keyBy('key');

        $this->assertSame('2 Unscheduled Projects', $actions['unscheduled_projects']['label']);
    }

    /**
     * The counts describe the work as it is now, so clearing a backlog takes
     * its entry off the dashboard rather than leaving it reading zero.
     */
    public function test_an_urgent_action_disappears_once_its_backlog_is_cleared(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');
        $project = $this->project('unscheduled');

        $this->assertTrue($this->hasUrgentAction($owner, 'unscheduled_projects'));

        Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => now()->addDay()->format('Y-m-d').' 08:00:00',
            'end_datetime' => now()->addDays(3)->format('Y-m-d').' 17:00:00',
            'status' => 'scheduled',
        ]);

        $this->assertFalse($this->hasUrgentAction($owner, 'unscheduled_projects'));
    }

    /**
     * Paused work is not a backlog. A hold sets the project's status to
     * Unscheduled, so without this every held project would report itself as
     * waiting to be booked.
     */
    public function test_a_held_project_is_not_reported_as_unscheduled(): void
    {
        $held = $this->project('unscheduled');
        $held->forceFill(['on_hold' => true])->save();

        $owner = $this->account('super_admin', 'owner@example.test');

        $this->assertFalse($this->hasUrgentAction($owner, 'unscheduled_projects'));

        // Still counted as crewless, though: a hold pauses the dates, not the
        // question of who is going to do the job.
        $this->assertTrue($this->hasUrgentAction($owner, 'projects_without_technicians'));
    }

    /**
     * Finished and archived work is history, and history is never a backlog.
     */
    public function test_finished_and_archived_work_is_never_urgent(): void
    {
        foreach (['completed', 'cancelled'] as $status) {
            $this->project($status);
        }

        $archived = $this->project('unscheduled');
        $archived->forceFill(['is_archived' => true])->save();

        $this->actingAs($this->account('super_admin', 'owner@example.test'))
            ->get(route('super-admin.dashboard'))
            ->assertOk()
            ->assertViewHas('urgentActions', []);
    }

    /**
     * The three queues that are not project counts: a technician who can no
     * longer sign in but is still crewed, a specialty decision nobody has
     * taken, and an enquiry nobody has answered.
     */
    public function test_urgent_actions_report_the_queues_outside_the_projects_table(): void
    {
        $project = $this->project('ongoing', ['start' => now()->subDay(), 'end' => now()->addDay()]);

        $tech = $this->account('technician', 'tech@example.test');
        $technician = Technician::create(['account_id' => $tech->id, 'role' => 'technician']);

        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);

        // Switched off, but their booking survives - which is the assignment
        // somebody has to pick up.
        $tech->forceFill(['status' => User::STATUS_DEACTIVATED])->save();

        SpecialtyRequest::create([
            'technician_id' => $technician->technician_id,
            'status' => SpecialtyRequest::STATUS_PENDING,
            'requested_skill_ids' => [],
            'current_skill_ids' => [],
            'requested_by' => $tech->id,
        ]);

        // New and In Progress are both still open work; Closed is an ending.
        foreach ([Inquiry::STATUS_NEW, Inquiry::STATUS_IN_PROGRESS, Inquiry::STATUS_CLOSED] as $index => $status) {
            Inquiry::create([
                'name' => 'Rosa Villanueva',
                'email' => 'rosa'.$index.'@example.test',
                'subject' => 'Aircon quote',
                'message' => 'We need two split-type units installed at our office.',
                'status' => $status,
            ]);
        }

        $actions = collect(
            $this->actingAs($this->account('super_admin', 'owner@example.test'))
                ->get(route('super-admin.dashboard'))
                ->assertOk()
                ->viewData('urgentActions')
        )->keyBy('key');

        $this->assertSame(
            '1 Inactive Technician in a Project',
            $actions['inactive_technicians']['label']
        );
        $this->assertSame(
            route('super-admin.projects').'?status=inactive_crew',
            $actions['inactive_technicians']['url']
        );

        $this->assertSame('1 Specialty Change Request', $actions['specialty_requests']['label']);
        $this->assertSame(
            route('super-admin.technicians.index').'?specialty=pending',
            $actions['specialty_requests']['url']
        );

        $this->assertSame('2 Pending Inquiries', $actions['pending_inquiries']['label']);
        $this->assertSame(
            route('super-admin.configuration.index').'?inquiries=pending',
            $actions['pending_inquiries']['url']
        );

        // The project is crewed, so it is not also reported as empty.
        $this->assertFalse($actions->has('projects_without_technicians'));
    }

    /**
     * Every View lands on a page that actually offers the tab it asks for -
     * an entry linking at a filter the table does not draw would open the
     * whole list and quietly lose the reader.
     */
    public function test_each_view_link_opens_a_list_that_offers_the_tab_it_asks_for(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');

        $this->project('unscheduled');
        $this->project('ongoing', ['start' => now()->subDays(9), 'end' => now()->subDays(2)]);

        $tabs = collect(
            $this->actingAs($owner)->get(route('super-admin.projects'))->viewData('statusTabs')
        )->pluck('key');

        foreach (['unscheduled', 'overdue', 'no_technicians'] as $key) {
            $this->assertContains($key, $tabs);
        }

        // And the rows say which of those tabs they answer, which is what the
        // browser-side filter reads.
        $this->actingAs($owner)
            ->get(route('super-admin.projects'))
            ->assertOk()
            ->assertSee('data-tab-extra="unscheduled no_technicians"', false);
    }

    /**
     * An attention tab is drawn only while it holds something, unlike a status
     * tab - so a cleared backlog takes its tab away as well as its entry.
     */
    public function test_an_attention_tab_is_absent_when_nothing_needs_that_attention(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');

        $tabs = collect(
            $this->actingAs($owner)->get(route('super-admin.projects'))->viewData('statusTabs')
        )->pluck('key');

        // The status tabs are always drawn, zero or not.
        $this->assertContains('overdue', $tabs);

        foreach (array_keys(Project::ATTENTION_TABS) as $key) {
            $this->assertNotContains($key, $tabs);
        }
    }

    /**
     * Whether the dashboard is currently reporting one particular backlog.
     */
    private function hasUrgentAction(User $viewer, string $key): bool
    {
        return collect(
            $this->actingAs($viewer)
                ->get(route('super-admin.dashboard'))
                ->viewData('urgentActions')
        )->contains(fn (array $action): bool => $action['key'] === $key);
    }

    // ------------------------------------------------------------------
    // Active Technicians Today
    // ------------------------------------------------------------------

    /**
     * Who is on site today, decided by the same booked ranges the Active Today
     * figure counts: a technician on a project whose dates cover today, and
     * nobody else.
     */
    public function test_active_technicians_today_lists_only_who_is_booked_today(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');

        $onSiteAccount = $this->account('technician', 'onsite@example.test');
        $onSite = Technician::create(['account_id' => $onSiteAccount->id, 'role' => 'technician']);

        $nextWeekAccount = $this->account('technician', 'nextweek@example.test');
        $nextWeek = Technician::create(['account_id' => $nextWeekAccount->id, 'role' => 'technician']);

        $today = $this->project('ongoing', ['start' => now()->subDay(), 'end' => now()->addDay()]);
        ProjectTechnician::create([
            'project_id' => $today->project_id,
            'technician_id' => $onSite->technician_id,
        ]);

        $later = $this->project('pending', ['start' => now()->addDays(7), 'end' => now()->addDays(9)]);
        ProjectTechnician::create([
            'project_id' => $later->project_id,
            'technician_id' => $nextWeek->technician_id,
        ]);

        $active = app(DashboardMetrics::class)->activeTechniciansToday();

        $this->assertSame([$onSiteAccount->fullName()], $active->pluck('name')->all());
        // The job they are on travels with them, and so does their picture.
        $this->assertSame([$today->reference_no], $active->first()['projects']);
        $this->assertSame(asset('img/default-avatar.svg'), $active->first()['avatar_url']);
        $this->assertSame(1, app(DashboardMetrics::class)->activeTechnicianCountToday());

        $this->actingAs($owner)
            ->get(route('super-admin.dashboard'))
            ->assertOk()
            ->assertSee('Active Technicians Today')
            ->assertSee($onSiteAccount->fullName())
            // The job they are on names them apart; the two accounts share a
            // display name, so the reference is what distinguishes the rows.
            ->assertSee($today->reference_no)
            ->assertDontSee($later->reference_no);
    }

    /**
     * A paused project has nobody on site, whatever dates it still holds - the
     * same rule the Active Today figure applies.
     */
    public function test_a_held_project_puts_nobody_on_site_today(): void
    {
        $this->account('super_admin', 'owner@example.test');

        $account = $this->account('technician', 'paused@example.test');
        $technician = Technician::create(['account_id' => $account->id, 'role' => 'technician']);

        $project = $this->project('ongoing', ['start' => now()->subDay(), 'end' => now()->addDay()]);
        $project->update(['on_hold' => true]);

        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);

        $this->assertTrue(app(DashboardMetrics::class)->activeTechniciansToday()->isEmpty());
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
