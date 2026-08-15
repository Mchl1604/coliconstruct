<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Technician;
use App\Models\User;
use App\Services\TechnicianAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The availability rules, exercised directly against the service rather than
 * through a screen.
 *
 * Half of this file exists to prove the whole-day answers did not move when
 * partial days were added: every scheduling screen leans on them.
 */
class TechnicianAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private function availability(): TechnicianAvailabilityService
    {
        return app(TechnicianAvailabilityService::class);
    }

    private function createTechnician(string $name): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => 'technician'])->save();

        return Technician::create([
            'account_id' => $user->id,
            'role' => 'technician',
        ]);
    }

    /**
     * Book a technician on a whole-day range for another project.
     */
    private function bookWholeDays(
        Technician $technician,
        string $startDate,
        string $endDate,
        string $projectStatus = 'ongoing'
    ): Project {
        return $this->book(
            $technician,
            $startDate.' 00:00:00',
            $endDate.' 23:59:59',
            Schedule::MODE_DATE_BASED,
            $projectStatus
        );
    }

    /**
     * Book a technician for part of one day, 'HH:MM' to 'HH:MM'.
     */
    private function bookHours(
        Technician $technician,
        string $date,
        string $startTime,
        string $endTime,
        string $projectStatus = 'ongoing'
    ): Project {
        return $this->book(
            $technician,
            $date.' '.$startTime.':00',
            $date.' '.$endTime.':00',
            Schedule::MODE_PARTIAL_DAY,
            $projectStatus
        );
    }

    private function book(
        Technician $technician,
        string $startDatetime,
        string $endDatetime,
        string $mode,
        string $projectStatus
    ): Project {
        $project = Project::create([
            'name' => 'Project '.uniqid(),
            'status' => $projectStatus,
            'address' => 'Address',
            'description' => 'Description',
        ]);

        $projectTechnician = ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);

        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $startDatetime,
            'end_datetime' => $endDatetime,
            'scheduling_mode' => $mode,
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        ScheduleTechnician::create([
            'schedule_id' => $schedule->schedule_id,
            'project_technician_id' => $projectTechnician->project_technician_id,
        ]);

        return $project;
    }

    private function day(int $offset): string
    {
        return CarbonImmutable::today()->addDays($offset)->toDateString();
    }

    /**
     * @return array<int, array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function wholeDayRange(string $startDate, string $endDate): array
    {
        return [[
            'start' => CarbonImmutable::parse($startDate)->startOfDay(),
            'end' => CarbonImmutable::parse($endDate)->startOfDay(),
        ]];
    }

    /**
     * @return array<int, array{start: CarbonImmutable, end: CarbonImmutable, mode: string}>
     */
    private function hoursRange(string $date, string $startTime, string $endTime): array
    {
        return [[
            'start' => CarbonImmutable::parse($date.' '.$startTime),
            'end' => CarbonImmutable::parse($date.' '.$endTime),
            'mode' => Schedule::MODE_PARTIAL_DAY,
        ]];
    }

    // -----------------------------------------------------------------
    // Whole-day behaviour, unchanged
    // -----------------------------------------------------------------

    public function test_a_whole_day_range_still_clashes_with_an_overlapping_whole_day_range(): void
    {
        $technician = $this->createTechnician('Jose Garcia');
        $this->bookWholeDays($technician, $this->day(10), $this->day(12));

        $conflicts = $this->availability()->findConflicts(
            [$technician->technician_id],
            $this->wholeDayRange($this->day(11), $this->day(13))
        );

        $this->assertCount(1, $conflicts);
        $this->assertSame(
            [$this->day(11), $this->day(12)],
            $conflicts->first()['dates']
        );
        $this->assertStringContainsString(
            'is unavailable on',
            $this->availability()->conflictMessage($conflicts)
        );
    }

    public function test_a_whole_day_range_clear_of_every_booking_reports_nothing(): void
    {
        $technician = $this->createTechnician('Ana Mendoza');
        $this->bookWholeDays($technician, $this->day(20), $this->day(21));

        $this->assertTrue(
            $this->availability()->findConflicts(
                [$technician->technician_id],
                $this->wholeDayRange($this->day(10), $this->day(12))
            )->isEmpty()
        );
    }

    /**
     * The rule the whole design rests on: endpoints free, middle day booked.
     */
    public function test_a_busy_middle_day_still_breaks_a_whole_day_range(): void
    {
        $technician = $this->createTechnician('Kevin Lopez');
        $this->bookWholeDays($technician, $this->day(11), $this->day(11));

        $conflicts = $this->availability()->findConflicts(
            [$technician->technician_id],
            $this->wholeDayRange($this->day(10), $this->day(12))
        );

        $this->assertSame([$this->day(11)], $conflicts->first()['dates']);
    }

    /**
     * Every row written before scheduling modes existed is a whole-day range,
     * and nothing that writes one names a mode. Inserting without the column -
     * exactly what the existing code paths do - must therefore still produce a
     * whole-day booking.
     */
    public function test_a_row_written_without_a_mode_is_a_whole_day_booking(): void
    {
        $technician = $this->createTechnician('Maria Santos');

        $project = Project::create([
            'name' => 'Legacy Project',
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
        ]);

        $projectTechnician = ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);

        // No scheduling_mode anywhere in the insert, and none in the model's
        // payload either - the shape every existing writer produces.
        DB::table('tbl_schedule')->insert([
            'project_id' => $project->project_id,
            'start_datetime' => $this->day(10).' 00:00:00',
            'end_datetime' => $this->day(10).' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Legacy booking',
        ]);

        $schedule = Schedule::where('project_id', $project->project_id)->firstOrFail();

        ScheduleTechnician::create([
            'schedule_id' => $schedule->schedule_id,
            'project_technician_id' => $projectTechnician->project_technician_id,
        ]);

        $this->assertTrue($schedule->isDateBased());

        // A whole-day request collides with it...
        $this->assertCount(1, $this->availability()->findConflicts(
            [$technician->technician_id],
            $this->wholeDayRange($this->day(10), $this->day(10))
        ));

        // ...and so does an hours-only one, because it takes the whole day.
        $conflicts = $this->availability()->findConflicts(
            [$technician->technician_id],
            $this->hoursRange($this->day(10), '13:00', '15:00')
        );

        $this->assertStringContainsString(
            'for the whole day',
            $this->availability()->conflictMessage($conflicts)
        );
    }

    public function test_bookings_on_completed_and_cancelled_projects_never_block_anything(): void
    {
        $technician = $this->createTechnician('Rosa Cruz');

        $this->bookWholeDays($technician, $this->day(10), $this->day(10), 'completed');
        $this->bookHours($technician, $this->day(10), '08:00', '10:00', 'cancelled');

        $this->assertTrue($this->availability()->findConflicts(
            [$technician->technician_id],
            $this->wholeDayRange($this->day(10), $this->day(10))
        )->isEmpty());

        $this->assertTrue($this->availability()->findConflicts(
            [$technician->technician_id],
            $this->hoursRange($this->day(10), '08:00', '10:00')
        )->isEmpty());
    }

    // -----------------------------------------------------------------
    // Hours-only behaviour
    // -----------------------------------------------------------------

    public function test_two_partial_days_on_one_date_are_allowed_when_their_hours_do_not_meet(): void
    {
        $technician = $this->createTechnician('Ben Reyes');
        $this->bookHours($technician, $this->day(10), '08:00', '10:00');

        $this->assertTrue($this->availability()->findConflicts(
            [$technician->technician_id],
            $this->hoursRange($this->day(10), '13:00', '15:00')
        )->isEmpty());
    }

    public function test_overlapping_hours_on_one_date_are_rejected_and_the_message_names_them(): void
    {
        $technician = $this->createTechnician('Carlo Diaz');
        $this->bookHours($technician, $this->day(10), '08:00', '10:00');

        $conflicts = $this->availability()->findConflicts(
            [$technician->technician_id],
            $this->hoursRange($this->day(10), '09:00', '11:00')
        );

        $this->assertCount(1, $conflicts);

        $message = $this->availability()->conflictMessage($conflicts);

        $this->assertStringContainsString('Carlo Diaz is already booked', $message);
        $this->assertStringContainsString('8:00 AM - 10:00 AM', $message);
        $this->assertStringContainsString('Please choose a time', $message);
    }

    /**
     * Finishing at 10 and starting again at 10 is a working day, not a clash.
     */
    public function test_hours_that_touch_at_the_boundary_do_not_clash(): void
    {
        $technician = $this->createTechnician('Elena Bautista');
        $this->bookHours($technician, $this->day(10), '08:00', '10:00');

        $this->assertTrue($this->availability()->findConflicts(
            [$technician->technician_id],
            $this->hoursRange($this->day(10), '10:00', '12:00')
        )->isEmpty());
    }

    public function test_a_partial_day_is_free_on_every_other_date(): void
    {
        $technician = $this->createTechnician('Nina Flores');
        $this->bookHours($technician, $this->day(10), '08:00', '17:00');

        $this->assertTrue($this->availability()->findConflicts(
            [$technician->technician_id],
            $this->hoursRange($this->day(11), '08:00', '10:00')
        )->isEmpty());
    }

    // -----------------------------------------------------------------
    // The two kinds, mixed
    // -----------------------------------------------------------------

    public function test_a_whole_day_range_is_blocked_by_a_partial_day_inside_it(): void
    {
        $technician = $this->createTechnician('Paolo Ramos');
        $this->bookHours($technician, $this->day(11), '08:00', '10:00');

        $conflicts = $this->availability()->findConflicts(
            [$technician->technician_id],
            $this->wholeDayRange($this->day(10), $this->day(12))
        );

        $this->assertSame([$this->day(11)], $conflicts->first()['dates']);
        $this->assertStringContainsString(
            'is unavailable on',
            $this->availability()->conflictMessage($conflicts)
        );
    }

    public function test_a_partial_day_is_blocked_by_a_whole_day_booking_on_that_date(): void
    {
        $technician = $this->createTechnician('Grace Villanueva');
        $this->bookWholeDays($technician, $this->day(10), $this->day(12));

        $conflicts = $this->availability()->findConflicts(
            [$technician->technician_id],
            $this->hoursRange($this->day(11), '08:00', '10:00')
        );

        $this->assertCount(1, $conflicts);
        $this->assertStringContainsString(
            'for the whole day',
            $this->availability()->conflictMessage($conflicts)
        );
    }

    // -----------------------------------------------------------------
    // The day-level map the calendar and the team picker read
    // -----------------------------------------------------------------

    public function test_the_day_owner_map_still_tags_each_busy_day_with_its_project(): void
    {
        $technician = $this->createTechnician('Luis Ocampo');
        $wholeDayProject = $this->bookWholeDays($technician, $this->day(10), $this->day(10));
        $partialDayProject = $this->bookHours($technician, $this->day(11), '08:00', '10:00');

        $owners = $this->availability()->unavailableDayOwners(
            [$technician->technician_id],
            $this->wholeDayRange($this->day(10), $this->day(11))
        );

        $technicianId = (int) $technician->technician_id;

        $this->assertSame(
            [(int) $wholeDayProject->project_id => true],
            $owners[$technicianId][$this->day(10)]
        );

        // A date is busy at day level even when only part of it is taken -
        // which is the right answer for the screens that book whole days.
        $this->assertSame(
            [(int) $partialDayProject->project_id => true],
            $owners[$technicianId][$this->day(11)]
        );
    }

    public function test_a_project_is_never_blocked_by_its_own_booking(): void
    {
        $technician = $this->createTechnician('Ivan Torres');
        $project = $this->bookHours($technician, $this->day(10), '08:00', '10:00');

        $this->assertTrue($this->availability()->findConflicts(
            [$technician->technician_id],
            $this->hoursRange($this->day(10), '08:00', '10:00'),
            (int) $project->project_id
        )->isEmpty());
    }

    /**
     * Widening a project onto a free neighbouring day is allowed, and the days
     * it already holds are never held against it.
     *
     * The case that reads as a bug from the outside: booked day 21-23, asked
     * for day 20-24, with the technician free on both new days. Nothing should
     * be reported at all - the overlap with day 21-23 is this project's own.
     */
    public function test_widening_a_range_over_a_projects_own_dates_reports_nothing(): void
    {
        $technician = $this->createTechnician('Ana Reyes');
        $project = $this->bookWholeDays($technician, $this->day(21), $this->day(23));

        $conflicts = $this->availability()->findConflicts(
            [$technician->technician_id],
            $this->wholeDayRange($this->day(20), $this->day(24)),
            (int) $project->project_id
        );

        $this->assertTrue(
            $conflicts->isEmpty(),
            'A project must not be blocked by the dates it already holds.'
        );
    }

    /**
     * When something genuinely does block, the message says WHAT.
     *
     * Without the project named, a clash on a date inside the range being
     * edited is indistinguishable from the project blocking itself - which is
     * exactly how a correct refusal came to be read as a bug.
     */
    public function test_a_conflict_names_the_project_holding_the_technician(): void
    {
        $technician = $this->createTechnician('Kevin Lopez');

        // The project being edited, and a different one holding day 20.
        $mine = $this->bookWholeDays($technician, $this->day(21), $this->day(23));
        $other = $this->bookWholeDays($technician, $this->day(20), $this->day(20));

        $other->forceFill(['reference_no' => 'PRJ-OTHER-0001'])->save();

        $conflicts = $this->availability()->findConflicts(
            [$technician->technician_id],
            $this->wholeDayRange($this->day(20), $this->day(24)),
            (int) $mine->project_id
        );

        $this->assertCount(1, $conflicts);

        // Only the other project's day is reported; days 21-23 are this
        // project's own and are not held against it.
        $this->assertSame([$this->day(20)], $conflicts->first()['dates']);
        $this->assertSame(['PRJ-OTHER-0001'], $conflicts->first()['projects']);

        $message = $this->availability()->conflictMessage($conflicts);

        $this->assertStringContainsString('Kevin Lopez', $message);
        $this->assertStringContainsString('(booked on PRJ-OTHER-0001)', $message);
    }

    public function test_assert_continuously_available_throws_with_the_conflict_message(): void
    {
        $technician = $this->createTechnician('Mika Santos');
        $this->bookHours($technician, $this->day(10), '08:00', '10:00');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is already booked');

        $this->availability()->assertContinuouslyAvailable(
            [$technician->technician_id],
            $this->hoursRange($this->day(10), '09:00', '11:00')
        );
    }
}
