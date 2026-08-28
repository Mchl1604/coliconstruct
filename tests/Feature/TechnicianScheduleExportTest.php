<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Technician;
use App\Models\User;
use App\Services\SystemReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Technician Schedule export, which is a report about people rather than
 * about projects.
 *
 * The distinction is the whole of it. A project's schedule says the job ran
 * Aug 24-30; it does not say who was on site, and handing that range to
 * whoever is on the team today gets both halves wrong at once - it credits a
 * newcomer with days they were not here for, and erases the days of whoever
 * they replaced. So every case below is written against a crew that changes
 * part-way through a booking, which is exactly where a project-level answer
 * and a person-level answer stop agreeing.
 *
 * Time is pinned in every test. Past and Future are decided against the
 * office's today, so a suite run in December would otherwise classify these
 * August bookings differently from the same suite run in July.
 */
class TechnicianScheduleExportTest extends TestCase
{
    use RefreshDatabase;

    /** The office day every test below is written against. */
    private const TODAY = '2026-08-28';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 04:00:00', 'UTC'));
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function technician(string $name): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => 'technician', 'status' => User::STATUS_ACTIVE])->save();

        return Technician::create(['account_id' => $user->id, 'role' => 'technician']);
    }

    private function project(string $name): Project
    {
        $project = Project::create([
            'name' => $name,
            'reference_no' => 'REF-'.strtoupper(substr(md5($name.microtime()), 0, 8)),
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 250000,
            'is_archived' => false,
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

        return $project;
    }

    private function schedule(Project $project, string $start, string $end): Schedule
    {
        return Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $start.' 00:00:00',
            'end_datetime' => $end.' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);
    }

    /**
     * A membership span, written the way ProjectTeam writes one.
     *
     * `$removed` is exclusive - the day somebody is taken off is a day they
     * were still there for - so a technician who worked the 24th to the 26th
     * is recorded as removed on the 27th. See ProjectTechnician::coveredOn().
     */
    private function assign(
        Project $project,
        Technician $technician,
        ?string $joined = null,
        ?string $removed = null,
        ?array $bookOn = null
    ): ProjectTechnician {
        $assignment = ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
            'joined_at' => $joined ? $joined.' 08:00:00' : null,
            'removed_at' => $removed ? $removed.' 08:00:00' : null,
        ]);

        // The booking ledger. A membership says somebody is on the project; it
        // is this that says which of its ranges they are on, and the export
        // needs both. `$bookOn` names the ranges - null books every one of
        // them, which is what a straightforwardly assigned technician has.
        foreach ($bookOn ?? $project->schedules->all() as $schedule) {
            ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $assignment->project_technician_id,
            ]);
        }

        return $assignment;
    }

    // ------------------------------------------------------------------
    // Reading the report
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function section(string $key = 'technician_schedule', string $month = '2026-08-01'): array
    {
        $day = CarbonImmutable::parse($month);

        $report = app(SystemReportService::class)->exportReport(
            'technician',
            app(SystemReportService::class)->resolveExportPeriod(
                'monthly',
                (int) $day->format('n'),
                (int) $day->format('Y')
            ),
            ['technician_kind' => 'all']
        );

        return collect($report['sections'])->firstWhere('key', $key);
    }

    /**
     * The rows of one half of the report, as "Technician | Schedule" strings -
     * the two columns every case below is actually about.
     *
     * @return array<int, string>
     */
    private function lines(string $half, string $month = '2026-08-01'): array
    {
        return collect($this->section('technician_schedule', $month)['subsections'])
            ->firstWhere('key', $half)['rows']
            ->map(fn (array $row): string => $row['technician'].' | '.$row['schedule'])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $half, string $month = '2026-08-01'): array
    {
        return collect($this->section('technician_schedule', $month)['subsections'])
            ->firstWhere('key', $half)['rows']
            ->all();
    }

    /**
     * The Assigned Projects rows, as
     * "Technician | Status | Removed Date | Schedule" - the four columns the
     * cases below are about, with the schedule cell joined by a slash when a
     * technician holds several stretches.
     *
     * @return array<int, string>
     */
    private function assignments(string $month = '2026-08-01'): array
    {
        return collect($this->section('assigned', $month)['rows'])
            ->map(fn (array $row): string => implode(' | ', [
                $row['technician'],
                $row['assignment_status'],
                $row['removed_on'],
                $row['schedules'] === [] ? 'No scheduled dates' : implode(' / ', $row['schedules']),
            ]))
            ->all();
    }

    // ------------------------------------------------------------------
    // The cases
    // ------------------------------------------------------------------

    /**
     * Test 1 and 3. One booking, two people across it, and neither of them
     * gets the whole range.
     *
     * Wholly behind today on purpose: this case is about the record, and a
     * booking straddling today would also be exercising the Past/Future cut,
     * which has its own tests below.
     */
    public function test_a_crew_change_mid_booking_splits_the_range_between_them(): void
    {
        $project = $this->project('Split Job');
        $this->schedule($project, '2026-08-10', '2026-08-16');
        $project->load('schedules');

        $ana = $this->technician('Ana Mendoza');
        $ben = $this->technician('Ben Santos');

        $this->assign($project, $ana, '2026-08-10', '2026-08-13');
        $this->assign($project, $ben, '2026-08-13');

        $this->assertSame([
            'Ana Mendoza | Aug 10, 2026 - Aug 12, 2026',
            'Ben Santos | Aug 13, 2026 - Aug 16, 2026',
        ], $this->lines('past'));

        // Never the project's range handed to both.
        $this->assertSame([3, 4], collect($this->rows('past'))->pluck('duration')->all());
        $this->assertSame([], $this->lines('future'));
    }

    /**
     * Test 2 and 8. Somebody taken off the project keeps the days they worked.
     * The current team does not contain them at all, which is what the export
     * used to be built from.
     */
    public function test_a_removed_technician_keeps_the_days_they_worked(): void
    {
        $project = $this->project('Handover Job');
        $this->schedule($project, '2026-08-24', '2026-08-26');
        $project->load('schedules');

        $ana = $this->technician('Ana Mendoza');
        $this->assign($project, $ana, '2026-08-24', '2026-08-27');

        // Nobody is on this project as it stands.
        $this->assertCount(0, $project->fresh()->projectTechnicians);

        $this->assertSame(
            ['Ana Mendoza | Aug 24, 2026 - Aug 26, 2026'],
            $this->lines('past')
        );
    }

    /**
     * Test 4. A missing date is a break, not a detail to be smoothed over.
     */
    public function test_a_gap_in_the_dates_stays_a_gap(): void
    {
        $project = $this->project('Interrupted Job');
        $this->schedule($project, '2026-08-24', '2026-08-26');
        $this->schedule($project, '2026-08-28', '2026-08-30');
        $project->load('schedules');

        $ana = $this->technician('Ana Mendoza');
        $this->assign($project, $ana, '2026-08-24');

        // Aug 27 is not booked, so it is not worked - and the two runs either
        // side of it must not be joined into Aug 24-30.
        $this->assertSame(
            ['Ana Mendoza | Aug 24, 2026 - Aug 26, 2026'],
            $this->lines('past')
        );
        $this->assertSame(
            ['Ana Mendoza | Aug 28, 2026 - Aug 30, 2026'],
            $this->lines('future')
        );
    }

    /**
     * Test 5. Two bookings on one project stay two rows, however far apart.
     */
    public function test_separate_bookings_are_never_merged(): void
    {
        $project = $this->project('Two Visit Job');
        $this->schedule($project, '2026-08-24', '2026-08-26');
        $this->schedule($project, '2026-09-06', '2026-09-08');
        $project->load('schedules');

        $ana = $this->technician('Ana Mendoza');
        $this->assign($project, $ana, '2026-08-01');

        $this->assertSame(
            ['Ana Mendoza | Aug 24, 2026 - Aug 26, 2026'],
            $this->lines('past')
        );

        // Test 9: the September visit is the future half, and it is reported
        // in September rather than folded into August.
        $this->assertSame([], $this->lines('future'));
        $this->assertSame(
            ['Ana Mendoza | Sep 6, 2026 - Sep 8, 2026'],
            $this->lines('future', '2026-09-01')
        );
    }

    /**
     * Test 6. A booking running across today is cut where today falls, and
     * today itself is future - it is being worked, not already worked.
     */
    public function test_a_booking_crossing_today_is_cut_at_today(): void
    {
        $project = $this->project('Crossing Job');
        $this->schedule($project, '2026-08-26', '2026-08-30');
        $project->load('schedules');

        $ana = $this->technician('Ana Mendoza');
        $this->assign($project, $ana, '2026-08-01');

        $this->assertSame(
            ['Ana Mendoza | Aug 26, 2026 - Aug 27, 2026'],
            $this->lines('past')
        );
        $this->assertSame(
            ['Ana Mendoza | Aug 28, 2026 - Aug 30, 2026'],
            $this->lines('future')
        );
    }

    /**
     * Test 6 again, with the crew changing over the same boundary: the split
     * follows the assignment, not just the calendar.
     */
    public function test_a_handover_across_today_lands_in_both_halves(): void
    {
        $project = $this->project('Handover Crossing Job');
        $this->schedule($project, '2026-08-26', '2026-08-30');
        $project->load('schedules');

        $ana = $this->technician('Ana Mendoza');
        $ben = $this->technician('Ben Santos');

        $this->assign($project, $ana, '2026-08-26', '2026-08-28');
        $this->assign($project, $ben, '2026-08-28');

        $this->assertSame(
            ['Ana Mendoza | Aug 26, 2026 - Aug 27, 2026'],
            $this->lines('past')
        );
        $this->assertSame(
            ['Ben Santos | Aug 28, 2026 - Aug 30, 2026'],
            $this->lines('future')
        );
    }

    /**
     * Test 7. Three people over one booking, each with their own days.
     */
    public function test_three_technicians_over_one_booking_each_get_their_own_days(): void
    {
        $project = $this->project('Relay Job');
        $this->schedule($project, '2026-08-24', '2026-08-30');
        $project->load('schedules');

        $ana = $this->technician('Ana Mendoza');
        $ben = $this->technician('Ben Santos');
        $cara = $this->technician('Cara Lim');

        $this->assign($project, $ana, '2026-08-24', '2026-08-26');
        $this->assign($project, $ben, '2026-08-26', '2026-08-29');
        $this->assign($project, $cara, '2026-08-29');

        $this->assertSame([
            'Ana Mendoza | Aug 24, 2026 - Aug 25, 2026',
            'Ben Santos | Aug 26, 2026 - Aug 27, 2026',
        ], $this->lines('past'));

        // Ben's booking runs across today, so his last two days are future
        // work and Cara's are all still ahead.
        $this->assertSame([
            'Ben Santos | Aug 28, 2026',
            'Cara Lim | Aug 29, 2026 - Aug 30, 2026',
        ], $this->lines('future'));
    }

    /**
     * Test 10. The reporting period is applied to the individual dates, and
     * the printed range is still the run that was worked.
     */
    public function test_the_period_counts_days_without_shortening_the_range(): void
    {
        $project = $this->project('Crossing In Job');
        $this->schedule($project, '2026-07-29', '2026-08-03');
        $project->load('schedules');

        $ana = $this->technician('Ana Mendoza');
        $this->assign($project, $ana, '2026-07-01');

        $rows = $this->rows('past');

        $this->assertCount(1, $rows);
        // The booking reads as the booking...
        $this->assertSame('Jul 29, 2026 - Aug 3, 2026', $rows[0]['schedule']);
        // ...while the figure counts only the August of it.
        $this->assertSame(3, $rows[0]['duration']);
        $this->assertSame('Past Scheduled Days: 3', $this->summaryLine('Past Scheduled Days'));
    }

    /**
     * A technician who joined after a booking ran is not written into it, the
     * other half of the removal rule.
     */
    public function test_a_later_joiner_does_not_acquire_earlier_days(): void
    {
        $project = $this->project('Late Joiner Job');
        $this->schedule($project, '2026-08-10', '2026-08-12');
        $project->load('schedules');

        $ana = $this->technician('Ana Mendoza');
        $ben = $this->technician('Ben Santos');

        $this->assign($project, $ana, '2026-08-01');
        $this->assign($project, $ben, '2026-08-20');

        $this->assertSame(
            ['Ana Mendoza | Aug 10, 2026 - Aug 12, 2026'],
            $this->lines('past')
        );
    }

    /**
     * Being on the project is not being on the booking.
     *
     * The membership span says who is on the team; tbl_schedule_technicians
     * says who is on a given range. A technician on the team for the whole
     * month, but not booked onto the range covering a date, did not work that
     * date and must not be credited with it.
     */
    public function test_a_team_member_not_booked_on_the_range_is_not_credited_with_it(): void
    {
        $project = $this->project('Selective Job');
        $first = $this->schedule($project, '2026-08-10', '2026-08-12');
        $second = $this->schedule($project, '2026-08-17', '2026-08-19');
        $project->load('schedules');

        $ana = $this->technician('Ana Mendoza');
        $ben = $this->technician('Ben Santos');

        // Both are on the project all month; only Ana is booked on the second
        // range.
        $this->assign($project, $ana, '2026-08-01', null, [$first, $second]);
        $this->assign($project, $ben, '2026-08-01', null, [$first]);

        $this->assertSame([
            'Ana Mendoza | Aug 10, 2026 - Aug 12, 2026',
            'Ana Mendoza | Aug 17, 2026 - Aug 19, 2026',
            'Ben Santos | Aug 10, 2026 - Aug 12, 2026',
        ], $this->lines('past'));
    }

    /**
     * A membership widened by a historical correction that was later withdrawn
     * leaves a span reaching back over dates nobody booked that person for.
     * The span alone would hand them those dates; the booking is what stops it.
     */
    public function test_a_widened_span_does_not_claim_a_range_it_was_never_booked_on(): void
    {
        $project = $this->project('Corrected Job');
        $range = $this->schedule($project, '2026-08-10', '2026-08-12');
        $project->load('schedules');

        $ana = $this->technician('Ana Mendoza');
        $stale = $this->technician('Stale Span');

        $this->assign($project, $ana, '2026-08-01', null, [$range]);
        // On the team, and their span covers the range - but they hold no
        // booking on it, exactly as a withdrawn correction leaves things.
        $this->assign($project, $stale, '2026-08-01', null, []);

        $this->assertSame(
            ['Ana Mendoza | Aug 10, 2026 - Aug 12, 2026'],
            $this->lines('past')
        );
    }

    /**
     * The export and the Schedule page's day panel answer the same question
     * the same way, because only one of them decides it.
     */
    public function test_the_export_agrees_with_the_schedule_page_for_a_past_date(): void
    {
        $project = $this->project('Agreement Job');
        $this->schedule($project, '2026-08-24', '2026-08-30');
        $project->load('schedules');

        $ana = $this->technician('Ana Mendoza');
        $ben = $this->technician('Ben Santos');

        $this->assign($project, $ana, '2026-08-24', '2026-08-27');
        $this->assign($project, $ben, '2026-08-27');

        $panel = $this->getJson(route('super-admin.schedules.date', ['date' => '2026-08-25']));
        $panel->assertOk();

        $onScreen = collect($panel->json('projects'))
            ->firstWhere('project_id', $project->project_id)['technicians'];

        $this->assertSame(['Ana Mendoza'], collect($onScreen)->pluck('name')->all());

        // And the export puts the same person against the same day.
        $covering = collect($this->rows('past'))->filter(
            fn (array $row): bool => $row['schedule'] === 'Aug 24, 2026 - Aug 26, 2026'
        );

        $this->assertSame(['Ana Mendoza'], $covering->pluck('technician')->values()->all());
    }

    /**
     * The section carries both halves whatever the period holds, so a report
     * with no past work says so rather than leaving the reader guessing which
     * half they are looking at.
     */
    public function test_both_halves_are_always_present(): void
    {
        $project = $this->project('Future Only Job');
        $this->schedule($project, '2026-08-29', '2026-08-31');
        $project->load('schedules');

        $this->assign($project, $this->technician('Ana Mendoza'), '2026-08-01');

        $this->assertSame(
            ['past', 'future'],
            collect($this->section()['subsections'])->pluck('key')->all()
        );
        $this->assertSame([], $this->lines('past'));
        $this->assertCount(1, $this->lines('future'));
        $this->assertSame('Past Scheduled Days: 0', $this->summaryLine('Past Scheduled Days'));
        $this->assertSame('Future Scheduled Days: 3', $this->summaryLine('Future Scheduled Days'));
    }

    // ------------------------------------------------------------------
    // Assigned Projects: status, removal date, and the dates actually held
    // ------------------------------------------------------------------

    /**
     * Tests 1, 2, 3 and 6 together, because they are one scenario: a booking
     * handed from one technician to another part-way through.
     *
     * The removal date is Aug 27 because that is what the membership records.
     * It is NOT Aug 26, which is merely the last day they were scheduled -
     * deriving one from the other is the mistake this column exists to avoid.
     */
    public function test_status_and_removal_date_come_from_the_assignment_history(): void
    {
        $project = $this->project('Handover Job');
        $this->schedule($project, '2026-08-10', '2026-08-16');
        $project->load('schedules');

        $ana = $this->technician('Ana Mendoza');
        $ben = $this->technician('Ben Santos');

        $this->assign($project, $ana, '2026-08-10', '2026-08-13');
        $this->assign($project, $ben, '2026-08-13');

        $this->assertSame([
            // Removed, with the date the membership was closed - not Aug 12,
            // which is the last day they worked.
            'Ana Mendoza | Removed | Aug 13, 2026 | Aug 10, 2026 - Aug 12, 2026',
            'Ben Santos | Active | — | Aug 13, 2026 - Aug 16, 2026',
        ], $this->assignments());

        $section = $this->section('assigned');

        $this->assertSame('Active Assignments: 1', $this->summaryLine('Active Assignments', $section));
        $this->assertSame('Removed Assignments: 1', $this->summaryLine('Removed Assignments', $section));
    }

    /**
     * Test 4. A removal months ago is still a removal, and its date is still
     * the date it happened. The current state of the team rewrites nothing.
     */
    public function test_a_historical_removal_keeps_its_date(): void
    {
        $project = $this->project('Old Job');
        $this->schedule($project, '2026-08-10', '2026-08-12');
        $project->load('schedules');

        $ana = $this->technician('Ana Mendoza');
        $this->assign($project, $ana, '2026-02-01', '2026-03-15');

        // Their membership ended in March, so August holds none of their days -
        // but the assignment is still on the record, with the date it closed.
        $this->assertSame(
            ['Ana Mendoza | Removed | Mar 15, 2026 | No scheduled dates'],
            $this->assignments()
        );
    }

    /**
     * Test 6, the other half. Neither technician is given the project's own
     * range, and the schedule cell is not cut at today the way the Past/Future
     * tables are - one assignment reads as one stretch of work.
     */
    public function test_an_assignment_running_across_today_is_one_stretch(): void
    {
        $project = $this->project('Crossing Job');
        $this->schedule($project, '2026-08-24', '2026-08-30');
        $project->load('schedules');

        $ana = $this->technician('Ana Mendoza');
        $ben = $this->technician('Ben Santos');

        $this->assign($project, $ana, '2026-08-24', '2026-08-27');
        $this->assign($project, $ben, '2026-08-27');

        $this->assertSame([
            'Ana Mendoza | Removed | Aug 27, 2026 | Aug 24, 2026 - Aug 26, 2026',
            // Aug 27-30 whole, though today is the 28th.
            'Ben Santos | Active | — | Aug 27, 2026 - Aug 30, 2026',
        ], $this->assignments());

        // The Schedule section still cuts the same work at today.
        $this->assertSame([
            'Ana Mendoza | Aug 24, 2026 - Aug 26, 2026',
            'Ben Santos | Aug 27, 2026',
        ], $this->lines('past'));
        $this->assertSame(['Ben Santos | Aug 28, 2026 - Aug 30, 2026'], $this->lines('future'));
    }

    /**
     * Test 5. Two stretches of work on one project stay two, and are never run
     * together into the weeks between them.
     */
    public function test_two_stretches_of_work_are_not_merged(): void
    {
        $project = $this->project('Return Visit Job');
        $this->schedule($project, '2026-08-10', '2026-08-12');
        $this->schedule($project, '2026-08-24', '2026-08-26');
        $project->load('schedules');

        $ana = $this->technician('Ana Mendoza');
        $this->assign($project, $ana, '2026-08-01');

        $row = collect($this->section('assigned')['rows'])->firstWhere('technician', 'Ana Mendoza');

        // Two cells' worth of dates, not one range swallowing the fortnight
        // between them.
        $this->assertSame([
            'Aug 10, 2026 - Aug 12, 2026',
            'Aug 24, 2026 - Aug 26, 2026',
        ], $row['schedules']);
    }

    /**
     * And the same across a month boundary: the reporting period narrows the
     * dates a row shows without ever joining what it keeps to what it drops.
     */
    public function test_a_stretch_outside_the_period_is_not_dragged_in(): void
    {
        $project = $this->project('Return Visit Job');
        $this->schedule($project, '2026-08-24', '2026-08-26');
        $this->schedule($project, '2026-09-06', '2026-09-08');
        $project->load('schedules');

        $ana = $this->technician('Ana Mendoza');
        $this->assign($project, $ana, '2026-08-01');

        $august = collect($this->section('assigned')['rows'])->firstWhere('technician', 'Ana Mendoza');
        $this->assertSame(['Aug 24, 2026 - Aug 26, 2026'], $august['schedules']);

        $september = collect($this->section('assigned', '2026-09-01')['rows'])
            ->firstWhere('technician', 'Ana Mendoza');
        $this->assertSame(['Sep 6, 2026 - Sep 8, 2026'], $september['schedules']);
    }

    /**
     * Test 6 of the previous brief, restated here: an assignment with no dates
     * against it is still an assignment and is still listed. No dates are
     * invented for it.
     */
    public function test_an_assignment_with_no_dates_is_still_listed(): void
    {
        $project = $this->project('Unbooked Job');
        $project->load('schedules');

        $ana = $this->technician('Ana Mendoza');
        $this->assign($project, $ana, '2026-08-01', '2026-08-20');

        $this->assertSame(
            ['Ana Mendoza | Removed | Aug 20, 2026 | No scheduled dates'],
            $this->assignments()
        );
    }

    /**
     * Test 9 of the brief. The project's state and the technician's are
     * different facts and the table prints both.
     */
    public function test_a_completed_project_can_carry_an_active_assignment(): void
    {
        $project = $this->project('Finished Job');
        $this->schedule($project, '2026-08-10', '2026-08-12');
        $project->load('schedules');
        $project->forceFill(['status' => 'completed', 'completed_at' => '2026-08-13 09:00:00'])->save();

        $ana = $this->technician('Ana Mendoza');
        $this->assign($project, $ana, '2026-08-01');

        $row = collect($this->section('assigned')['rows'])->firstWhere('technician', 'Ana Mendoza');

        $this->assertSame('Active', $row['assignment_status']);
        $this->assertSame('—', $row['removed_on']);
        // The project's own status is carried beside it, not instead of it.
        $this->assertSame('completed', $row['status_key']);
    }

    /**
     * The PDF is the deliverable, so the split has to survive rendering rather
     * than only existing in the array behind it.
     */
    public function test_the_exported_pdf_carries_both_halves(): void
    {
        $project = $this->project('Printed Job');
        // One booking that has run, and one still ahead, so both halves have
        // something to print.
        $this->schedule($project, '2026-08-10', '2026-08-16');
        $this->schedule($project, '2026-09-01', '2026-09-03');
        $project->load('schedules');

        $ana = $this->technician('Ana Mendoza');
        $ben = $this->technician('Ben Santos');

        $this->assign($project, $ana, '2026-08-10', '2026-08-13');
        $this->assign($project, $ben, '2026-08-13');

        $html = view('super-admin.reports-pdf', $this->pdfPayload())->render();

        $this->assertStringContainsString('PAST SCHEDULE', $html);
        $this->assertStringContainsString('FUTURE SCHEDULE', $html);
        $this->assertStringContainsString('Scheduled Days', $html);
        $this->assertStringContainsString('Aug 10, 2026 - Aug 12, 2026', $html);
        $this->assertStringContainsString('Aug 13, 2026 - Aug 16, 2026', $html);
        // The project's own range is never printed as anybody's schedule.
        $this->assertStringNotContainsString('Aug 10, 2026 - Aug 16, 2026', $html);
    }

    /**
     * The whole flow, through the endpoint a user actually presses.
     */
    public function test_the_export_endpoint_returns_a_pdf(): void
    {
        $project = $this->project('Downloaded Job');
        $this->schedule($project, '2026-08-24', '2026-08-26');
        $project->load('schedules');

        $this->assign($project, $this->technician('Ana Mendoza'), '2026-08-24');

        $response = $this->post(route('super-admin.reports.export'), [
            'report_type' => 'technician',
            'period' => 'monthly',
            'month' => 8,
            'year' => 2026,
            'technician_kind' => 'schedule',
            'technician_scope' => 'all',
        ]);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    /**
     * @return array<string, mixed>
     */
    private function pdfPayload(): array
    {
        $service = app(SystemReportService::class);
        $period = $service->resolveExportPeriod('monthly', 8, 2026);

        return [
            'report' => $service->exportReport('technician', $period, ['technician_kind' => 'all']),
            'reportTitle' => 'Technician Report',
            'period' => $period,
            'appliedFilters' => [],
            'generatedBy' => 'Test Super Admin',
            'generatedAt' => CarbonImmutable::now(),
            'logoData' => null,
            'company' => \App\Support\CompanyBranding::letterhead(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $section
     */
    private function summaryLine(string $label, ?array $section = null): ?string
    {
        $line = collect(($section ?? $this->section())['summary'])->firstWhere('label', $label);

        return $line ? $line['label'].': '.$line['value'] : null;
    }
}
