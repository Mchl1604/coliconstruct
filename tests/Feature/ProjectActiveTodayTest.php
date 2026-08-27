<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\Technician;
use App\Models\User;
use App\Services\DashboardMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "There is a crew on this job today", on every portal's projects table.
 *
 * One rule decides it - Project::isActiveToday(), with scopeActiveToday() as
 * its SQL twin - so the four tables and the dashboard's Active Today figure
 * cannot disagree. Nothing is stored: a project starts and stops being
 * highlighted as the date rolls over.
 *
 * The two things most easily got wrong, and pinned here: EVERY booked range is
 * consulted rather than the first or the latest, and the status column is not
 * what decides it - only what excludes archived, finished and paused work.
 */
class ProjectActiveTodayTest extends TestCase
{
    use RefreshDatabase;

    private Technician $technician;

    private Technician $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();

        $this->technician = $this->technician('Ana Cruz');
        $this->lead = $this->technician('Rita Lead', User::ROLE_LEAD_TECHNICIAN);
    }

    // ------------------------------------------------------------------
    // The rule
    // ------------------------------------------------------------------

    public function test_a_project_scheduled_today_is_active_today(): void
    {
        $project = $this->project('Today Only', [[0, 0]]);

        $this->assertTrue($project->isActiveToday());
        $this->assertTrue($this->matchedByScope($project));
    }

    public function test_a_project_scheduled_yesterday_only_is_not_active_today(): void
    {
        $project = $this->project('Yesterday Only', [[-1, -1]]);

        $this->assertFalse($project->isActiveToday());
        $this->assertFalse($this->matchedByScope($project));
    }

    public function test_a_project_scheduled_tomorrow_only_is_not_active_today(): void
    {
        $project = $this->project('Tomorrow Only', [[1, 1]]);

        $this->assertFalse($project->isActiveToday());
        $this->assertFalse($this->matchedByScope($project));
    }

    public function test_a_project_spanning_today_is_active_today(): void
    {
        $project = $this->project('Spanning', [[-3, 3]]);

        $this->assertTrue($project->isActiveToday());
    }

    public function test_a_project_starting_today_is_active_today(): void
    {
        // The boundary: today is the first booked day.
        $project = $this->project('Starts Today', [[0, 5]]);

        $this->assertTrue($project->isActiveToday());
        $this->assertTrue($this->matchedByScope($project));
    }

    public function test_a_project_ending_today_is_active_today(): void
    {
        // The other boundary: today is the last booked day, and the crew is
        // on site for it.
        $project = $this->project('Ends Today', [[-5, 0]]);

        $this->assertTrue($project->isActiveToday());
        $this->assertTrue($this->matchedByScope($project));
    }

    public function test_every_range_is_checked_not_just_the_first(): void
    {
        // The example from the brief: Aug 24-26 and Sep 6-8, read on Sep 7.
        // The first range is long past; the second is today. Checking only the
        // first - or only the latest - gets this wrong.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 09:00', Schedule::BUSINESS_TIMEZONE));

        $project = $this->projectWithDates('Two Ranges', [
            ['2026-08-24', '2026-08-26'],
            ['2026-09-06', '2026-09-08'],
        ]);

        $this->assertTrue($project->isActiveToday());
        $this->assertTrue($this->matchedByScope($project));

        CarbonImmutable::setTestNow();
    }

    public function test_a_project_between_two_ranges_is_not_active_today(): void
    {
        // The same project on Aug 27: past range behind it, future range ahead
        // of it, nobody on site.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-27 09:00', Schedule::BUSINESS_TIMEZONE));

        $project = $this->projectWithDates('Two Ranges', [
            ['2026-08-24', '2026-08-26'],
            ['2026-09-06', '2026-09-08'],
        ]);

        $this->assertFalse($project->isActiveToday());
        $this->assertFalse($this->matchedByScope($project));

        CarbonImmutable::setTestNow();
    }

    public function test_a_project_with_only_past_ranges_is_not_active_today(): void
    {
        $project = $this->project('All Past', [[-20, -15], [-9, -4]]);

        $this->assertFalse($project->isActiveToday());
    }

    public function test_a_project_with_only_future_ranges_is_not_active_today(): void
    {
        $project = $this->project('All Future', [[4, 9], [15, 20]]);

        $this->assertFalse($project->isActiveToday());
    }

    public function test_a_project_with_no_schedule_is_not_active_today(): void
    {
        $project = $this->project('Unbooked', []);

        $this->assertFalse($project->isActiveToday());
    }

    // ------------------------------------------------------------------
    // What the status rules exclude
    // ------------------------------------------------------------------

    public function test_an_archived_project_with_a_range_covering_today_is_not_highlighted(): void
    {
        $project = $this->project('Archived', [[-2, 2]]);
        $project->forceFill(['is_archived' => true])->save();

        $this->assertFalse($project->fresh()->isActiveToday());
        $this->assertFalse($this->matchedByScope($project));
    }

    public function test_finished_and_cancelled_work_is_not_active_today(): void
    {
        // The dates a finished project still holds are the days the crew
        // already worked, not a booking for this morning. Work sitting with
        // the client for confirmation is finished too.
        foreach (Project::READ_ONLY_STATUSES as $status) {
            $project = $this->project('Closed '.$status, [[-2, 2]]);
            $project->forceFill(['status' => $status])->save();

            $this->assertFalse(
                $project->fresh()->isActiveToday(),
                sprintf('A %s project must not be active today.', $status)
            );
            $this->assertFalse($this->matchedByScope($project));
        }
    }

    public function test_a_paused_project_is_not_active_today(): void
    {
        $project = $this->project('Paused', [[-2, 2]]);
        $project->forceFill(['on_hold' => true])->save();

        $this->assertFalse($project->fresh()->isActiveToday());
        $this->assertFalse($this->matchedByScope($project));
    }

    public function test_the_status_column_alone_does_not_decide_it(): void
    {
        // An Ongoing project between its ranges is NOT active today, and a
        // Pending one whose range opens this morning IS. Reading the status
        // column would get both backwards.
        $ongoingButIdle = $this->project('Ongoing Idle', [[-10, -5], [5, 10]]);
        $ongoingButIdle->forceFill(['status' => 'ongoing'])->save();

        $pendingButOnSite = $this->project('Pending On Site', [[0, 4]]);
        $pendingButOnSite->forceFill(['status' => 'pending'])->save();

        $this->assertFalse($ongoingButIdle->fresh()->isActiveToday());
        $this->assertTrue($pendingButOnSite->fresh()->isActiveToday());
    }

    // ------------------------------------------------------------------
    // The row-level rule and the SQL rule agree
    // ------------------------------------------------------------------

    public function test_the_helper_and_the_scope_always_agree(): void
    {
        $cases = [
            'today' => [[0, 0]],
            'yesterday' => [[-1, -1]],
            'tomorrow' => [[1, 1]],
            'spanning' => [[-2, 2]],
            'starts today' => [[0, 3]],
            'ends today' => [[-3, 0]],
            'one of several' => [[-9, -6], [0, 1], [7, 9]],
            'none of several' => [[-9, -6], [7, 9]],
            'unbooked' => [],
        ];

        foreach ($cases as $name => $ranges) {
            $project = $this->project('Agreement '.$name, $ranges);

            $this->assertSame(
                $this->matchedByScope($project),
                $project->isActiveToday(),
                sprintf('isActiveToday() and scopeActiveToday() disagree about "%s".', $name)
            );
        }
    }

    public function test_the_dashboard_figure_counts_each_project_once(): void
    {
        // Two ranges covering today is still one job happening.
        $this->project('Double Booked', [[-1, 1], [0, 0]]);
        $this->project('Also On', [[0, 2]]);
        $this->project('Not On', [[5, 8]]);

        DashboardMetrics::flush();

        $this->assertSame(2, app(DashboardMetrics::class)->projectCounts()['active_today']);
    }

    // ------------------------------------------------------------------
    // Every portal
    // ------------------------------------------------------------------

    /**
     * The same project, in every portal's projects table, flagged identically.
     */
    public function test_every_portal_flags_the_same_project(): void
    {
        $active = $this->project('On Site Today', [[-1, 1]], assignCrew: true);
        $idle = $this->project('Nobody Here', [[5, 9]], assignCrew: true);

        foreach (self::PORTALS as $where => [$role, $routeName]) {
            $response = $this->actingAs($this->accountFor($role))->get(route($routeName));

            $response->assertOk();

            $body = $response->getContent();

            $this->assertStringContainsString('ACTIVE TODAY', $body, $where.' does not flag anything.');
            $this->assertStringContainsString(
                'project-row-active-today',
                $body,
                $where.' does not highlight the row.'
            );

            // The right project, and only it: the flag is carried on the row's
            // own data attribute, so this checks the flag landed on the active
            // project rather than merely appearing somewhere on the page.
            $this->assertSame(
                ['1'],
                $this->activeAttributesFor($body, $active->reference_no),
                $where.' did not mark the active project.'
            );
            $this->assertSame(
                ['0'],
                $this->activeAttributesFor($body, $idle->reference_no),
                $where.' wrongly marked the idle project.'
            );
        }
    }

    public function test_no_portal_flags_a_project_whose_range_ended_yesterday(): void
    {
        $this->project('Finished Yesterday', [[-6, -1]], assignCrew: true);

        foreach (self::PORTALS as $where => [$role, $routeName]) {
            $response = $this->actingAs($this->accountFor($role))->get(route($routeName));

            $response->assertOk();
            $this->assertStringNotContainsString(
                'ACTIVE TODAY',
                $response->getContent(),
                $where.' flagged a project that finished yesterday.'
            );
        }
    }

    public function test_the_flag_appears_by_itself_when_the_date_rolls_into_a_range(): void
    {
        // Booked to start the day after tomorrow. Nothing about the project
        // changes in between - only the date.
        $project = $this->project('Starts Later', [[2, 5]], assignCrew: true);

        $bookedRange = $project->schedules->map(
            fn (Schedule $schedule): string => $schedule->startsOn()->toDateString()
                .'/'.$schedule->endsOn()->toDateString()
        )->all();

        $before = $this->get(route('super-admin.projects'))->getContent();

        $this->assertStringNotContainsString('ACTIVE TODAY', $before);
        $this->assertSame(['0'], $this->activeAttributesFor($before, $project->reference_no));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(2));
        DashboardMetrics::flush();

        $after = $this->get(route('super-admin.projects'))->getContent();

        $this->assertStringContainsString('ACTIVE TODAY', $after);
        $this->assertSame(['1'], $this->activeAttributesFor($after, $project->reference_no));
        $this->assertTrue($project->fresh()->isActiveToday());

        // Nothing about the booking changed - only the date. The highlight is
        // a display state read off the schedule on every request, so there is
        // no column holding it and nothing to migrate when it lapses.
        $this->assertSame(
            $bookedRange,
            $project->fresh()->schedules->map(
                fn (Schedule $schedule): string => $schedule->startsOn()->toDateString()
                    .'/'.$schedule->endsOn()->toDateString()
            )->all()
        );

        CarbonImmutable::setTestNow();
    }

    /**
     * Every portal's projects table, with a role entitled to open it.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PORTALS = [
        'Super Admin' => ['super_admin', 'super-admin.projects'],
        'Admin' => ['admin', 'super-admin.projects'],
        'Lead Technician' => ['lead_technician', 'technician.projects'],
        'Technician' => ['technician', 'technician.projects'],
    ];

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * The `data-active-today` values on the rows carrying a reference number.
     *
     * @return array<int, string>
     */
    private function activeAttributesFor(string $html, string $reference): array
    {
        $found = [];

        // Confined to the table body first. Both pages render their per-project
        // dialogs AFTER the table, and those repeat every reference number - so
        // splitting the whole document on <tr> lets the last row's chunk run on
        // through all of them and answer for projects it does not contain.
        $body = $html;

        if (preg_match('/<tbody\b.*?<\/tbody>/s', $html, $section) === 1) {
            $body = $section[0];
        }

        // Each row opens with its data attributes and carries the reference in
        // a later cell, so the row is read from its <tr> up to the next one.
        foreach (preg_split('/<tr\b/', $body) as $row) {
            if (! str_contains($row, $reference)) {
                continue;
            }

            if (preg_match('/data-active-today="(\d)"/', $row, $matches) === 1) {
                $found[] = $matches[1];
            }
        }

        return $found;
    }

    private function matchedByScope(Project $project): bool
    {
        return Project::query()
            ->activeToday()
            ->whereKey($project->project_id)
            ->exists();
    }

    private function accountFor(string $role): User
    {
        return match ($role) {
            'lead_technician' => $this->lead->account,
            'technician' => $this->technician->account,
            default => $this->administrator($role),
        };
    }

    /** @var array<string, User> */
    private array $administrators = [];

    private function administrator(string $role): User
    {
        if (isset($this->administrators[$role])) {
            return $this->administrators[$role];
        }

        $user = User::factory()->create([
            'name' => ucfirst($role).' Account',
            'email' => $role.'.viewer@example.test',
        ]);

        $user->forceFill(['role' => $role, 'status' => User::STATUS_ACTIVE])->save();

        return $this->administrators[$role] = $user;
    }

    /**
     * A project with booked ranges given as day offsets from today.
     *
     * @param  array<int, array{0: int, 1: int}>  $ranges
     */
    private function project(string $name, array $ranges, bool $assignCrew = false): Project
    {
        $today = Schedule::businessToday();

        return $this->projectWithDates(
            $name,
            array_map(
                fn (array $range): array => [
                    $today->addDays($range[0])->toDateString(),
                    $today->addDays($range[1])->toDateString(),
                ],
                $ranges
            ),
            $assignCrew
        );
    }

    /**
     * The same, with the ranges given as explicit 'Y-m-d' dates.
     *
     * @param  array<int, array{0: string, 1: string}>  $ranges
     */
    private function projectWithDates(string $name, array $ranges, bool $assignCrew = true): Project
    {
        $project = Project::create([
            'name' => $name,
            'reference_no' => 'REF-'.strtoupper(substr(md5($name.microtime()), 0, 10)),
            'status' => 'pending',
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 100000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Commercial',
            'company_name' => $name.' Holdings',
            'firstname' => 'Client',
            'surname' => 'Of '.$project->project_id,
            'fullname' => 'Client Of '.$project->project_id,
            'email_address' => 'client'.$project->project_id.'@example.test',
            'contact_number' => '09123456789',
        ]);

        foreach ($ranges as [$start, $end]) {
            Schedule::create([
                'project_id' => $project->project_id,
                'start_datetime' => $start.' 00:00:00',
                'end_datetime' => $end.' 23:59:59',
                'status' => 'scheduled',
                'remarks' => 'Booking',
            ]);
        }

        if ($assignCrew) {
            foreach ([$this->technician, $this->lead] as $member) {
                ProjectTechnician::create([
                    'project_id' => $project->project_id,
                    'technician_id' => $member->technician_id,
                ]);
            }
        }

        return $project->fresh(['schedules']);
    }

    private function technician(string $name, string $role = 'technician'): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => $role, 'status' => User::STATUS_ACTIVE])->save();

        return Technician::create(['account_id' => $user->id, 'role' => $role]);
    }
}
