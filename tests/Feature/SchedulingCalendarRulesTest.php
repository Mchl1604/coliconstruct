<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Holiday;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use App\Services\ProjectTeam;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Database\Seeders\PhilippineNonWorkingDaySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who may book which days: nobody but the Super Admin books for today, a lead's
 * tasks start tomorrow at the earliest, and holidays are skipped inside a range
 * and refused at either end of one.
 */
class SchedulingCalendarRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = $this->account(User::ROLE_SUPER_ADMIN);
        $this->project = $this->project('REF-CAL-1');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function schedulers(): array
    {
        return [
            'admin officer' => [User::ROLE_ADMIN_OFFICER],
            'hr admin' => [User::ROLE_ADMIN],
        ];
    }

    #[DataProvider('schedulers')]
    public function test_the_office_books_from_tomorrow_not_today(string $role): void
    {
        $this->actingAs($this->account($role));

        $this->assign($this->day(0), $this->day(2))
            ->assertStatus(422)
            ->assertJsonPath('error', 'Choose tomorrow or later.');

        $this->assertSame(0, Schedule::count());

        $this->assign($this->day(1), $this->day(2))->assertOk();

        $this->assertSame(1, Schedule::count());
    }

    #[DataProvider('schedulers')]
    public function test_the_office_books_no_hours_for_today(string $role): void
    {
        $this->actingAs($this->account($role));

        $this->postJson(route('super-admin.schedules.assign'), [
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(0),
            'start_time' => '22:00',
            'end_time' => '23:00',
            'project_ids' => [$this->project->project_id],
        ])->assertStatus(422);

        $this->assertSame(0, Schedule::count());
    }

    public function test_a_super_admin_cannot_book_today_either(): void
    {
        $this->actingAs($this->superAdmin);

        $this->assign($this->day(0), $this->day(1))->assertStatus(422);

        $this->assertSame(0, Schedule::count());
    }

    public function test_a_technician_is_added_for_a_day_from_tomorrow(): void
    {
        $project = $this->project('REF-CAL-2');
        Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $this->day(0).' 00:00:00',
            'end_datetime' => $this->day(3).' 23:59:59',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'status' => 'scheduled',
        ]);
        $technician = $this->technician(User::ROLE_TECHNICIAN);

        $this->actingAs($this->account(User::ROLE_ADMIN));

        $this->postJson(route('super-admin.technicians.projects.day', [$technician->technician_id, $project->project_id]), [
            'date' => $this->day(0),
        ])->assertStatus(422)->assertJsonPath('error', 'Choose tomorrow or later.');
    }

    public function test_a_lead_assigns_tasks_from_tomorrow_and_keeps_a_task_already_dated(): void
    {
        $lead = $this->technician(User::ROLE_LEAD_TECHNICIAN);
        $mate = $this->technician(User::ROLE_TECHNICIAN);
        $this->finalizePhases($this->project);

        Schedule::create([
            'project_id' => $this->project->project_id,
            'start_datetime' => $this->day(-1).' 00:00:00',
            'end_datetime' => $this->day(5).' 23:59:59',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'status' => 'scheduled',
        ]);

        foreach ([$lead, $mate] as $technician) {
            app(ProjectTeam::class)->attach($this->project, $technician->technician_id, $this->superAdmin->id);
        }

        $this->project->update(['status' => 'ongoing']);
        $fields = fn (string $start): array => [
            'task_title' => 'Fit the bracket',
            'task_description' => 'Fit it.',
            'phase_id' => $this->defaultPhaseId($this->project),
            'technician_id' => $mate->technician_id,
            'start_date' => $start,
            'due_date' => $this->day(3),
        ];

        $this->actingAs($lead->account);

        $this->post(route('technician.tasks.store', $this->project->project_id), $fields($this->day(0)));
        $this->assertSame(0, Task::count());

        $this->post(route('technician.tasks.store', $this->project->project_id), $fields($this->day(1)))
            ->assertSessionHasNoErrors();
        $task = Task::query()->firstOrFail();
        $this->assertSame(1, Task::count());

        // Moving its start to today is a new assignment for today.
        $this->post(route('technician.tasks.update', $task->task_id), $fields($this->day(0)));
        $this->assertSame($this->day(1), CarbonImmutable::parse($task->fresh()->start_date)->toDateString());

        // A task that began yesterday can still be edited as it stands.
        $task->forceFill(['start_date' => $this->day(-1)])->save();

        $this->post(route('technician.tasks.update', $task->task_id), ['task_title' => 'Renamed'] + $fields($this->day(-1)));
        $this->assertSame('Renamed', $task->fresh()->task_title);
    }

    public function test_a_holiday_inside_a_range_is_skipped_and_the_crew_stays_booked(): void
    {
        $technician = $this->technician(User::ROLE_TECHNICIAN);
        app(ProjectTeam::class)->attach($this->project, $technician->technician_id, $this->superAdmin->id);
        Holiday::factory()->create(['holiday_date' => $this->day(5), 'name' => 'Bonifacio Day']);

        $this->actingAs($this->account(User::ROLE_ADMIN_OFFICER));

        $this->assign($this->day(3), $this->day(7))->assertOk();

        $rows = Schedule::query()->where('project_id', $this->project->project_id)->orderBy('start_datetime')->get();

        $this->assertCount(2, $rows);
        $this->assertSame([$this->day(3), $this->day(4)], [$rows[0]->startsOn()->toDateString(), $rows[0]->endsOn()->toDateString()]);
        $this->assertSame([$this->day(6), $this->day(7)], [$rows[1]->startsOn()->toDateString(), $rows[1]->endsOn()->toDateString()]);
        $this->assertFalse($this->project->fresh()->isScheduledOn($this->day(5)));

        foreach ($rows as $row) {
            $this->assertSame(1, ScheduleTechnician::query()->where('schedule_id', $row->schedule_id)->count());
        }
    }

    public function test_several_holidays_in_one_range_are_all_skipped(): void
    {
        Holiday::factory()->create(['holiday_date' => $this->day(4)]);
        Holiday::factory()->create(['holiday_date' => $this->day(6)]);

        $this->actingAs($this->superAdmin);
        $this->assign($this->day(3), $this->day(7))->assertOk();

        $project = $this->project->fresh();

        $this->assertCount(3, $project->schedules);
        $this->assertFalse($project->isScheduledOn($this->day(4)));
        $this->assertTrue($project->isScheduledOn($this->day(5)));
        $this->assertFalse($project->isScheduledOn($this->day(6)));
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function endsOnHoliday(): array
    {
        return ['the start' => [5, 8], 'the end' => [3, 5], 'a single day' => [5, 5]];
    }

    #[DataProvider('endsOnHoliday')]
    public function test_a_range_may_not_start_or_end_on_a_holiday(int $start, int $end): void
    {
        Holiday::factory()->create(['holiday_date' => $this->day(5), 'name' => 'Bonifacio Day']);

        // Not even the Super Admin: it is the calendar, not a permission.
        $this->actingAs($this->superAdmin);

        $this->assign($this->day($start), $this->day($end))
            ->assertStatus(422)
            ->assertJsonPath('error', CarbonImmutable::parse($this->day(5))->format(BusinessTime::DATE).' is a non-working day: Bonifacio Day.');

        $this->assertSame(0, Schedule::count());
    }

    public function test_hours_may_not_be_booked_on_a_holiday(): void
    {
        Holiday::factory()->create(['holiday_date' => $this->day(5), 'name' => 'Bonifacio Day']);

        $this->actingAs($this->superAdmin);

        $this->postJson(route('super-admin.schedules.assign'), [
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(5),
            'start_time' => '09:00',
            'end_time' => '11:00',
            'project_ids' => [$this->project->project_id],
        ])->assertStatus(422);

        $this->assertSame(0, Schedule::count());
    }

    public function test_a_booking_made_before_the_holiday_stays_as_it_is(): void
    {
        $schedule = Schedule::create([
            'project_id' => $this->project->project_id,
            'start_datetime' => $this->day(3).' 00:00:00',
            'end_datetime' => $this->day(7).' 23:59:59',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->superAdmin);

        $this->postJson(route('super-admin.configuration.holidays.store'), [
            'holiday_date' => $this->day(5),
            'name' => 'Bonifacio Day',
        ])->assertOk();

        $this->assertSame(1, Schedule::count());
        $this->assertSame($this->day(7), $schedule->fresh()->endsOn()->toDateString());
    }

    public function test_the_super_admin_keeps_the_list(): void
    {
        $this->actingAs($this->superAdmin);

        $this->get(route('super-admin.configuration.index'))
            ->assertOk()
            ->assertSee('data-holidays-panel', false)
            ->assertSee('/js/super-admin/holidays.js', false);

        $this->postJson(route('super-admin.configuration.holidays.store'), ['holiday_date' => $this->day(9), 'name' => ''])
            ->assertStatus(422)->assertJsonPath('error', 'Enter a name.');

        $this->postJson(route('super-admin.configuration.holidays.store'), ['holiday_date' => $this->day(9), 'name' => 'Town Fiesta'])
            ->assertOk();

        $this->postJson(route('super-admin.configuration.holidays.store'), ['holiday_date' => $this->day(9), 'name' => 'Again'])
            ->assertStatus(422)->assertJsonPath('error', 'That date is already listed.');

        $this->getTablePage(route('super-admin.configuration.holidays.index'))->assertSee('Town Fiesta');

        $holiday = Holiday::query()->firstOrFail();

        $this->deleteJson(route('super-admin.configuration.holidays.destroy', $holiday))->assertOk();

        $this->assertSame(0, Holiday::count());
    }

    #[DataProvider('schedulers')]
    public function test_nobody_else_keeps_the_list(string $role): void
    {
        $holiday = Holiday::factory()->create();

        $this->actingAs($this->account($role));

        $this->get(route('super-admin.configuration.index'))->assertOk()->assertDontSee('data-holidays-panel', false);
        $this->getJson(route('super-admin.configuration.holidays.index'))->assertForbidden();
        $this->postJson(route('super-admin.configuration.holidays.store'), ['holiday_date' => $this->day(9), 'name' => 'No'])->assertForbidden();
        $this->deleteJson(route('super-admin.configuration.holidays.destroy', $holiday))->assertForbidden();

        $this->assertSame(1, Holiday::count());
    }

    public function test_the_pickers_are_told_the_first_day_and_the_holidays(): void
    {
        Holiday::factory()->create(['holiday_date' => $this->day(5), 'name' => 'Bonifacio Day']);

        $this->actingAs($this->account(User::ROLE_ADMIN));

        $this->get(route('super-admin.schedules.index'))
            ->assertOk()
            ->assertSee('earliest: "'.$this->day(1).'"', false)
            ->assertSee('Bonifacio Day');

        $this->actingAs($this->superAdmin);

        $this->get(route('super-admin.schedules.index'))
            ->assertOk()
            ->assertSee('earliest: "'.$this->day(1).'"', false);
    }

    public function test_the_philippine_days_seed_once_and_leave_edsa_a_working_day(): void
    {
        Holiday::factory()->create(['holiday_date' => '2026-11-30', 'name' => 'Renamed Here']);

        $this->seed(PhilippineNonWorkingDaySeeder::class);
        $this->seed(PhilippineNonWorkingDaySeeder::class);

        $this->assertSame(38, Holiday::count());
        $this->assertSame('Renamed Here', Holiday::query()->whereDate('holiday_date', '2026-11-30')->value('name'));
        $this->assertSame('Christmas Day', Holiday::query()->whereDate('holiday_date', '2027-12-25')->value('name'));
        $this->assertFalse(Holiday::query()->whereDate('holiday_date', '2027-02-25')->exists());
        $this->assertFalse(Holiday::query()->whereDate('holiday_date', '2026-02-25')->exists());
    }

    public function test_every_portal_with_a_calendar_is_told_the_non_working_days(): void
    {
        Holiday::factory()->create(['holiday_date' => $this->day(-40), 'name' => 'Last Month Fiesta']);
        Holiday::factory()->create(['holiday_date' => $this->day(5), 'name' => 'Bonifacio Day']);

        $technician = $this->technician(User::ROLE_TECHNICIAN);

        foreach ([
            [$this->superAdmin, route('super-admin.schedules.index')],
            [$this->superAdmin, route('super-admin.technicians.index')],
            [$technician->account, route('technician.schedule')],
        ] as [$account, $page]) {
            $this->actingAs($account)
                ->get($page)
                ->assertOk()
                ->assertSee('window.bookingCalendar', false)
                ->assertSee('Bonifacio Day')
                ->assertSee('Last Month Fiesta');
        }

        foreach (['public/js/super-admin/schedule.js', 'public/js/super-admin/technicians.js', 'public/js/technician/schedule.js'] as $script) {
            $this->assertStringContainsString('nonWorkingDays()', (string) file_get_contents(base_path($script)), $script);
        }
    }

    private function assign(string $start, string $end): TestResponse
    {
        return $this->postJson(route('super-admin.schedules.assign'), [
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => $start,
            'end_date' => $end,
            'project_ids' => [$this->project->project_id],
        ]);
    }

    private function day(int $offset): string
    {
        return BusinessTime::today()->addDays($offset)->toDateString();
    }

    private function project(string $reference): Project
    {
        return Project::create([
            'client_id' => Client::factory()->create()->client_id,
            'name' => 'Aircon Installation',
            'reference_no' => $reference,
            'status' => 'unscheduled',
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);
    }

    private function technician(string $role): Technician
    {
        $account = $this->account($role);

        return Technician::create(['account_id' => $account->id, 'role' => $role]);
    }

    private function account(string $role): User
    {
        $sequence = User::count() + 1;

        return User::create([
            'user_code' => sprintf('EMP-%04d', $sequence),
            'name' => 'Person '.$sequence,
            'first_name' => 'Person',
            'last_name' => (string) $sequence,
            'email' => 'person'.$sequence.'@example.test',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);
    }
}
