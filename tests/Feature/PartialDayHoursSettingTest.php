<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\SystemContent;
use App\Models\Technician;
use App\Models\User;
use App\Services\DashboardMetrics;
use App\Services\SystemContentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Configuration -> System Settings -> Project Settings: the hours a
 * partial-day booking may be made in.
 *
 * Two things are being pinned. That the pair is a window - saved together,
 * ordered, and refused when it is not - and that it binds what is being
 * PROMISED rather than what has already been promised: narrowing the window
 * must leave every booking already made exactly where it is, and must not
 * refuse the form that booking is sitting on.
 *
 * Whole-day ranges are here too, asserting the negative: they run midnight to
 * midnight, have no hours to bound, and nothing in this section reaches them.
 */
class PartialDayHoursSettingTest extends TestCase
{
    use RefreshDatabase;

    private static int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::$sequence = 0;
    }

    private function account(string $role): User
    {
        $sequence = ++self::$sequence;

        return User::create([
            'user_code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => 'Given Surname'.$sequence,
            'first_name' => 'Given',
            'last_name' => 'Surname'.$sequence,
            'email' => $role.$sequence.'@example.test',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'password' => 'correct-password',
        ]);
    }

    /**
     * Save the Project Settings section the way the editor does - every field
     * of it, with the ones named here laid over the current values.
     *
     * @param  array<string, string>  $values
     */
    private function saveProjectSettings(array $values): TestResponse
    {
        $current = [];

        foreach (SystemContent::definitionsFor(SystemContent::SECTION_PROJECT_SETTINGS) as $key => $definition) {
            $current[$key] = (string) (
                SystemContent::query()->where('content_key', $key)->value('content_value')
                    ?? $definition['default'] ?? ''
            );
        }

        return $this->putJson(
            route('super-admin.configuration.contents.update', SystemContent::SECTION_PROJECT_SETTINGS),
            ['values' => array_merge($current, $values)]
        );
    }

    private function storeHours(string $start, string $end): void
    {
        foreach ([Schedule::SETTING_PARTIAL_DAY_START => $start, Schedule::SETTING_PARTIAL_DAY_END => $end] as $key => $value) {
            SystemContent::query()->updateOrCreate(
                ['content_key' => $key],
                ['content_value' => $value, 'section' => SystemContent::SECTION_PROJECT_SETTINGS]
            );
        }

        app(SystemContentService::class)->flush();
    }

    /**
     * A residential project - the only kind that may be booked by the hour -
     * holding one partial-day range.
     *
     * @return array{0: Project, 1: Schedule, 2: Technician}
     */
    private function partialDayProject(string $start = '08:00', string $end = '12:00', ?string $date = null): array
    {
        $sequence = ++self::$sequence;
        $day = $date ?? CarbonImmutable::tomorrow()->toDateString();

        $project = Project::create([
            'name' => 'Aircon Service '.$sequence,
            'reference_no' => 'REF-PD-'.$sequence,
            'status' => 'pending',
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 50000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Residential',
            'firstname' => 'Contact',
            'surname' => 'Person',
            'fullname' => 'Contact Person',
            'email_address' => 'contact'.$sequence.'@example.test',
            'contact_number' => '09123456789',
        ]);

        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $day.' '.$start.':00',
            'end_datetime' => $day.' '.$end.':00',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'status' => 'scheduled',
        ]);

        $account = $this->account('lead_technician');
        $technician = Technician::create(['account_id' => $account->id, 'role' => 'lead_technician']);

        return [$project->refresh(), $schedule, $technician];
    }

    // ------------------------------------------------------------------
    // Saving the setting
    // ------------------------------------------------------------------

    public function test_a_super_admin_saves_a_window_and_it_persists(): void
    {
        $this->actingAs($this->account('super_admin'));

        $this->saveProjectSettings([
            Schedule::SETTING_PARTIAL_DAY_START => '07:00',
            Schedule::SETTING_PARTIAL_DAY_END => '19:00',
        ])->assertOk()->assertJsonPath('message', 'Settings updated.');

        $this->assertSame(7, Schedule::partialDayStartHour());
        $this->assertSame(19, Schedule::partialDayEndHour());

        // Read back the way the editor reads it when Project Settings is
        // reopened: the saved values, no longer flagged as defaults.
        $fields = collect(
            $this->getJson(route('super-admin.configuration.contents.show', SystemContent::SECTION_PROJECT_SETTINGS))
                ->assertOk()
                ->json('fields')
        )->keyBy('key');

        $this->assertSame('07:00', $fields[Schedule::SETTING_PARTIAL_DAY_START]['value']);
        $this->assertSame('19:00', $fields[Schedule::SETTING_PARTIAL_DAY_END]['value']);
        // Chosen from a list of whole hours, so there is no minute to edit.
        $this->assertSame('hour', $fields[Schedule::SETTING_PARTIAL_DAY_START]['type']);
        $this->assertCount(24, $fields[Schedule::SETTING_PARTIAL_DAY_START]['options']);
        $this->assertSame('00:00', $fields[Schedule::SETTING_PARTIAL_DAY_START]['options'][0]['value']);
        $this->assertSame('8:00 AM', $fields[Schedule::SETTING_PARTIAL_DAY_START]['options'][8]['label']);
        $this->assertSame(
            [],
            collect($fields[Schedule::SETTING_PARTIAL_DAY_END]['options'])
                ->reject(fn (array $option): bool => str_ends_with($option['value'], ':00'))
                ->all()
        );
        $this->assertFalse($fields[Schedule::SETTING_PARTIAL_DAY_END]['is_default']);
    }

    public function test_an_end_before_the_start_is_refused(): void
    {
        $this->actingAs($this->account('super_admin'));

        $this->saveProjectSettings([
            Schedule::SETTING_PARTIAL_DAY_START => '17:00',
            Schedule::SETTING_PARTIAL_DAY_END => '09:00',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'The partial day end hour must be later than the start hour.');

        $this->assertNull(SystemContent::query()
            ->where('content_key', Schedule::SETTING_PARTIAL_DAY_START)
            ->value('content_value'));
    }

    public function test_an_end_equal_to_the_start_is_refused(): void
    {
        $this->actingAs($this->account('super_admin'));

        $this->saveProjectSettings([
            Schedule::SETTING_PARTIAL_DAY_START => '10:00',
            Schedule::SETTING_PARTIAL_DAY_END => '10:00',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'The partial day end hour must be later than the start hour.');

        $this->assertNull(SystemContent::query()
            ->where('content_key', Schedule::SETTING_PARTIAL_DAY_END)
            ->value('content_value'));
    }

    public function test_a_time_off_the_hour_or_out_of_shape_is_refused(): void
    {
        $this->actingAs($this->account('super_admin'));

        foreach (['08:30', '25:00', 'morning', ''] as $bad) {
            $this->saveProjectSettings([Schedule::SETTING_PARTIAL_DAY_START => $bad])
                ->assertStatus(422);

            $this->assertNull(SystemContent::query()
                ->where('content_key', Schedule::SETTING_PARTIAL_DAY_START)
                ->value('content_value'));
        }
    }

    public function test_an_admin_cannot_change_the_window(): void
    {
        $this->actingAs($this->account('admin'));

        $this->saveProjectSettings([Schedule::SETTING_PARTIAL_DAY_START => '07:00'])
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // What reads the setting
    // ------------------------------------------------------------------

    public function test_the_shipped_window_stands_until_something_is_configured(): void
    {
        $this->assertSame(8, Schedule::partialDayStartHour());
        $this->assertSame(17, Schedule::partialDayEndHour());
    }

    public function test_a_window_that_is_not_a_window_falls_back_to_the_shipped_pair(): void
    {
        // Neither of these can be saved through the editor; this is about a
        // value that gets in some other way not being passed on.
        $this->storeHours('18:00', '09:00');

        $this->assertSame(8, Schedule::partialDayStartHour());
        $this->assertSame(17, Schedule::partialDayEndHour());

        $this->storeHours('nonsense', '17:00');

        $this->assertSame(8, Schedule::partialDayStartHour());
        $this->assertSame(17, Schedule::partialDayEndHour());
    }

    public function test_the_pickers_and_the_validator_follow_the_setting(): void
    {
        $this->storeHours('09:00', '15:00');

        $options = collect(Schedule::workingHourOptions())->pluck('value')->all();

        $this->assertSame('09:00', $options[0]);
        $this->assertSame('15:00', end($options));
        $this->assertCount(7, $options);

        $this->assertFalse(Schedule::isWorkingHour('08:00'));
        $this->assertTrue(Schedule::isWorkingHour('09:00'));
        $this->assertTrue(Schedule::isWorkingHour('15:00'));
        $this->assertFalse(Schedule::isWorkingHour('16:00'));

        // On the hour is a different question from bookable, and the answer
        // does not move with the setting.
        $this->assertTrue(Schedule::isOnTheHour('08:00'));
        $this->assertFalse(Schedule::isOnTheHour('08:30'));
    }

    public function test_the_scheduling_page_hands_the_window_to_the_browser(): void
    {
        $this->storeHours('09:00', '15:00');

        $this->actingAs($this->account('super_admin'))
            ->get(route('super-admin.schedules.index'))
            ->assertOk()
            ->assertSee('window.partialDayHours', escape: false)
            ->assertSee('"start":9', escape: false)
            ->assertSee('"end":15', escape: false);
    }

    // ------------------------------------------------------------------
    // Creating and editing a partial-day schedule
    // ------------------------------------------------------------------

    public function test_a_partial_day_schedule_is_created_inside_the_configured_window(): void
    {
        $this->storeHours('09:00', '15:00');

        [$project, $schedule, $technician] = $this->partialDayProject('09:00', '11:00');

        $this->actingAs($this->account('super_admin'))
            ->put(route('super-admin.schedules.update', $project->project_id), [
                'ranges' => [[
                    'schedule_id' => $schedule->schedule_id,
                    'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
                    'project_date' => CarbonImmutable::tomorrow()->toDateString(),
                    'start_time' => '10:00',
                    'end_time' => '14:00',
                ]],
            ])
            ->assertSessionHas('success');

        $saved = $schedule->fresh();

        $this->assertSame('10:00', $saved->start_datetime->format('H:i'));
        $this->assertSame('14:00', $saved->end_datetime->format('H:i'));
    }

    public function test_an_edit_outside_the_configured_window_is_refused(): void
    {
        $this->storeHours('09:00', '15:00');

        [$project, $schedule] = $this->partialDayProject('09:00', '11:00');

        $this->actingAs($this->account('super_admin'))
            ->put(route('super-admin.schedules.update', $project->project_id), [
                'ranges' => [[
                    'schedule_id' => $schedule->schedule_id,
                    'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
                    'project_date' => CarbonImmutable::tomorrow()->toDateString(),
                    'start_time' => '09:00',
                    'end_time' => '16:00',
                ]],
            ]);

        // Refusals on this screen arrive as the page's error toast rather than
        // as field errors - see ScheduleController::update.
        $this->assertStringContainsString(
            'Choose a time on the hour between 9:00 AM and 3:00 PM.',
            (string) session('error')
        );
        $this->assertSame('11:00', $schedule->fresh()->end_datetime->format('H:i'));
    }

    // ------------------------------------------------------------------
    // Narrowing the window under a booking that already exists
    // ------------------------------------------------------------------

    /**
     * The case this whole section has to get right: a project booked for eight
     * o'clock, and the window then moved to nine.
     */
    public function test_narrowing_the_window_leaves_an_existing_booking_alone(): void
    {
        [$project, $schedule] = $this->partialDayProject('08:00', '12:00');

        $this->storeHours('09:00', '17:00');

        $saved = $schedule->fresh();

        $this->assertSame('08:00', $saved->start_datetime->format('H:i'));
        $this->assertSame('12:00', $saved->end_datetime->format('H:i'));
        $this->assertSame(CarbonImmutable::tomorrow()->toDateString(), $saved->startsOn()->toDateString());
        $this->assertTrue($saved->isPartialDay());
    }

    public function test_the_row_still_offers_the_hour_a_booking_already_holds(): void
    {
        $this->storeHours('09:00', '17:00');

        $values = collect(Schedule::workingHourOptionsIncluding('08:00', '12:00'));

        $this->assertSame('08:00', $values->first()['value']);
        $this->assertTrue($values->first()['outside']);
        // Sorted into place rather than tacked on the end, and the hours the
        // window does cover are unaffected.
        $this->assertSame('09:00', $values[1]['value']);
        $this->assertFalse($values[1]['outside']);
        $this->assertFalse($values->firstWhere('value', '12:00')['outside']);
    }

    /**
     * The form the booking sits on still saves. Resubmitting a row exactly as
     * it stands is not a new promise about those hours, so the narrowed window
     * has nothing to say about it.
     */
    public function test_an_untouched_booking_outside_the_window_still_saves(): void
    {
        [$project, $schedule] = $this->partialDayProject('08:00', '12:00');

        $this->storeHours('09:00', '17:00');

        $this->actingAs($this->account('super_admin'))
            ->put(route('super-admin.schedules.update', $project->project_id), [
                'ranges' => [[
                    'schedule_id' => $schedule->schedule_id,
                    'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
                    'project_date' => CarbonImmutable::tomorrow()->toDateString(),
                    'start_time' => '08:00',
                    'end_time' => '12:00',
                ]],
            ])
            ->assertSessionHas('success');

        $saved = $schedule->fresh();

        $this->assertSame('08:00', $saved->start_datetime->format('H:i'));
        $this->assertSame('12:00', $saved->end_datetime->format('H:i'));
    }

    /**
     * Change one hour of it, though, and it is a new promise - which the
     * narrowed window governs like any other.
     */
    public function test_changing_that_booking_is_held_to_the_new_window(): void
    {
        [$project, $schedule] = $this->partialDayProject('08:00', '12:00');

        $this->storeHours('09:00', '17:00');

        $this->actingAs($this->account('super_admin'))
            ->put(route('super-admin.schedules.update', $project->project_id), [
                'ranges' => [[
                    'schedule_id' => $schedule->schedule_id,
                    'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
                    'project_date' => CarbonImmutable::tomorrow()->toDateString(),
                    'start_time' => '08:00',
                    'end_time' => '13:00',
                ]],
            ]);

        $this->assertStringContainsString(
            'Choose a time on the hour between 9:00 AM and 5:00 PM.',
            (string) session('error')
        );
        $this->assertSame('12:00', $schedule->fresh()->end_datetime->format('H:i'));
    }

    // ------------------------------------------------------------------
    // The other ways a partial day gets booked
    // ------------------------------------------------------------------

    /**
     * Reopening a project books it onto new dates, and those dates are as new
     * a promise as any other - so the configured window governs them, through
     * the same interpreter every other screen goes through.
     */
    public function test_reopening_onto_a_partial_day_obeys_the_configured_window(): void
    {
        $this->storeHours('09:00', '15:00');

        [$project, $schedule] = $this->partialDayProject('09:00', '11:00');

        $project->forceFill([
            'status' => Project::STATUS_AWAITING_CLIENT_CONFIRMATION,
            'completion_requested_at' => now(),
            'completion_summary' => 'Everything on site is finished.',
        ])->save();

        $admin = $this->account('super_admin');
        $day = CarbonImmutable::tomorrow()->addDays(10)->toDateString();

        $this->actingAs($admin)
            ->post(route('super-admin.projects.reopen', $project->project_id), [
                'reopen_reason' => 'More work was found on site after the visit.',
                'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
                'project_date' => $day,
                'start_time' => '09:00',
                'end_time' => '16:00',
            ]);

        $this->assertStringContainsString(
            'Choose a time on the hour between 9:00 AM and 3:00 PM.',
            (string) session('error')
        );
        $this->assertSame(Project::STATUS_AWAITING_CLIENT_CONFIRMATION, $project->fresh()->status);

        $this->actingAs($admin)
            ->post(route('super-admin.projects.reopen', $project->project_id), [
                'reopen_reason' => 'More work was found on site after the visit.',
                'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
                'project_date' => $day,
                'start_time' => '10:00',
                'end_time' => '14:00',
            ]);

        $this->assertSame('ongoing', $project->fresh()->status);
        $this->assertNotNull(
            $project->fresh()->schedules()
                ->where('start_datetime', $day.' 10:00:00')
                ->where('end_datetime', $day.' 14:00:00')
                ->first()
        );
    }

    /**
     * Resuming a paused project does not touch its dates, so a booking made
     * before the window narrowed comes back exactly as it was.
     */
    public function test_resuming_a_paused_project_keeps_a_booking_outside_the_window(): void
    {
        [$project, $schedule] = $this->partialDayProject('08:00', '12:00');

        $project->forceFill(['on_hold' => true, 'status' => 'unscheduled'])->save();

        $this->storeHours('09:00', '17:00');

        $this->actingAs($this->account('super_admin'))
            ->put(route('super-admin.projects.resume', $project->project_id))
            ->assertRedirect();

        $saved = $schedule->fresh();

        $this->assertFalse((bool) $project->fresh()->on_hold);
        $this->assertSame('08:00', $saved->start_datetime->format('H:i'));
        $this->assertSame('12:00', $saved->end_datetime->format('H:i'));
        $this->assertTrue($saved->isPartialDay());
    }

    /**
     * Restoring an archived project brings its dates back as they were, hours
     * the window no longer covers included: the restore is the record coming
     * back, not a fresh promise about those hours.
     */
    public function test_restoring_a_project_keeps_its_partial_day_hours(): void
    {
        [$project, $schedule] = $this->partialDayProject('08:00', '12:00');

        $superAdmin = $this->account('super_admin');

        $this->actingAs($superAdmin)
            ->post(route('super-admin.projects.archive', $project->project_id))
            ->assertRedirect();

        $this->assertTrue((bool) $project->fresh()->is_archived);

        $this->storeHours('09:00', '17:00');

        $this->actingAs($superAdmin)
            ->put(route('super-admin.projects.restore', $project->project_id))
            ->assertRedirect();

        $saved = $schedule->fresh();

        $this->assertFalse((bool) $project->fresh()->is_archived);
        $this->assertSame('08:00', $saved->start_datetime->format('H:i'));
        $this->assertSame('12:00', $saved->end_datetime->format('H:i'));
        $this->assertTrue($saved->isPartialDay());
    }

    // ------------------------------------------------------------------
    // Bookings the window no longer covers
    // ------------------------------------------------------------------

    public function test_a_booking_outside_the_window_is_only_flagged_while_it_is_still_to_come(): void
    {
        [, $upcoming] = $this->partialDayProject('08:00', '12:00');
        [, $past] = $this->partialDayProject(
            '08:00',
            '12:00',
            CarbonImmutable::yesterday()->subDays(3)->toDateString()
        );
        [, $inside] = $this->partialDayProject('09:00', '12:00');

        $this->storeHours('09:00', '17:00');

        $this->assertTrue($upcoming->fresh()->isOutsidePartialDayHours());
        $this->assertTrue($upcoming->fresh()->needsHourCorrection());

        // Already worked. The record of a day that happened is not a to-do.
        $this->assertTrue($past->fresh()->isOutsidePartialDayHours());
        $this->assertFalse($past->fresh()->needsHourCorrection());

        $this->assertFalse($inside->fresh()->needsHourCorrection());
    }

    public function test_a_whole_day_booking_is_never_outside_the_window(): void
    {
        $this->storeHours('09:00', '15:00');

        $schedule = new Schedule([
            'start_datetime' => CarbonImmutable::tomorrow()->toDateString().' 00:00:00',
            'end_datetime' => CarbonImmutable::tomorrow()->addDays(2)->toDateString().' 23:59:59',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
        ]);

        $this->assertFalse($schedule->isOutsidePartialDayHours());
        $this->assertFalse($schedule->needsHourCorrection());
    }

    public function test_the_dashboard_raises_it_as_an_urgent_action(): void
    {
        [$project, $schedule] = $this->partialDayProject('08:00', '12:00');

        $this->storeHours('09:00', '17:00');

        $response = $this->actingAs($this->account('super_admin'))
            ->get(route('super-admin.dashboard'))
            ->assertOk()
            ->assertSee('1 Partial Day Outside Working Hours');

        // The link goes straight to the booking: the schedules page with that
        // project's editor already open.
        $response->assertSee(
            route('super-admin.schedules.index').'?openSchedule='.$project->project_id,
            escape: false
        );
    }

    public function test_the_dashboard_says_nothing_while_every_booking_fits(): void
    {
        $this->partialDayProject('09:00', '12:00');

        $this->storeHours('09:00', '17:00');

        $this->actingAs($this->account('super_admin'))
            ->get(route('super-admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Outside Working Hours');
    }

    public function test_a_finished_project_is_not_raised(): void
    {
        [$project] = $this->partialDayProject('08:00', '12:00');

        $project->forceFill(['status' => 'completed'])->save();

        $this->storeHours('09:00', '17:00');

        $this->assertCount(0, app(DashboardMetrics::class)->partialDaysOutsideHours());
    }

    public function test_the_schedule_editor_flags_the_row_it_was_sent_to(): void
    {
        [$project] = $this->partialDayProject('08:00', '12:00');

        $this->storeHours('09:00', '17:00');

        $this->actingAs($this->account('super_admin'))
            ->get(route('super-admin.schedules.index', ['openSchedule' => $project->project_id]))
            ->assertOk()
            ->assertSee('needs-hours', escape: false)
            ->assertSee('Outside working hours')
            ->assertSee('outside the')
            ->assertSee('9:00 AM to 5:00 PM');
    }

    // ------------------------------------------------------------------
    // Whole days are not touched
    // ------------------------------------------------------------------

    public function test_a_whole_day_range_is_unaffected_by_the_window(): void
    {
        $this->storeHours('09:00', '15:00');

        $project = Project::create([
            'name' => 'Office Retrofit',
            'reference_no' => 'REF-WD-1',
            'status' => 'pending',
            'address' => '2 Test Street',
            'description' => 'Description',
            'quotation' => 250000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Commercial',
            'company_name' => 'Some Holdings',
            'firstname' => 'Contact',
            'surname' => 'Person',
            'fullname' => 'Contact Person',
            'email_address' => 'whole.day@example.test',
            'contact_number' => '09123456789',
        ]);

        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => CarbonImmutable::tomorrow()->toDateString().' 00:00:00',
            'end_datetime' => CarbonImmutable::tomorrow()->addDays(3)->toDateString().' 23:59:59',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->account('super_admin'))
            ->put(route('super-admin.schedules.update', $project->project_id), [
                'ranges' => [[
                    'schedule_id' => $schedule->schedule_id,
                    'scheduling_mode' => Schedule::MODE_DATE_BASED,
                    'start_date' => CarbonImmutable::tomorrow()->toDateString(),
                    'end_date' => CarbonImmutable::tomorrow()->addDays(5)->toDateString(),
                ]],
            ])
            ->assertSessionHas('success');

        $saved = $schedule->fresh();

        $this->assertTrue($saved->isDateBased());
        $this->assertSame('00:00', $saved->start_datetime->format('H:i'));
        $this->assertSame('23:59', $saved->end_datetime->format('H:i'));
        $this->assertSame(
            CarbonImmutable::tomorrow()->addDays(5)->toDateString(),
            $saved->endsOn()->toDateString()
        );
    }
}
