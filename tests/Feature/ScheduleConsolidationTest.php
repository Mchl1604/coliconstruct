<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Technician;
use App\Models\User;
use App\Services\ScheduleConsolidation;
use App\Services\TechnicianAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two bookings that run into each other are one booking.
 *
 * Aug 20-21 followed by Aug 22-24 is not two visits with a break between them;
 * it is Aug 20-24. A gap of even one day is a real gap and is left alone, and
 * partial days never merge with anything - they book hours, not days.
 */
class ScheduleConsolidationTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Technician $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();

        $this->project = Project::create([
            'name' => 'Consolidation Project',
            'reference_no' => 'REF-CONSOLIDATE',
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
        ]);

        $user = User::factory()->create(['name' => 'Ana Cruz', 'email' => 'ana.cruz@example.test']);
        $user->forceFill(['role' => 'technician'])->save();

        $this->technician = Technician::create(['account_id' => $user->id, 'role' => 'technician']);

        ProjectTechnician::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $this->technician->technician_id,
        ]);
    }

    /**
     * A day offset from today, so the fixtures never drift into the past.
     */
    private function day(int $offset): string
    {
        return CarbonImmutable::today()->addDays($offset)->toDateString();
    }

    private function book(int $from, int $to): Schedule
    {
        $schedule = Schedule::create([
            'project_id' => $this->project->project_id,
            'start_datetime' => CarbonImmutable::parse($this->day($from))->startOfDay(),
            'end_datetime' => CarbonImmutable::parse($this->day($to))->endOfDay(),
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'status' => 'scheduled',
        ]);

        ProjectTechnician::where('project_id', $this->project->project_id)
            ->pluck('project_technician_id')
            ->each(fn ($id) => ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $id,
            ]));

        return $schedule;
    }

    private function bookHours(int $day, string $from, string $to): Schedule
    {
        $date = CarbonImmutable::parse($this->day($day));

        return Schedule::create([
            'project_id' => $this->project->project_id,
            'start_datetime' => $date->setTimeFromTimeString($from),
            'end_datetime' => $date->setTimeFromTimeString($to),
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'status' => 'scheduled',
        ]);
    }

    private function consolidate(): int
    {
        return app(ScheduleConsolidation::class)->consolidate($this->project);
    }

    /**
     * @return array<int, string> "start..end" per remaining row, in order
     */
    private function ranges(): array
    {
        return Schedule::query()
            ->where('project_id', $this->project->project_id)
            ->orderBy('start_datetime')
            ->get()
            ->map(fn (Schedule $schedule): string => $schedule->startsOn()->toDateString()
                .'..'.$schedule->endsOn()->toDateString())
            ->all();
    }

    // ==================================================================
    // Merging
    // ==================================================================

    /** The headline case: Aug 20-21 + Aug 22-24 becomes Aug 20-24. */
    public function test_two_touching_ranges_become_one(): void
    {
        $this->book(20, 21);
        $this->book(22, 24);

        $this->assertSame(1, $this->consolidate());

        $this->assertSame([$this->day(20).'..'.$this->day(24)], $this->ranges());
    }

    /** A whole chain collapses, and a separate run beside it survives. */
    public function test_a_run_of_three_collapses_and_a_gap_is_kept(): void
    {
        $this->book(20, 22);
        $this->book(23, 25);
        $this->book(28, 30);

        $this->assertSame(1, $this->consolidate());

        $this->assertSame([
            $this->day(20).'..'.$this->day(25),
            $this->day(28).'..'.$this->day(30),
        ], $this->ranges());
    }

    /** Single days that touch are still one stretch. */
    public function test_two_touching_single_days_become_one_range(): void
    {
        $this->book(20, 20);
        $this->book(21, 21);

        $this->assertSame(1, $this->consolidate());

        $this->assertSame([$this->day(20).'..'.$this->day(21)], $this->ranges());
    }

    /** The rows are merged whatever order they were written in. */
    public function test_ranges_written_out_of_order_still_merge(): void
    {
        $this->book(22, 24);
        $this->book(20, 21);

        $this->assertSame(1, $this->consolidate());

        $this->assertSame([$this->day(20).'..'.$this->day(24)], $this->ranges());
    }

    // ==================================================================
    // Not merging
    // ==================================================================

    /** One missing day is a real gap. */
    public function test_a_one_day_gap_is_left_alone(): void
    {
        $this->book(20, 22);
        $this->book(24, 26);

        $this->assertSame(0, $this->consolidate());

        $this->assertSame([
            $this->day(20).'..'.$this->day(22),
            $this->day(24).'..'.$this->day(26),
        ], $this->ranges());
    }

    // ==================================================================
    // Partial days
    // ==================================================================

    /**
     * A partial day books hours, not a day. Folding it into a whole-day range
     * would claim time nobody booked and mark the crew busy for it.
     */
    public function test_a_partial_day_never_merges_into_a_date_range(): void
    {
        $this->book(20, 21);
        $this->bookHours(22, '08:00', '12:00');

        $this->assertSame(0, $this->consolidate());
        $this->assertSame(2, Schedule::where('project_id', $this->project->project_id)->count());

        $partial = Schedule::where('project_id', $this->project->project_id)
            ->where('scheduling_mode', Schedule::MODE_PARTIAL_DAY)
            ->first();

        $this->assertNotNull($partial, 'The partial day must survive untouched.');
        $this->assertSame('08:00', $partial->start_datetime->format('H:i'));
        $this->assertSame('12:00', $partial->end_datetime->format('H:i'));
    }

    /** The same the other way round: a range starting the day after one. */
    public function test_a_date_range_never_absorbs_the_partial_day_before_it(): void
    {
        $this->bookHours(20, '08:00', '12:00');
        $this->book(21, 23);

        $this->assertSame(0, $this->consolidate());
        $this->assertSame(2, Schedule::where('project_id', $this->project->project_id)->count());
    }

    /** Merging two would claim the night between them. */
    public function test_two_partial_days_on_consecutive_dates_stay_apart(): void
    {
        $this->bookHours(20, '08:00', '12:00');
        $this->bookHours(21, '08:00', '12:00');

        $this->assertSame(0, $this->consolidate());
        $this->assertSame(2, Schedule::where('project_id', $this->project->project_id)->count());
    }

    /** A part-booked day between two ranges means they are not continuous. */
    public function test_a_partial_day_between_two_ranges_keeps_them_apart(): void
    {
        $this->book(20, 21);
        $this->bookHours(22, '08:00', '12:00');
        $this->book(23, 24);

        $this->assertSame(0, $this->consolidate());
        $this->assertSame(3, Schedule::where('project_id', $this->project->project_id)->count());
    }

    // ==================================================================
    // What the merged row carries
    // ==================================================================

    /**
     * The crew booked on the absorbed days keeps its booking, on the row that
     * now covers them - otherwise merging would quietly free the technicians
     * for half the range.
     */
    public function test_the_merged_range_keeps_the_technicians_of_both(): void
    {
        $first = $this->book(20, 21);
        $second = $this->book(22, 24);

        $this->consolidate();

        $survivor = Schedule::where('project_id', $this->project->project_id)->first();

        $this->assertSame((int) $first->schedule_id, (int) $survivor->schedule_id, 'The earliest row survives.');
        $this->assertDatabaseMissing('tbl_schedule', ['schedule_id' => $second->schedule_id]);

        $this->assertSame(
            1,
            ScheduleTechnician::where('schedule_id', $survivor->schedule_id)->count()
        );

        // And the technician still reads as busy across the whole stretch.
        $conflicts = app(TechnicianAvailabilityService::class)->findConflicts(
            [$this->technician->technician_id],
            [[
                'start' => CarbonImmutable::parse($this->day(23)),
                'end' => CarbonImmutable::parse($this->day(23)),
            ]]
        );

        $this->assertTrue($conflicts->isNotEmpty(), 'The absorbed days must still book the crew.');
    }

    public function test_consolidating_twice_changes_nothing_the_second_time(): void
    {
        $this->book(20, 21);
        $this->book(22, 24);

        $this->assertSame(1, $this->consolidate());
        $this->assertSame(0, $this->consolidate());
    }

    public function test_a_project_with_one_range_is_untouched(): void
    {
        $this->book(20, 24);

        $this->assertSame(0, $this->consolidate());
        $this->assertSame([$this->day(20).'..'.$this->day(24)], $this->ranges());
    }

    // ==================================================================
    // Through the screens that write schedules
    // ==================================================================

    /** Saving touching ranges from the schedules page stores one row. */
    public function test_the_schedules_editor_stores_touching_ranges_as_one(): void
    {
        $existing = $this->book(20, 21);

        $this->put(route('super-admin.schedules.update', $this->project->project_id), [
            'ranges' => [
                [
                    'schedule_id' => $existing->schedule_id,
                    'start_date' => $this->day(20),
                    'end_date' => $this->day(21),
                ],
                [
                    'start_date' => $this->day(22),
                    'end_date' => $this->day(24),
                ],
            ],
        ])->assertRedirect();

        $this->assertSame([$this->day(20).'..'.$this->day(24)], $this->ranges());
    }

    /** Booking the next day from the calendar extends the existing range. */
    public function test_assigning_the_next_day_from_the_calendar_extends_the_range(): void
    {
        $this->book(20, 21);

        $this->postJson(route('super-admin.schedules.assign'), [
            'project_ids' => [$this->project->project_id],
            'start_date' => $this->day(22),
            'end_date' => $this->day(22),
        ])->assertOk();

        $this->assertSame([$this->day(20).'..'.$this->day(22)], $this->ranges());
    }
}
