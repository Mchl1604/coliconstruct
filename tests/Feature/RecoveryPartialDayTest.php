<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\SystemContent;
use App\Models\Technician;
use App\Models\User;
use App\Services\ProjectScheduleRecovery;
use App\Services\SystemContentService;
use App\Services\TechnicianAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Resolving a schedule conflict with a partial day, in every flow that brings
 * a project back.
 *
 * Restore, Reopen and Resume are the same question asked of three different
 * reasons a project stopped, and they answer it with one service - see
 * ProjectScheduleRecovery. What is pinned here is that they cannot drift
 * apart on any part of it:
 *
 *   - whether Partial Day is offered at all comes from the project's STORED
 *     client record, not from a label on a page or a value a browser sent;
 *   - the hours come from Configuration -> Project Settings, and moving them
 *     moves what all three offer and what all three accept;
 *   - a partial day is screened against the same occupied minutes everything
 *     else is, so it cannot be used to step around a technician who is booked
 *     for the whole of a day;
 *   - and a range that has ended is history in all three.
 */
class RecoveryPartialDayTest extends TestCase
{
    use RefreshDatabase;

    private static int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
    }

    // ==================================================================
    // Fixtures
    // ==================================================================

    private function technician(string $name): Technician
    {
        $sequence = ++self::$sequence;

        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'.'.$sequence.'@example.test',
        ]);

        $user->forceFill(['role' => 'technician'])->save();

        return Technician::create(['account_id' => $user->id, 'role' => 'technician']);
    }

    /**
     * @param  array<int, Technician>  $technicians
     */
    private function project(
        array $technicians,
        string $clientType = 'Residential',
        string $status = 'ongoing'
    ): Project {
        $sequence = ++self::$sequence;

        $project = Project::create([
            'name' => 'Recovery Project '.$sequence,
            'reference_no' => sprintf('PRJ-2026-%05d', $sequence),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 250000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => $clientType,
            'company_name' => $clientType === 'Commercial' ? 'Some Holdings' : null,
            'firstname' => 'Client',
            'surname' => 'One',
            'fullname' => 'Client One',
            'email_address' => 'client'.$sequence.'@example.test',
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

    /**
     * One booking, with every one of the project's crew on it. Whole-day
     * unless hours are given, which is what makes a partial day.
     */
    private function book(
        Project $project,
        int $startOffset,
        int $endOffset,
        ?string $from = null,
        ?string $to = null
    ): Schedule {
        $partial = $from !== null && $to !== null;
        $start = $this->day($startOffset);

        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $partial
                ? $start->setTimeFromTimeString($from)
                : $start->startOfDay(),
            'end_datetime' => $partial
                ? $start->setTimeFromTimeString($to)
                : $this->day($endOffset)->endOfDay(),
            'scheduling_mode' => $partial ? Schedule::MODE_PARTIAL_DAY : Schedule::MODE_DATE_BASED,
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

    private function day(int $offset): CarbonImmutable
    {
        return Schedule::businessToday()->addDays($offset);
    }

    private function storeHours(string $start, string $end): void
    {
        foreach ([
            Schedule::SETTING_PARTIAL_DAY_START => $start,
            Schedule::SETTING_PARTIAL_DAY_END => $end,
        ] as $key => $value) {
            SystemContent::query()->updateOrCreate(
                ['content_key' => $key],
                ['content_value' => $value, 'section' => SystemContent::SECTION_PROJECT_SETTINGS]
            );
        }

        app(SystemContentService::class)->flush();
    }

    // ------------------------------------------------------------------
    // The three flows, addressed the same way
    // ------------------------------------------------------------------

    private function archive(Project $project): Project
    {
        $this->post(route('super-admin.projects.archive', $project->project_id))
            ->assertSessionHasNoErrors();

        return $project->fresh();
    }

    private function hold(Project $project): Project
    {
        $this->put(route('super-admin.projects.hold', $project->project_id))
            ->assertSessionHasNoErrors();

        return $project->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function report(Project $project, string $flow): array
    {
        $route = $flow === ProjectScheduleRecovery::FLOW_RESUME
            ? 'super-admin.projects.resume-conflicts'
            : 'super-admin.projects.restore-conflicts';

        return $this->getJson(route($route, $project->project_id))->assertOk()->json();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolve(Project $project, string $flow, array $payload)
    {
        $route = $flow === ProjectScheduleRecovery::FLOW_RESUME
            ? 'super-admin.projects.resume-schedule'
            : 'super-admin.projects.restore-schedule';

        return $this->putJson(route($route, $project->project_id), $payload);
    }

    private function commit(Project $project, string $flow)
    {
        $route = $flow === ProjectScheduleRecovery::FLOW_RESUME
            ? 'super-admin.projects.resume'
            : 'super-admin.projects.restore';

        return $this->putJson(route($route, $project->project_id));
    }

    /**
     * The range in the report the given schedule row became.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function rangeFor(array $report, Schedule $schedule): array
    {
        foreach ($report['ranges'] as $range) {
            if ((int) $range['schedule_id'] === (int) $schedule->schedule_id) {
                return $range;
            }
        }

        $this->fail('The report does not mention schedule '.$schedule->schedule_id.'.');
    }

    /**
     * A project stopped and put in the way of other work, ready to be brought
     * back by whichever flow is being tested.
     *
     * The clash is deliberately a WHOLE day of the other project, so a partial
     * day of the recovered one has something real to dodge - and something it
     * must not be able to dodge, which is the point of the pair of tests that
     * use it.
     *
     * @return array{0: Project, 1: Schedule, 2: Technician}
     */
    private function stoppedWithConflict(
        string $flow,
        string $clientType = 'Residential',
        int $otherStart = 10,
        int $otherEnd = 12,
        ?string $otherFrom = null,
        ?string $otherTo = null
    ): array {
        $ana = $this->technician('Ana Mendoza');

        $project = $this->project([$ana], $clientType);
        // One range in the past, which is history and never screened, and one
        // ahead of it, which is what the other work will collide with.
        $this->book($project, -8, -6);
        $clashing = $this->book($project, 10, 12);

        $project = $flow === ProjectScheduleRecovery::FLOW_RESUME
            ? $this->hold($project)
            : $this->archive($project);

        // Allowed, and the entire point of stopping a project: those days
        // really are free while it is set aside.
        $other = $this->project([$ana], 'Commercial');
        $this->book($other, $otherStart, $otherEnd, $otherFrom, $otherTo);

        return [$project->fresh(), $clashing->fresh(), $ana];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function recoveryFlows(): array
    {
        return [
            'restore' => [ProjectScheduleRecovery::FLOW_RESTORE],
            'resume' => [ProjectScheduleRecovery::FLOW_RESUME],
        ];
    }

    // ==================================================================
    // Client type comes from the client record
    // ==================================================================

    #[DataProvider('recoveryFlows')]
    public function test_a_residential_project_is_offered_the_partial_day_option(string $flow): void
    {
        [$project, $clashing] = $this->stoppedWithConflict($flow);

        $report = $this->report($project, $flow);

        $this->assertTrue($report['blocked']);
        $this->assertSame('Residential', $report['project']['client_type']);
        $this->assertTrue($report['project']['partial_day_allowed']);
        $this->assertTrue($this->rangeFor($report, $clashing)['partial_day_allowed']);
    }

    #[DataProvider('recoveryFlows')]
    public function test_a_commercial_project_is_not_offered_the_partial_day_option(string $flow): void
    {
        [$project, $clashing] = $this->stoppedWithConflict($flow, 'Commercial');

        $report = $this->report($project, $flow);

        $this->assertTrue($report['blocked']);
        $this->assertSame('Commercial', $report['project']['client_type']);
        $this->assertFalse($report['project']['partial_day_allowed']);
        $this->assertFalse($this->rangeFor($report, $clashing)['partial_day_allowed']);
    }

    /**
     * The answer is the client record's, not the request's. A browser that
     * posts a partial day for a Commercial project - by editing the page, or
     * by posting straight at the endpoint - is refused on the same rule that
     * hid the control.
     *     */
    #[DataProvider('recoveryFlows')]
    public function test_a_commercial_project_cannot_post_its_way_to_a_partial_day(string $flow): void
    {
        [$project, $clashing] = $this->stoppedWithConflict($flow, 'Commercial');

        $this->resolve($project, $flow, [
            'schedule_id' => $clashing->schedule_id,
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(15)->toDateString(),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Partial Day scheduling is for Residential projects only.');

        // And nothing was written on the way to saying so.
        $clashing->refresh();

        $this->assertFalse($clashing->isPartialDay());
        $this->assertSame($this->day(10)->toDateString(), $clashing->startsOn()->toDateString());
    }

    // ==================================================================
    // Resolving a conflict with a partial day
    // ==================================================================

    /**
     * The headline case: a Residential project's clashing whole-day range is
     * moved onto hours of a free date, and the recovery goes through.
     *     */
    #[DataProvider('recoveryFlows')]
    public function test_a_residential_conflict_is_resolved_by_booking_hours(string $flow): void
    {
        [$project, $clashing] = $this->stoppedWithConflict($flow);

        $report = $this->resolve($project, $flow, [
            'schedule_id' => $clashing->schedule_id,
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(15)->toDateString(),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ])->assertOk()->json();

        $this->assertFalse($report['blocked']);

        $clashing->refresh();

        $this->assertTrue($clashing->isPartialDay());
        $this->assertSame($this->day(15)->toDateString(), $clashing->startsOn()->toDateString());
        $this->assertSame('8:00 AM - 12:00 PM', $clashing->timeRange());

        // The past range is untouched by all of this.
        $this->assertSame(2, Schedule::where('project_id', $project->project_id)->count());

        $this->commit($project, $flow)->assertOk();
    }

    /**
     * Two partial days on the same date do not clash when their hours do not
     * meet, and do when they do - which is the whole reason a partial day is
     * worth offering as a resolution.
     */
    public function test_hours_that_miss_each_other_are_free_and_hours_that_meet_are_not(): void
    {
        $flow = ProjectScheduleRecovery::FLOW_RESTORE;

        // The other project holds the morning of day 10 and nothing else.
        [$project, $clashing] = $this->stoppedWithConflict($flow, 'Residential', 10, 10, '08:00', '12:00');

        // The afternoon of the very same date is free.
        $this->resolve($project, $flow, [
            'schedule_id' => $clashing->schedule_id,
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(10)->toDateString(),
            'start_time' => '13:00',
            'end_time' => '17:00',
        ])->assertOk()->assertJsonPath('blocked', false);

        // The morning is not.
        $this->resolve($project, $flow, [
            'schedule_id' => $clashing->schedule_id,
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(10)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '11:00',
        ])->assertStatus(422);

        $clashing->refresh();

        $this->assertSame('1:00 PM - 5:00 PM', $clashing->timeRange());
    }

    /**
     * A technician booked for the WHOLE of a day is unavailable for part of it
     * too.
     *
     * This is the failure a second, date-only conflict system would have
     * introduced, and the reason there is not one: a whole-day booking
     * occupies every minute of every day it covers, so an hours-only request
     * lands on that occupancy like anything else.
     *     */
    #[DataProvider('recoveryFlows')]
    public function test_a_partial_day_cannot_step_around_a_full_day_booking(string $flow): void
    {
        [$project, $clashing] = $this->stoppedWithConflict($flow);

        $response = $this->resolve($project, $flow, [
            'schedule_id' => $clashing->schedule_id,
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            // One hour, squarely inside the other project's whole-day range.
            'project_date' => $this->day(11)->toDateString(),
            'start_time' => '08:00',
            'end_time' => '09:00',
        ])->assertStatus(422);

        $error = (string) $response->json('error');

        $this->assertStringContainsString('Ana Mendoza', $error);
        $this->assertStringContainsString('for the whole day', $error);

        $clashing->refresh();

        $this->assertFalse($clashing->isPartialDay());
    }

    /**
     * And the picker is told the same thing: a day the team is booked on for
     * the whole of it appears in BOTH lists, so it is greyed out for an
     * hours-only booking as well as for a whole-day one.
     *     */
    #[DataProvider('recoveryFlows')]
    public function test_a_full_day_booking_greys_the_day_out_for_partial_days_too(string $flow): void
    {
        [$project, $clashing] = $this->stoppedWithConflict($flow);

        $blocked = $this->rangeFor($this->report($project, $flow), $clashing)['blocked_dates'];

        $busy = $this->day(11)->toDateString();

        $this->assertContains($busy, $blocked['whole_day']);
        $this->assertContains($busy, $blocked['partial_day']);
    }

    // ==================================================================
    // The hours come from Project Settings
    // ==================================================================

    #[DataProvider('recoveryFlows')]
    public function test_the_offered_hours_are_the_configured_ones(string $flow): void
    {
        $this->storeHours('07:00', '15:00');

        [$project, $clashing] = $this->stoppedWithConflict($flow);

        $range = $this->rangeFor($this->report($project, $flow), $clashing);

        $this->assertSame('07:00', $range['default_start_time']);
        $this->assertSame('15:00', $range['default_end_time']);
        $this->assertSame('7:00 AM', $range['partial_day_start_label']);
        $this->assertSame('3:00 PM', $range['partial_day_end_label']);

        $hours = array_column($range['hour_options'], 'value');

        $this->assertSame('07:00', $hours[0]);
        $this->assertSame('15:00', end($hours));
        $this->assertNotContains('06:00', $hours);
        $this->assertNotContains('16:00', $hours);
    }

    /**
     * The administrator may still choose any other hour inside that window -
     * the settings are a window, not a fixed pair - and an hour outside it is
     * refused by the same rules every other scheduling screen is validated by.
     *     */
    #[DataProvider('recoveryFlows')]
    public function test_the_hours_may_be_adjusted_inside_the_window_and_not_outside_it(string $flow): void
    {
        $this->storeHours('07:00', '15:00');

        [$project, $clashing] = $this->stoppedWithConflict($flow);

        $this->resolve($project, $flow, [
            'schedule_id' => $clashing->schedule_id,
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(15)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '14:00',
        ])->assertOk();

        $this->assertSame('9:00 AM - 2:00 PM', $clashing->refresh()->timeRange());

        // An hour past the configured end is refused, whatever a picker showed.
        $this->resolve($project, $flow, [
            'schedule_id' => $clashing->schedule_id,
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(15)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '18:00',
        ])->assertStatus(422);

        // Half past nine is not a finer hour, it is one nothing downstream
        // could honour.
        $this->resolve($project, $flow, [
            'schedule_id' => $clashing->schedule_id,
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(15)->toDateString(),
            'start_time' => '09:30',
            'end_time' => '14:00',
        ])->assertStatus(422);

        $this->assertSame('9:00 AM - 2:00 PM', $clashing->refresh()->timeRange());
    }

    // ==================================================================
    // Ranges: past, future, several of them
    // ==================================================================

    /**
     * A range that has entirely ended is shown, is not screened, is not
     * editable, and cannot be changed even by posting at the endpoint.
     *     */
    #[DataProvider('recoveryFlows')]
    public function test_a_past_range_is_history_in_every_flow(string $flow): void
    {
        [$project] = $this->stoppedWithConflict($flow);

        $past = Schedule::where('project_id', $project->project_id)
            ->orderBy('start_datetime')
            ->first();

        $range = $this->rangeFor($this->report($project, $flow), $past);

        $this->assertSame('past', $range['state']);
        $this->assertFalse($range['editable']);
        $this->assertFalse($range['removable']);
        $this->assertNull($range['conflict']);

        $this->resolve($project, $flow, [
            'schedule_id' => $past->schedule_id,
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(15)->toDateString(),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ])->assertStatus(422);

        $this->assertFalse($past->refresh()->isPartialDay());
    }

    /**
     * Several ranges, one of them in the way: the others are reported
     * available and are left exactly as they stand by the resolution.
     *     */
    #[DataProvider('recoveryFlows')]
    public function test_only_the_conflicting_range_is_touched(string $flow): void
    {
        $ana = $this->technician('Ana Mendoza');

        $project = $this->project([$ana]);
        $past = $this->book($project, -8, -6);
        $free = $this->book($project, 4, 5);
        $clashing = $this->book($project, 10, 12);

        $project = $flow === ProjectScheduleRecovery::FLOW_RESUME
            ? $this->hold($project)
            : $this->archive($project);

        $other = $this->project([$ana], 'Commercial');
        $this->book($other, 10, 12);

        $report = $this->report($project, $flow);

        $this->assertSame('past', $this->rangeFor($report, $past)['state']);
        $this->assertSame('available', $this->rangeFor($report, $free)['state']);
        $this->assertSame('conflict', $this->rangeFor($report, $clashing)['state']);

        $this->resolve($project, $flow, [
            'schedule_id' => $clashing->schedule_id,
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(15)->toDateString(),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ])->assertOk()->assertJsonPath('blocked', false);

        $this->assertSame(
            [$this->day(-8)->toDateString(), $this->day(4)->toDateString()],
            [$past->refresh()->startsOn()->toDateString(), $free->refresh()->startsOn()->toDateString()]
        );
        $this->assertSame($this->day(5)->toDateString(), $free->endsOn()->toDateString());
    }

    /**
     * A range this project's other ranges already hold is refused as well as
     * one the team is booked on elsewhere - a project may not book itself
     * twice over the same time.
     *     */
    #[DataProvider('recoveryFlows')]
    public function test_a_range_may_not_be_moved_on_top_of_its_own_siblings(string $flow): void
    {
        $ana = $this->technician('Ana Mendoza');

        $project = $this->project([$ana]);
        $this->book($project, 4, 5);
        $clashing = $this->book($project, 10, 12);

        $project = $flow === ProjectScheduleRecovery::FLOW_RESUME
            ? $this->hold($project)
            : $this->archive($project);

        $other = $this->project([$ana], 'Commercial');
        $this->book($other, 10, 12);

        $blocked = $this->rangeFor($this->report($project, $flow), $clashing)['blocked_dates'];

        $this->assertContains($this->day(4)->toDateString(), $blocked['whole_day']);
        $this->assertContains($this->day(4)->toDateString(), $blocked['partial_day']);

        $this->resolve($project, $flow, [
            'schedule_id' => $clashing->schedule_id,
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(4)->toDateString(),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'This project already has a schedule range covering that time.');
    }

    // ==================================================================
    // The team
    // ==================================================================

    /**
     * Every technician on the team has to be free, not just one of them.
     *     */
    #[DataProvider('recoveryFlows')]
    public function test_a_second_technician_is_screened_too(string $flow): void
    {
        $ana = $this->technician('Ana Mendoza');
        $ben = $this->technician('Ben Cruz');

        $project = $this->project([$ana, $ben]);
        $clashing = $this->book($project, 10, 12);

        $project = $flow === ProjectScheduleRecovery::FLOW_RESUME
            ? $this->hold($project)
            : $this->archive($project);

        // Only Ben is busy, and only on the morning of the date being moved to.
        $other = $this->project([$ben], 'Commercial');
        $this->book($other, 15, 15, '08:00', '12:00');

        $this->resolve($project, $flow, [
            'schedule_id' => $clashing->schedule_id,
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(15)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '11:00',
        ])->assertStatus(422);

        // The afternoon suits them both.
        $this->resolve($project, $flow, [
            'schedule_id' => $clashing->schedule_id,
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(15)->toDateString(),
            'start_time' => '13:00',
            'end_time' => '16:00',
        ])->assertOk()->assertJsonPath('blocked', false);
    }

    /**
     * Taking the technician off the team is the other way out of a clash, and
     * it clears it in every flow: the screening asks about the team as it
     * stands now, not as it stood when the project stopped.
     *     */
    #[DataProvider('recoveryFlows')]
    public function test_removing_the_technician_from_the_team_clears_the_conflict(string $flow): void
    {
        [$project, , $ana] = $this->stoppedWithConflict($flow);

        $this->assertTrue($this->report($project, $flow)['blocked']);

        ProjectTechnician::where('project_id', $project->project_id)
            ->where('technician_id', $ana->technician_id)
            ->delete();

        $report = $this->report($project, $flow);

        $this->assertFalse($report['blocked']);
        $this->assertSame(0, $report['project']['technician_count']);

        $this->commit($project, $flow)->assertOk();
    }

    // ==================================================================
    // No conflict at all
    // ==================================================================

    #[DataProvider('recoveryFlows')]
    public function test_a_project_with_no_conflict_comes_back_without_a_dialog(string $flow): void
    {
        $ana = $this->technician('Ana Mendoza');

        $project = $this->project([$ana]);
        $this->book($project, 10, 12);

        $project = $flow === ProjectScheduleRecovery::FLOW_RESUME
            ? $this->hold($project)
            : $this->archive($project);

        $report = $this->report($project, $flow);

        $this->assertFalse($report['blocked']);
        $this->assertNull($report['message']);

        $this->commit($project, $flow)->assertOk();
    }

    /**
     * A Residential project with an hours-only range already on it is reported
     * as one, with its own hours selected rather than the window's defaults.
     *     */
    #[DataProvider('recoveryFlows')]
    public function test_an_existing_partial_day_range_keeps_its_own_hours(string $flow): void
    {
        $ana = $this->technician('Ana Mendoza');

        $project = $this->project([$ana]);
        $hours = $this->book($project, 10, 10, '09:00', '11:00');

        $project = $flow === ProjectScheduleRecovery::FLOW_RESUME
            ? $this->hold($project)
            : $this->archive($project);

        $range = $this->rangeFor($this->report($project, $flow), $hours);

        $this->assertTrue($range['partial_day']);
        $this->assertSame('09:00', $range['start_time']);
        $this->assertSame('11:00', $range['end_time']);
        $this->assertSame('09:00', $range['default_start_time']);
        $this->assertSame('11:00', $range['default_end_time']);
    }

    // ==================================================================
    // Resume specifically: the preserved schedule
    // ==================================================================

    /**
     * A hold preserves the days still to come as the project's proposal, and
     * Resume is what checks them against the calendar as it stands - rather
     * than putting them back into force with nobody told.
     */
    public function test_resume_screens_the_schedule_the_hold_preserved(): void
    {
        [$project, $clashing] = $this->stoppedWithConflict(ProjectScheduleRecovery::FLOW_RESUME);

        // The proposal is still there to be screened, which is the change this
        // whole flow rests on.
        $this->assertSame($this->day(10)->toDateString(), $clashing->startsOn()->toDateString());

        $this->putJson(route('super-admin.projects.resume', $project->project_id))
            ->assertStatus(409)
            ->assertJsonPath('conflicts.blocked', true)
            ->assertJsonPath('conflicts.flow.key', 'resume');

        // Refused, and nothing about the project was changed on the way to
        // saying so.
        $this->assertTrue((bool) $project->fresh()->on_hold);
        $this->assertSame($this->day(10)->toDateString(), $clashing->refresh()->startsOn()->toDateString());
    }

    /**
     * The refusal names the range and the technician, which is what turns it
     * into something a person can act on.
     */
    public function test_the_resume_refusal_names_the_range_and_the_technician(): void
    {
        [$project, $clashing] = $this->stoppedWithConflict(ProjectScheduleRecovery::FLOW_RESUME);

        $report = $this->putJson(route('super-admin.projects.resume', $project->project_id))
            ->assertStatus(409)
            ->json('conflicts');

        $range = $this->rangeFor($report, $clashing);

        $this->assertSame('conflict', $range['state']);
        $this->assertSame(['Ana Mendoza'], $range['conflict']['technicians']);
        $this->assertNotEmpty($range['conflict']['projects']);
        $this->assertStringContainsString('Ana Mendoza', (string) $report['message']);
    }

    /**
     * A held project holds nobody, preserved dates or not - which is what lets
     * the other work be booked over them in the first place, and why the
     * screening is needed at all.
     */
    public function test_a_held_projects_preserved_dates_do_not_occupy_its_crew(): void
    {
        $ana = $this->technician('Ana Mendoza');

        $project = $this->project([$ana]);
        $this->book($project, 10, 12);
        $this->hold($project);

        $conflicts = app(TechnicianAvailabilityService::class)->findConflicts(
            [$ana->technician_id],
            [['start' => $this->day(10), 'end' => $this->day(12)]]
        );

        $this->assertTrue($conflicts->isEmpty());
    }

    // ==================================================================
    // Reopen
    // ==================================================================

    /**
     * A project awaiting client confirmation reopens onto hours when its
     * client is Residential.
     */
    public function test_a_residential_project_reopens_onto_a_partial_day(): void
    {
        $ana = $this->technician('Ana Mendoza');

        $project = $this->project([$ana], 'Residential', 'ongoing');
        $this->book($project, -8, -6);
        $project->forceFill([
            'status' => 'awaiting_client_confirmation',
            'completion_requested_at' => CarbonImmutable::now(),
        ])->save();

        $this->post(route('super-admin.projects.reopen', $project->project_id), [
            'reopen_reason' => 'The client reported a fault with the installation.',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(5)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '12:00',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $booked = Schedule::where('project_id', $project->project_id)
            ->orderByDesc('schedule_id')
            ->first();

        $this->assertTrue($booked->isPartialDay());
        $this->assertSame('9:00 AM - 12:00 PM', $booked->timeRange());
        $this->assertSame('ongoing', $project->fresh()->status);
    }

    /**
     * And a Commercial one does not, whatever it posts - the same refusal the
     * restore and resume endpoints give.
     */
    public function test_a_commercial_project_cannot_reopen_onto_a_partial_day(): void
    {
        $ana = $this->technician('Ana Mendoza');

        $project = $this->project([$ana], 'Commercial', 'ongoing');
        $this->book($project, -8, -6);
        $project->forceFill([
            'status' => 'awaiting_client_confirmation',
            'completion_requested_at' => CarbonImmutable::now(),
        ])->save();

        $this->post(route('super-admin.projects.reopen', $project->project_id), [
            'reopen_reason' => 'The client reported a fault with the installation.',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(5)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '12:00',
        ])->assertSessionHas('error');

        $this->assertSame(1, Schedule::where('project_id', $project->project_id)->count());
        $this->assertSame('awaiting_client_confirmation', $project->fresh()->status);
    }

    /**
     * The Reopen dialog offers the configured Partial Day hours, and offers
     * them filled in - so the window in force is visible the moment the mode
     * is chosen rather than hidden behind a "Select".
     */
    public function test_the_reopen_dialog_shows_the_configured_partial_day_hours(): void
    {
        $this->storeHours('07:00', '15:00');

        $ana = $this->technician('Ana Mendoza');

        $project = $this->project([$ana], 'Residential', 'ongoing');
        $this->book($project, -8, -6);
        $project->forceFill([
            'status' => 'awaiting_client_confirmation',
            'completion_requested_at' => CarbonImmutable::now(),
        ])->save();

        $response = $this->get(route('super-admin.projects.show', $project->project_id))->assertOk();

        $response->assertSee('reopenStartTime', false);
        $response->assertSee('Partial Day books only these hours on the one date, between', false);
        $response->assertSee('7:00 AM', false);
        $response->assertSee('3:00 PM', false);
        // Neither bound of the old window is offered any more.
        $response->assertDontSee('value="06:00"', false);
        $response->assertDontSee('value="17:00"', false);
    }

    /**
     * The Reopen dialog's date pickers carry a Reset of their own, appended
     * inside each calendar - which is where somebody is looking when they find
     * they cannot pick the date they want.
     */
    public function test_the_reopen_dialog_offers_a_reset_inside_its_date_pickers(): void
    {
        $ana = $this->technician('Ana Mendoza');

        $project = $this->project([$ana], 'Residential', 'ongoing');
        $this->book($project, -8, -6);
        $project->forceFill([
            'status' => 'awaiting_client_confirmation',
            'completion_requested_at' => CarbonImmutable::now(),
        ])->save();

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('/js/super-admin/reopenProject.js', false)
            // The footer that styles it, which flatpickr appends to <body> and
            // so cannot be reached by a rule scoped to the dialog.
            ->assertSee('/css/super-admin/restoreConflicts.css', false);

        $this->assertStringContainsString(
            'conflict-picker-clear',
            (string) file_get_contents(public_path('js/super-admin/reopenProject.js'))
        );
    }

    // ==================================================================
    // One service, three flows
    // ==================================================================

    /**
     * The two dialogs post at their own endpoints and call themselves by their
     * own names, and everything underneath is the same code.
     */
    public function test_each_flow_names_itself_and_its_own_endpoints(): void
    {
        [$archived] = $this->stoppedWithConflict(ProjectScheduleRecovery::FLOW_RESTORE);
        [$held] = $this->stoppedWithConflict(ProjectScheduleRecovery::FLOW_RESUME);

        $restore = $this->report($archived, ProjectScheduleRecovery::FLOW_RESTORE);
        $resume = $this->report($held, ProjectScheduleRecovery::FLOW_RESUME);

        $this->assertSame('restore', $restore['flow']['key']);
        $this->assertSame('Restore Project', $restore['flow']['action_label']);
        $this->assertStringContainsString('restore-conflicts', $restore['flow']['conflicts_url']);
        $this->assertStringContainsString('restore-schedule', $restore['project']['update_url']);

        $this->assertSame('resume', $resume['flow']['key']);
        $this->assertSame('Resume Project', $resume['flow']['action_label']);
        $this->assertStringContainsString('resume-conflicts', $resume['flow']['conflicts_url']);
        $this->assertStringContainsString('resume-schedule', $resume['project']['update_url']);
    }

    /**
     * Neither endpoint is a wider door than the action it belongs to: the
     * administrators' group turns a technician away before the controller is
     * reached, and nothing about the schedule is touched.
     */
    public function test_the_resume_endpoints_are_closed_to_a_technician(): void
    {
        [$project, $clashing] = $this->stoppedWithConflict(ProjectScheduleRecovery::FLOW_RESUME);

        $crew = User::factory()->create(['email' => 'crew@example.test']);
        $crew->forceFill(['role' => 'technician'])->save();

        $this->actingAs($crew);

        $this->getJson(route('super-admin.projects.resume-conflicts', $project->project_id))
            ->assertForbidden();

        // Signed in again, because being turned away ends the session.
        $this->actingAs($crew);

        $this->putJson(route('super-admin.projects.resume-schedule', $project->project_id), [
            'schedule_id' => $clashing->schedule_id,
            'action' => 'remove',
        ])->assertForbidden();

        // And the range it tried to drop is still there.
        $this->assertNotNull($clashing->fresh());
    }
}
