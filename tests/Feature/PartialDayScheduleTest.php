<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\ProjectType;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Technician;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Partial-day scheduling: creating it, editing it, and switching a schedule
 * between the two modes.
 *
 * Scheduling mode belongs to the individual schedule row, so a project may
 * hold any mix of the two and each is judged on its own.
 */
class PartialDayScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
    }

    private function createTechnician(string $role, string $name): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => $role])->save();

        return Technician::create([
            'account_id' => $user->id,
            'role' => $role,
        ]);
    }

    private function day(int $offset): string
    {
        return CarbonImmutable::today()->addDays($offset)->toDateString();
    }

    /**
     * The wizard payload, minus whatever the schedule section contributes.
     *
     * @return array<string, mixed>
     */
    private function wizardPayload(Technician $lead, Technician $technician, string $clientType = 'Residential'): array
    {
        $payload = [
            'client_type' => $clientType,
            'surname' => 'Dela Cruz',
            'firstname' => 'Juan',
            'client_email' => 'juan.dela.cruz@example.test',
            'client_phone' => '09123456789',
            'project_address' => '123 Sample Street',
            'quotation_amount' => '1250.00',
            'project_types' => ['Aircon Installation'],
            'assessment_report' => [UploadedFile::fake()->create('assessment.pdf', 12, 'application/pdf')],
            'approved_quotation' => [UploadedFile::fake()->create('quotation.jpg', 12, 'image/jpeg')],
            'project_description' => 'Test project description',
            'lead_tech' => $lead->technician_id,
            'technicians' => [$technician->technician_id],
        ];

        if ($clientType === 'Commercial') {
            $payload['company_name'] = 'Acme Corp';
            $payload['contract'] = [UploadedFile::fake()->create('contract.pdf', 12, 'application/pdf')];
        }

        return $payload;
    }

    /**
     * A Residential project holding one schedule, in whichever mode.
     */
    private function residentialProject(
        Technician $technician,
        string $mode,
        string $startDatetime,
        string $endDatetime,
        string $status = 'ongoing'
    ): Project {
        $project = Project::create([
            'name' => 'Residential Project '.uniqid(),
            'reference_no' => 'REF-'.uniqid(),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Residential',
            'firstname' => 'Juan',
            'surname' => 'Dela Cruz',
            'fullname' => 'Juan Dela Cruz',
            'email_address' => 'client'.uniqid().'@example.test',
            'contact_number' => '09123456789',
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

        return $project->fresh(['schedules', 'clients']);
    }

    /**
     * A project waiting to be booked from the calendar: it has a team but no
     * schedule yet.
     */
    private function schedulableProject(Technician $technician, string $clientType): Project
    {
        $project = Project::create([
            'name' => $clientType.' Project '.uniqid(),
            'reference_no' => 'REF-'.uniqid(),
            'status' => 'unscheduled',
            'address' => 'Address',
            'description' => 'Description',
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => $clientType,
            'firstname' => 'Juan',
            'surname' => 'Dela Cruz',
            'fullname' => 'Juan Dela Cruz',
            'company_name' => $clientType === 'Commercial' ? 'Acme Corp' : null,
            'email_address' => 'client'.uniqid().'@example.test',
            'contact_number' => '09123456789',
        ]);

        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);

        return $project;
    }

    // -----------------------------------------------------------------
    // How a schedule reads
    // -----------------------------------------------------------------

    public function test_describe_covers_all_three_shapes_a_schedule_can_take(): void
    {
        $partialDay = new Schedule([
            'start_datetime' => '2026-08-06 08:00:00',
            'end_datetime' => '2026-08-06 12:00:00',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
        ]);

        $oneDay = new Schedule([
            'start_datetime' => '2026-08-06 00:00:00',
            'end_datetime' => '2026-08-06 23:59:59',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
        ]);

        $severalDays = new Schedule([
            'start_datetime' => '2026-08-06 00:00:00',
            'end_datetime' => '2026-08-09 23:59:59',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
        ]);

        $this->assertSame('Aug 6, 2026 · 8:00 AM - 12:00 PM', $partialDay->describe());
        $this->assertSame('Aug 6, 2026', $oneDay->describe());
        $this->assertSame('Aug 6, 2026 - Aug 9, 2026', $severalDays->describe());
    }

    public function test_the_project_details_page_shows_the_hours_of_a_partial_day(): void
    {
        $technician = $this->createTechnician('technician', 'Nina Flores');

        $project = $this->residentialProject(
            $technician,
            Schedule::MODE_PARTIAL_DAY,
            $this->day(10).' 08:00:00',
            $this->day(10).' 12:00:00'
        );

        $response = $this->get(route('super-admin.projects.show', $project->project_id));

        $response->assertOk();
        $response->assertSee(
            CarbonImmutable::parse($this->day(10))->format('M j, Y').' · 8:00 AM - 12:00 PM',
            false
        );
        $response->assertSee('Partial Day');
    }

    public function test_the_project_details_page_still_shows_a_whole_day_range_as_dates(): void
    {
        $technician = $this->createTechnician('technician', 'Ivan Torres');

        $project = $this->residentialProject(
            $technician,
            Schedule::MODE_DATE_BASED,
            $this->day(10).' 00:00:00',
            $this->day(12).' 23:59:59'
        );

        $response = $this->get(route('super-admin.projects.show', $project->project_id));

        $response->assertOk();
        $response->assertSee(
            CarbonImmutable::parse($this->day(10))->format('M j, Y')
                .' - '.CarbonImmutable::parse($this->day(12))->format('M j, Y'),
            false
        );
    }

    // -----------------------------------------------------------------
    // How a schedule reaches the calendars
    // -----------------------------------------------------------------

    public function test_a_partial_day_becomes_a_timed_calendar_event(): void
    {
        $schedule = new Schedule([
            'start_datetime' => '2026-08-06 08:00:00',
            'end_datetime' => '2026-08-06 12:00:00',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
        ]);

        $this->assertSame([
            'start' => '2026-08-06T08:00:00',
            'end' => '2026-08-06T12:00:00',
            'allDay' => false,
        ], $schedule->toCalendarTimes());
    }

    /**
     * The times carry no timezone offset on purpose. Schedules hold wall-clock
     * time, and an offset would invite the browser to convert it - turning an
     * 8 AM booking into something else for anyone sitting in Manila.
     */
    public function test_calendar_times_carry_no_timezone_offset(): void
    {
        $schedule = new Schedule([
            'start_datetime' => '2026-08-06 08:00:00',
            'end_datetime' => '2026-08-06 12:00:00',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
        ]);

        foreach ($schedule->toCalendarTimes() as $key => $value) {
            if ($key === 'allDay') {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression('/[Zz]|[+-]\d{2}:?\d{2}$/', $value);
        }
    }

    public function test_a_whole_day_schedule_stays_an_all_day_calendar_event(): void
    {
        $schedule = new Schedule([
            'start_datetime' => '2026-08-06 00:00:00',
            'end_datetime' => '2026-08-09 23:59:59',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
        ]);

        $this->assertSame([
            'start' => '2026-08-06',
            // FullCalendar treats an all-day end as exclusive.
            'end' => '2026-08-10',
            'allDay' => true,
        ], $schedule->toCalendarTimes());
    }

    public function test_every_calendar_receives_the_hours_of_a_partial_day(): void
    {
        $technician = $this->createTechnician('technician', 'Rosa Cruz');

        $project = $this->residentialProject(
            $technician,
            Schedule::MODE_PARTIAL_DAY,
            $this->day(10).' 08:00:00',
            $this->day(10).' 12:00:00'
        );

        $expectedStart = $this->day(10).'T08:00:00';
        $expectedLabel = CarbonImmutable::parse($this->day(10))->format('M j, Y').' · 8:00 AM - 12:00 PM';

        // The administrator's schedules calendar.
        $schedules = $this->get(route('super-admin.schedules.index'));
        $schedules->assertOk();

        $event = collect($schedules->viewData('calendarEvents'))
            ->firstWhere('id', $project->schedules->first()->schedule_id);

        $this->assertFalse($event['allDay']);
        $this->assertSame($expectedStart, $event['start']);
        $this->assertSame($expectedLabel, $event['extendedProps']['scheduleLabel']);

        // The technician's own calendar, as an administrator sees it.
        $technicianCalendar = $this->getJson(
            route('super-admin.technicians.calendar', $technician->technician_id)
        );
        $technicianCalendar->assertOk();

        $technicianEvent = $technicianCalendar->json('events.0');

        $this->assertFalse($technicianEvent['allDay']);
        $this->assertSame($expectedStart, $technicianEvent['start']);
        $this->assertSame($expectedLabel, $technicianEvent['extendedProps']['rangeLabel']);
    }

    /**
     * The mark says which kind of booking it is.
     *
     * A whole-day range owns every hour of the days it covers and is drawn
     * filled. A partial day books a few hours and leaves the rest of that
     * date free, so it stays outlined - a solid bar would claim a day nobody
     * booked. Both calendars have to agree about it.
     */
    public function test_a_partial_day_stays_outlined_while_whole_days_are_filled(): void
    {
        $technician = $this->createTechnician('technician', 'Rosa Cruz');

        $partial = $this->residentialProject(
            $technician,
            Schedule::MODE_PARTIAL_DAY,
            $this->day(10).' 08:00:00',
            $this->day(10).' 12:00:00'
        );

        $whole = $this->residentialProject(
            $technician,
            Schedule::MODE_DATE_BASED,
            $this->day(20).' 00:00:00',
            $this->day(22).' 23:59:59'
        );

        $events = collect($this->get(route('super-admin.schedules.index'))->viewData('calendarEvents'))
            ->keyBy('id');

        $partialEvent = $events[$partial->schedules->first()->schedule_id];
        $wholeEvent = $events[$whole->schedules->first()->schedule_id];

        // Outlined: the bar itself carries no fill.
        $this->assertSame('transparent', $partialEvent['backgroundColor']);
        $this->assertSame($partialEvent['borderColor'], $partialEvent['textColor']);

        // Filled: the bar carries the colour, the lettering is white on it.
        $this->assertSame($wholeEvent['borderColor'], $wholeEvent['backgroundColor']);
        $this->assertSame('#ffffff', $wholeEvent['textColor']);
        $this->assertNotSame('transparent', $wholeEvent['backgroundColor']);
    }

    // -----------------------------------------------------------------
    // The screens that write schedules
    // -----------------------------------------------------------------

    public function test_the_wizard_offers_the_scheduling_mode_selector_and_the_working_hours(): void
    {
        ProjectType::create(['type_name' => 'Aircon Installation']);
        $this->createTechnician('lead_technician', 'Lead Technician');

        $response = $this->get(route('super-admin.projects.create'));

        $response->assertOk();
        $response->assertSee('Scheduling Mode');
        $response->assertSee('name="scheduling_mode"', false);
        $response->assertSee('value="'.Schedule::MODE_PARTIAL_DAY.'"', false);

        // Every bookable hour, 8:00 AM through 5:00 PM.
        foreach (Schedule::workingHourOptions() as $hour) {
            $response->assertSee($hour['label']);
        }
    }

    public function test_the_schedules_page_offers_the_mode_selector_on_residential_projects_only(): void
    {
        $technician = $this->createTechnician('technician', 'Rosa Cruz');

        $residential = $this->residentialProject(
            $technician,
            Schedule::MODE_DATE_BASED,
            $this->day(10).' 00:00:00',
            $this->day(10).' 23:59:59'
        );

        $response = $this->get(route('super-admin.schedules.index'));

        $response->assertOk();
        $response->assertSee('ranges[0][scheduling_mode]', false);
        $response->assertSee('ranges[0][start_time]', false);
        $response->assertSee('Add New Schedule');

        // The same project turned Commercial loses the choice entirely.
        $residential->clients()->update(['client_type' => 'Commercial']);

        $commercialResponse = $this->get(route('super-admin.schedules.index'));

        $commercialResponse->assertOk();
        $commercialResponse->assertDontSee('ranges[0][scheduling_mode]', false);
        $commercialResponse->assertSee('ranges[0][start_date]', false);
    }

    public function test_the_schedules_page_pre_fills_an_existing_partial_day_schedule(): void
    {
        $technician = $this->createTechnician('technician', 'Ben Reyes');

        $this->residentialProject(
            $technician,
            Schedule::MODE_PARTIAL_DAY,
            $this->day(10).' 08:00:00',
            $this->day(10).' 12:00:00'
        );

        $response = $this->get(route('super-admin.schedules.index'));

        $response->assertOk();
        $response->assertSee('value="'.$this->day(10).'"', false);
        $response->assertSee('value="08:00" selected', false);
        $response->assertSee('value="12:00" selected', false);
    }

    // -----------------------------------------------------------------
    // Creating
    // -----------------------------------------------------------------

    public function test_the_wizard_creates_a_partial_day_residential_project(): void
    {
        ProjectType::create(['type_name' => 'Aircon Installation']);

        $lead = $this->createTechnician('lead_technician', 'Lead Technician');
        $technician = $this->createTechnician('technician', 'Juan Technician');

        $response = $this->post(route('super-admin.projects.create.store'), [
            ...$this->wizardPayload($lead, $technician),
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(10),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('super-admin.projects'));

        $schedule = Schedule::firstOrFail();

        $this->assertTrue($schedule->isPartialDay());
        $this->assertSame($this->day(10).' 08:00:00', $schedule->start_datetime->format('Y-m-d H:i:s'));
        $this->assertSame($this->day(10).' 12:00:00', $schedule->end_datetime->format('Y-m-d H:i:s'));
    }

    /**
     * The wizard with no mode named is the wizard as it was, and must still
     * produce the whole-day schedule it always did.
     */
    public function test_the_wizard_still_creates_a_whole_day_project_when_no_mode_is_named(): void
    {
        ProjectType::create(['type_name' => 'Aircon Installation']);

        $lead = $this->createTechnician('lead_technician', 'Lead Technician');
        $technician = $this->createTechnician('technician', 'Juan Technician');

        $response = $this->post(route('super-admin.projects.create.store'), [
            ...$this->wizardPayload($lead, $technician),
            'start_date' => $this->day(10),
            'end_date' => $this->day(12),
        ]);

        $response->assertSessionHasNoErrors();

        $schedule = Schedule::firstOrFail();

        $this->assertTrue($schedule->isDateBased());
        $this->assertSame($this->day(10).' 00:00:00', $schedule->start_datetime->format('Y-m-d H:i:s'));
        $this->assertSame($this->day(12).' 23:59:59', $schedule->end_datetime->format('Y-m-d H:i:s'));
    }

    public function test_a_one_day_whole_day_project_is_stored_as_a_single_date(): void
    {
        ProjectType::create(['type_name' => 'Aircon Installation']);

        $lead = $this->createTechnician('lead_technician', 'Lead Technician');
        $technician = $this->createTechnician('technician', 'Juan Technician');

        $this->post(route('super-admin.projects.create.store'), [
            ...$this->wizardPayload($lead, $technician),
            'start_date' => $this->day(10),
            'end_date' => $this->day(10),
        ])->assertSessionHasNoErrors();

        $schedule = Schedule::firstOrFail();

        $this->assertTrue($schedule->spansSingleDay());
        $this->assertSame($this->day(10), $schedule->startsOn()->toDateString());
    }

    public function test_a_commercial_project_may_not_be_scheduled_by_the_hour(): void
    {
        ProjectType::create(['type_name' => 'Aircon Installation']);

        $lead = $this->createTechnician('lead_technician', 'Lead Technician');
        $technician = $this->createTechnician('technician', 'Juan Technician');

        $this->post(route('super-admin.projects.create.store'), [
            ...$this->wizardPayload($lead, $technician, 'Commercial'),
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(10),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ])->assertSessionHasErrors(['scheduling_mode']);

        $this->assertDatabaseCount('tbl_projects', 0);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function invalidTimeProvider(): array
    {
        return [
            'end before start' => ['12:00', '09:00', 'end_time'],
            'end equal to start' => ['09:00', '09:00', 'end_time'],
            'end past five' => ['15:00', '18:00', 'end_time'],
            'not on the hour' => ['08:30', '10:00', 'start_time'],
            'before opening' => ['07:00', '09:00', 'start_time'],
        ];
    }

    #[DataProvider('invalidTimeProvider')]
    public function test_the_wizard_refuses_times_outside_the_working_day(
        string $startTime,
        string $endTime,
        string $expectedField
    ): void {
        ProjectType::create(['type_name' => 'Aircon Installation']);

        $lead = $this->createTechnician('lead_technician', 'Lead Technician');
        $technician = $this->createTechnician('technician', 'Juan Technician');

        $this->post(route('super-admin.projects.create.store'), [
            ...$this->wizardPayload($lead, $technician),
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(10),
            'start_time' => $startTime,
            'end_time' => $endTime,
        ])->assertSessionHasErrors([$expectedField]);

        $this->assertDatabaseCount('tbl_projects', 0);
    }

    /**
     * Today is bookable, but only for hours still ahead at the office.
     */
    public function test_a_time_that_has_already_passed_today_is_refused(): void
    {
        // 06:00 UTC is 2:00 PM in Manila, so the morning has gone.
        $this->travelTo(CarbonImmutable::parse('2026-08-12 06:00:00', 'UTC'));

        ProjectType::create(['type_name' => 'Aircon Installation']);

        $lead = $this->createTechnician('lead_technician', 'Lead Technician');
        $technician = $this->createTechnician('technician', 'Juan Technician');

        $this->post(route('super-admin.projects.create.store'), [
            ...$this->wizardPayload($lead, $technician),
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => '2026-08-12',
            'start_time' => '08:00',
            'end_time' => '10:00',
        ])->assertSessionHasErrors(['start_time']);

        // Later the same day is still fair game.
        $this->post(route('super-admin.projects.create.store'), [
            ...$this->wizardPayload($lead, $technician),
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => '2026-08-12',
            'start_time' => '15:00',
            'end_time' => '17:00',
        ])->assertSessionHasNoErrors();
    }

    public function test_the_wizard_refuses_hours_a_technician_has_already_promised(): void
    {
        ProjectType::create(['type_name' => 'Aircon Installation']);

        $lead = $this->createTechnician('lead_technician', 'Lead Technician');
        $technician = $this->createTechnician('technician', 'Juan Technician');

        $this->residentialProject(
            $technician,
            Schedule::MODE_PARTIAL_DAY,
            $this->day(10).' 08:00:00',
            $this->day(10).' 10:00:00'
        );

        $this->post(route('super-admin.projects.create.store'), [
            ...$this->wizardPayload($lead, $technician),
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(10),
            'start_time' => '09:00',
            'end_time' => '11:00',
        ])->assertSessionHasErrors(['project_date', 'start_time', 'end_time']);

        $this->assertStringContainsString(
            'is already booked',
            session('errors')->first('start_time')
        );
    }

    public function test_the_wizard_accepts_hours_that_sit_beside_an_existing_booking(): void
    {
        ProjectType::create(['type_name' => 'Aircon Installation']);

        $lead = $this->createTechnician('lead_technician', 'Lead Technician');
        $technician = $this->createTechnician('technician', 'Juan Technician');

        $this->residentialProject(
            $technician,
            Schedule::MODE_PARTIAL_DAY,
            $this->day(10).' 08:00:00',
            $this->day(10).' 10:00:00'
        );

        $this->post(route('super-admin.projects.create.store'), [
            ...$this->wizardPayload($lead, $technician),
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(10),
            'start_time' => '10:00',
            'end_time' => '12:00',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Schedule::query()->count());
    }

    public function test_a_whole_day_project_cannot_be_created_over_an_existing_partial_day(): void
    {
        ProjectType::create(['type_name' => 'Aircon Installation']);

        $lead = $this->createTechnician('lead_technician', 'Lead Technician');
        $technician = $this->createTechnician('technician', 'Juan Technician');

        $this->residentialProject(
            $technician,
            Schedule::MODE_PARTIAL_DAY,
            $this->day(11).' 08:00:00',
            $this->day(11).' 10:00:00'
        );

        $this->post(route('super-admin.projects.create.store'), [
            ...$this->wizardPayload($lead, $technician),
            'start_date' => $this->day(10),
            'end_date' => $this->day(12),
        ])->assertSessionHasErrors(['start_date', 'end_date']);
    }

    /**
     * The other half of the mixed case: a whole-day booking reserves the
     * technician outright, so no hours on that date are on offer.
     */
    public function test_the_wizard_refuses_a_partial_day_against_a_whole_day_booking(): void
    {
        ProjectType::create(['type_name' => 'Aircon Installation']);

        $lead = $this->createTechnician('lead_technician', 'Lead Technician');
        $technician = $this->createTechnician('technician', 'Juan Technician');

        $this->residentialProject(
            $technician,
            Schedule::MODE_DATE_BASED,
            $this->day(9).' 00:00:00',
            $this->day(11).' 23:59:59'
        );

        // Day 10 sits in the middle of that range, so every hour is spoken for.
        $this->post(route('super-admin.projects.create.store'), [
            ...$this->wizardPayload($lead, $technician),
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(10),
            'start_time' => '13:00',
            'end_time' => '15:00',
        ])->assertSessionHasErrors(['project_date', 'start_time', 'end_time']);

        $this->assertStringContainsString(
            'for the whole day',
            session('errors')->first('start_time')
        );
    }

    // -----------------------------------------------------------------
    // Editing on the schedules page
    // -----------------------------------------------------------------

    public function test_two_partial_days_on_one_date_can_be_saved_together(): void
    {
        $technician = $this->createTechnician('technician', 'Ben Reyes');

        $project = $this->residentialProject(
            $technician,
            Schedule::MODE_PARTIAL_DAY,
            $this->day(10).' 08:00:00',
            $this->day(10).' 10:00:00'
        );

        $schedule = $project->schedules->first();

        $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [
                [
                    'schedule_id' => $schedule->schedule_id,
                    'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
                    'project_date' => $this->day(10),
                    'start_time' => '08:00',
                    'end_time' => '10:00',
                ],
                [
                    'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
                    'project_date' => $this->day(10),
                    'start_time' => '13:00',
                    'end_time' => '15:00',
                ],
            ],
        ])->assertSessionHas('success');

        $this->assertSame(2, $project->schedules()->count());
    }

    public function test_two_partial_days_whose_hours_meet_are_refused(): void
    {
        $technician = $this->createTechnician('technician', 'Carlo Diaz');

        $project = $this->residentialProject(
            $technician,
            Schedule::MODE_PARTIAL_DAY,
            $this->day(10).' 08:00:00',
            $this->day(10).' 10:00:00'
        );

        $schedule = $project->schedules->first();

        $response = $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [
                [
                    'schedule_id' => $schedule->schedule_id,
                    'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
                    'project_date' => $this->day(10),
                    'start_time' => '08:00',
                    'end_time' => '10:00',
                ],
                [
                    'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
                    'project_date' => $this->day(10),
                    'start_time' => '09:00',
                    'end_time' => '11:00',
                ],
            ],
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('overlaps with', session('error'));
        $this->assertSame(1, $project->schedules()->count());
    }

    // -----------------------------------------------------------------
    // Switching a schedule between the two modes
    // -----------------------------------------------------------------

    public function test_a_one_day_whole_day_schedule_can_become_a_partial_day(): void
    {
        $technician = $this->createTechnician('technician', 'Elena Bautista');

        $project = $this->residentialProject(
            $technician,
            Schedule::MODE_DATE_BASED,
            $this->day(10).' 00:00:00',
            $this->day(10).' 23:59:59'
        );

        $schedule = $project->schedules->first();

        $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [[
                'schedule_id' => $schedule->schedule_id,
                'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
                'project_date' => $this->day(10),
                'start_time' => '09:00',
                'end_time' => '11:00',
            ]],
        ])->assertSessionHas('success');

        $schedule = $schedule->fresh();

        $this->assertTrue($schedule->isPartialDay());
        $this->assertSame('9:00 AM - 11:00 AM', $schedule->timeRange());
    }

    /**
     * Hours cannot be attached to a range covering several days without
     * guessing which of them was meant, so the conversion is refused and
     * nothing on the project is touched.
     */
    public function test_a_multi_day_schedule_cannot_become_a_partial_day(): void
    {
        $technician = $this->createTechnician('technician', 'Grace Villanueva');

        $project = $this->residentialProject(
            $technician,
            Schedule::MODE_DATE_BASED,
            $this->day(10).' 00:00:00',
            $this->day(12).' 23:59:59'
        );

        $schedule = $project->schedules->first();

        $response = $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [[
                'schedule_id' => $schedule->schedule_id,
                'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
                'project_date' => $this->day(10),
                'start_time' => '09:00',
                'end_time' => '11:00',
            ]],
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('covers more than one day', session('error'));

        $schedule = $schedule->fresh();

        $this->assertTrue($schedule->isDateBased());
        $this->assertSame($this->day(12), $schedule->endsOn()->toDateString());
    }

    public function test_a_partial_day_becomes_a_one_day_whole_day_schedule_keeping_its_date(): void
    {
        $technician = $this->createTechnician('technician', 'Nina Flores');

        $project = $this->residentialProject(
            $technician,
            Schedule::MODE_PARTIAL_DAY,
            $this->day(10).' 08:00:00',
            $this->day(10).' 10:00:00'
        );

        $schedule = $project->schedules->first();

        $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [[
                'schedule_id' => $schedule->schedule_id,
                'scheduling_mode' => Schedule::MODE_DATE_BASED,
                'start_date' => $this->day(10),
                'end_date' => $this->day(10),
            ]],
        ])->assertSessionHas('success');

        $schedule = $schedule->fresh();

        $this->assertTrue($schedule->isDateBased());
        $this->assertTrue($schedule->spansSingleDay());
        $this->assertNull($schedule->timeRange());
        $this->assertSame($this->day(10), $schedule->startsOn()->toDateString());
    }

    /**
     * Widening a booking from a few hours to a whole day can collide with work
     * the technician has elsewhere that afternoon. The conversion is cancelled
     * rather than pushed through.
     */
    public function test_widening_a_partial_day_is_refused_when_the_whole_day_is_not_free(): void
    {
        $technician = $this->createTechnician('technician', 'Paolo Ramos');

        $project = $this->residentialProject(
            $technician,
            Schedule::MODE_PARTIAL_DAY,
            $this->day(10).' 08:00:00',
            $this->day(10).' 10:00:00'
        );

        // The same technician is out on another job that afternoon.
        $this->residentialProject(
            $technician,
            Schedule::MODE_PARTIAL_DAY,
            $this->day(10).' 13:00:00',
            $this->day(10).' 15:00:00'
        );

        $schedule = $project->schedules->first();

        $response = $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [[
                'schedule_id' => $schedule->schedule_id,
                'scheduling_mode' => Schedule::MODE_DATE_BASED,
                'start_date' => $this->day(10),
                'end_date' => $this->day(10),
            ]],
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('unavailable on', session('error'));

        $this->assertTrue($schedule->fresh()->isPartialDay());
    }

    /**
     * Converting one row must leave every other row on the project exactly as
     * it was - nothing merged, nothing dropped.
     */
    public function test_converting_one_schedule_leaves_the_others_untouched(): void
    {
        $technician = $this->createTechnician('technician', 'Ivan Torres');

        $project = $this->residentialProject(
            $technician,
            Schedule::MODE_DATE_BASED,
            $this->day(10).' 00:00:00',
            $this->day(10).' 23:59:59'
        );

        $untouched = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $this->day(20).' 00:00:00',
            'end_datetime' => $this->day(22).' 23:59:59',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'status' => 'scheduled',
            'remarks' => 'Second range',
        ]);

        $converting = $project->schedules->first();

        $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [
                [
                    'schedule_id' => $converting->schedule_id,
                    'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
                    'project_date' => $this->day(10),
                    'start_time' => '09:00',
                    'end_time' => '11:00',
                ],
                [
                    'schedule_id' => $untouched->schedule_id,
                    'scheduling_mode' => Schedule::MODE_DATE_BASED,
                    'start_date' => $this->day(20),
                    'end_date' => $this->day(22),
                ],
            ],
        ])->assertSessionHas('success');

        $this->assertTrue($converting->fresh()->isPartialDay());

        $untouched = $untouched->fresh();

        $this->assertTrue($untouched->isDateBased());
        $this->assertSame($this->day(20), $untouched->startsOn()->toDateString());
        $this->assertSame($this->day(22), $untouched->endsOn()->toDateString());
        $this->assertSame(2, $project->schedules()->count());
    }

    /**
     * Switching back and forth must land exactly where it started. Each hop is
     * its own decision, and none of them may quietly lose the date.
     */
    public function test_a_schedule_survives_switching_modes_and_back_again(): void
    {
        $technician = $this->createTechnician('technician', 'Mika Santos');

        $project = $this->residentialProject(
            $technician,
            Schedule::MODE_DATE_BASED,
            $this->day(10).' 00:00:00',
            $this->day(10).' 23:59:59'
        );

        $schedule = $project->schedules->first();
        $scheduleId = $schedule->schedule_id;

        $switchTo = function (array $range) use ($project): void {
            $this->put(
                route('super-admin.schedules.update', $project->project_id),
                ['ranges' => [$range]]
            )->assertSessionHas('success');
        };

        $switchTo([
            'schedule_id' => $scheduleId,
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(10),
            'start_time' => '09:00',
            'end_time' => '11:00',
        ]);

        $this->assertTrue($schedule->fresh()->isPartialDay());

        $switchTo([
            'schedule_id' => $scheduleId,
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => $this->day(10),
            'end_date' => $this->day(10),
        ]);

        $this->assertTrue($schedule->fresh()->isDateBased());

        $switchTo([
            'schedule_id' => $scheduleId,
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(10),
            'start_time' => '13:00',
            'end_time' => '15:00',
        ]);

        $schedule = $schedule->fresh();

        // Same row throughout - nothing was deleted and recreated - and the
        // date it started on is the date it ended on.
        $this->assertSame($scheduleId, $schedule->schedule_id);
        $this->assertTrue($schedule->isPartialDay());
        $this->assertSame($this->day(10), $schedule->startsOn()->toDateString());
        $this->assertSame('1:00 PM - 3:00 PM', $schedule->timeRange());
        $this->assertSame(1, $project->schedules()->count());
    }

    // -----------------------------------------------------------------
    // Projects that pre-date the feature
    // -----------------------------------------------------------------

    /**
     * A schedule written before scheduling modes existed, edited the way the
     * page used to submit - no mode named anywhere. It must save, and it must
     * still be a whole-day booking afterwards.
     */
    public function test_a_pre_existing_project_is_edited_exactly_as_it_always_was(): void
    {
        $technician = $this->createTechnician('technician', 'Luis Ocampo');

        $project = Project::create([
            'name' => 'Legacy Project',
            'reference_no' => 'REF-'.uniqid(),
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
        ]);

        $projectTechnician = ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);

        // Inserted without the column, the shape every pre-feature writer
        // produced. The database default is what fills it in.
        DB::table('tbl_schedule')->insert([
            'project_id' => $project->project_id,
            'start_datetime' => $this->day(10).' 00:00:00',
            'end_datetime' => $this->day(12).' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Legacy booking',
        ]);

        $schedule = Schedule::where('project_id', $project->project_id)->firstOrFail();

        ScheduleTechnician::create([
            'schedule_id' => $schedule->schedule_id,
            'project_technician_id' => $projectTechnician->project_technician_id,
        ]);

        $this->assertTrue($schedule->isDateBased());

        // The old form posted dates and nothing else.
        $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [[
                'schedule_id' => $schedule->schedule_id,
                'start_date' => $this->day(10),
                'end_date' => $this->day(14),
            ]],
        ])->assertSessionHas('success');

        $schedule = $schedule->fresh();

        $this->assertTrue($schedule->isDateBased());
        $this->assertSame($this->day(14), $schedule->endsOn()->toDateString());
        $this->assertNull($schedule->timeRange());
    }

    /**
     * A project with no client row at all - older data, and what several of
     * the pre-existing tests build - is not Residential, so it keeps the
     * whole-day workflow and cannot reach partial days.
     */
    public function test_a_project_without_a_client_keeps_the_whole_day_workflow(): void
    {
        $technician = $this->createTechnician('technician', 'Grace Villanueva');

        $project = Project::create([
            'name' => 'Clientless Project',
            'reference_no' => 'REF-'.uniqid(),
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
        ]);

        $projectTechnician = ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);

        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $this->day(10).' 00:00:00',
            'end_datetime' => $this->day(10).' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        ScheduleTechnician::create([
            'schedule_id' => $schedule->schedule_id,
            'project_technician_id' => $projectTechnician->project_technician_id,
        ]);

        $this->assertFalse($project->isResidential());

        $response = $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [[
                'schedule_id' => $schedule->schedule_id,
                'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
                'project_date' => $this->day(10),
                'start_time' => '09:00',
                'end_time' => '11:00',
            ]],
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('Residential', session('error'));
        $this->assertTrue($schedule->fresh()->isDateBased());
    }

    // -----------------------------------------------------------------
    // The calendar's Add Project flow
    // -----------------------------------------------------------------

    public function test_the_calendar_offers_partial_days_to_residential_work_only(): void
    {
        $technician = $this->createTechnician('technician', 'Rosa Cruz');

        $residential = $this->schedulableProject($technician, 'Residential');
        $commercial = $this->schedulableProject($this->createTechnician('technician', 'Mika Santos'), 'Commercial');

        $response = $this->getJson(route('super-admin.schedules.assignable', [
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(5),
            'start_time' => '08:00',
            'end_time' => '10:00',
        ]));

        $response->assertOk();

        $this->assertSame(
            [$residential->project_id],
            array_column($response->json('projects'), 'project_id')
        );

        $blocked = collect($response->json('blocked'))
            ->firstWhere('project_id', $commercial->project_id);

        $this->assertNotNull($blocked);
        $this->assertStringContainsString('Residential projects only', $blocked['reason']);
    }

    /**
     * The point of the whole feature, seen from the calendar: a technician
     * booked for the morning is still offered for the afternoon.
     */
    public function test_the_calendar_screens_partial_days_by_the_hour(): void
    {
        $technician = $this->createTechnician('technician', 'Ben Reyes');

        $candidate = $this->schedulableProject($technician, 'Residential');

        // The same technician is out on another job that morning.
        $this->residentialProject(
            $technician,
            Schedule::MODE_PARTIAL_DAY,
            $this->day(5).' 08:00:00',
            $this->day(5).' 10:00:00'
        );

        $afternoon = $this->getJson(route('super-admin.schedules.assignable', [
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(5),
            'start_time' => '13:00',
            'end_time' => '15:00',
        ]));

        $this->assertContains(
            $candidate->project_id,
            array_column($afternoon->json('projects'), 'project_id')
        );

        $morning = $this->getJson(route('super-admin.schedules.assignable', [
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(5),
            'start_time' => '09:00',
            'end_time' => '11:00',
        ]));

        $this->assertNotContains(
            $candidate->project_id,
            array_column($morning->json('projects'), 'project_id')
        );

        $blocked = collect($morning->json('blocked'))
            ->firstWhere('project_id', $candidate->project_id);

        $this->assertStringContainsString('at this time', $blocked['reason']);
    }

    public function test_the_calendar_books_a_partial_day_across_the_projects_picked(): void
    {
        $first = $this->schedulableProject($this->createTechnician('technician', 'Nina Flores'), 'Residential');
        $second = $this->schedulableProject($this->createTechnician('technician', 'Ivan Torres'), 'Residential');

        $this->postJson(route('super-admin.schedules.assign'), [
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(5),
            'start_time' => '13:00',
            'end_time' => '15:00',
            'project_ids' => [$first->project_id, $second->project_id],
        ])->assertOk();

        $this->assertSame(2, Schedule::query()->count());

        foreach ([$first, $second] as $project) {
            $schedule = Schedule::where('project_id', $project->project_id)->firstOrFail();

            $this->assertTrue($schedule->isPartialDay());
            $this->assertSame('1:00 PM - 3:00 PM', $schedule->timeRange());
            $this->assertSame($this->day(5), $schedule->startsOn()->toDateString());
        }
    }

    public function test_the_calendar_refuses_a_commercial_project_in_a_partial_day_batch(): void
    {
        $residential = $this->schedulableProject($this->createTechnician('technician', 'Grace Villanueva'), 'Residential');
        $commercial = $this->schedulableProject($this->createTechnician('technician', 'Paolo Ramos'), 'Commercial');

        $response = $this->postJson(route('super-admin.schedules.assign'), [
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(5),
            'start_time' => '13:00',
            'end_time' => '15:00',
            'project_ids' => [$residential->project_id, $commercial->project_id],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Residential projects only', $response->json('error'));

        // The whole batch rolls back, so the Residential one is unbooked too.
        $this->assertSame(0, Schedule::query()->count());
    }

    public function test_the_calendar_still_books_whole_days_when_no_mode_is_named(): void
    {
        $project = $this->schedulableProject($this->createTechnician('technician', 'Luis Ocampo'), 'Residential');

        $this->postJson(route('super-admin.schedules.assign'), [
            'start_date' => $this->day(5),
            'end_date' => $this->day(7),
            'project_ids' => [$project->project_id],
        ])->assertOk();

        $schedule = Schedule::firstOrFail();

        $this->assertTrue($schedule->isDateBased());
        $this->assertSame($this->day(5), $schedule->startsOn()->toDateString());
        $this->assertSame($this->day(7), $schedule->endsOn()->toDateString());
    }

    public function test_a_commercial_project_cannot_switch_a_schedule_to_partial_day(): void
    {
        $technician = $this->createTechnician('technician', 'Luis Ocampo');

        $project = $this->residentialProject(
            $technician,
            Schedule::MODE_DATE_BASED,
            $this->day(10).' 00:00:00',
            $this->day(10).' 23:59:59'
        );

        $project->clients()->update(['client_type' => 'Commercial']);

        $schedule = $project->schedules->first();

        $response = $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [[
                'schedule_id' => $schedule->schedule_id,
                'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
                'project_date' => $this->day(10),
                'start_time' => '09:00',
                'end_time' => '11:00',
            ]],
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('Residential', session('error'));
        $this->assertTrue($schedule->fresh()->isDateBased());
    }
}
