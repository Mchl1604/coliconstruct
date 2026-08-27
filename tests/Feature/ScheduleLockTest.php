<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Technician;
use App\Models\User;
use App\Services\ScheduleModeRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How much of a booking may still be changed, and by whom.
 *
 * A schedule row is measured against today and falls into one of three states -
 * see Schedule::lockState(), which draws the line ScheduleHoldCutoff already
 * drew: work that has happened is the project's record, work still to come is a
 * promise that can be withdrawn.
 *
 *   future   nothing has been worked        everything may change
 *   active   started, not finished          the start is worked and is frozen;
 *                                           the end may move, never past today
 *   locked   ended before today             view only
 *
 * A Super Admin may correct a locked booking, having confirmed they mean to.
 * That lifts the lock and nothing else: an overridden save is still checked for
 * overlaps and for the availability of everybody on the job.
 */
class ScheduleLockTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);

        $user->forceFill(['role' => $role, 'status' => User::STATUS_ACTIVE])->save();

        return $user;
    }

    private function technician(string $name): Technician
    {
        return Technician::create([
            'account_id' => $this->account('technician', strtolower(str_replace(' ', '.', $name)).'@example.test')->id,
            'role' => 'technician',
        ]);
    }

    /**
     * @param  array<int, Technician>  $technicians
     */
    private function project(array $technicians = [], string $status = 'ongoing'): Project
    {
        $project = Project::create([
            'name' => 'Lock Test',
            'reference_no' => 'PRJ-'.random_int(1000, 9999),
            'status' => $status,
            'address' => '1 Test Street',
            'description' => 'Work',
            'quotation' => 1000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Commercial',
            'firstname' => 'Juan',
            'surname' => 'Dela Cruz',
            'fullname' => 'Juan Dela Cruz',
            'company_name' => 'Acme',
            'email_address' => 'client@example.test',
            'contact_number' => '09171234567',
        ]);

        foreach ($technicians as $technician) {
            ProjectTechnician::create([
                'project_id' => $project->project_id,
                'technician_id' => $technician->technician_id,
            ]);
        }

        return $project->refresh();
    }

    private function book(Project $project, int $startOffset, int $endOffset): Schedule
    {
        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $this->day($startOffset).' 00:00:00',
            'end_datetime' => $this->day($endOffset).' 23:59:59',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        foreach ($project->projectTechnicians()->get() as $assignment) {
            ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $assignment->project_technician_id,
            ]);
        }

        return $schedule;
    }

    private function day(int $offset): string
    {
        return Schedule::businessToday()->addDays($offset)->toDateString();
    }

    /**
     * @param  array<int, array<string, mixed>>  $ranges
     */
    private function save(Project $project, array $ranges, bool $override = false)
    {
        return $this->put(
            route('super-admin.schedules.update', $project->project_id),
            ['ranges' => $ranges] + ($override ? ['override_past_lock' => '1'] : [])
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function range(Schedule $schedule, int $startOffset, int $endOffset): array
    {
        return [
            'schedule_id' => $schedule->schedule_id,
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => $this->day($startOffset),
            'end_date' => $this->day($endOffset),
        ];
    }

    /**
     * @return array<int, array{start: string, end: string}>
     */
    private function rangesOf(Project $project): array
    {
        return Schedule::query()
            ->where('project_id', $project->project_id)
            ->orderBy('start_datetime')
            ->get()
            ->map(fn (Schedule $schedule): array => [
                'start' => $schedule->startsOn()->toDateString(),
                'end' => $schedule->endsOn()->toDateString(),
            ])
            ->all();
    }

    // ------------------------------------------------------------------
    // The three states
    // ------------------------------------------------------------------

    public function test_a_booking_is_locked_only_once_its_whole_range_has_ended(): void
    {
        $project = $this->project();

        // The boundary, from both sides: a range ending yesterday is history,
        // one ending today is not - it is still being worked.
        $this->assertSame(Schedule::LOCK_LOCKED, $this->book($project, -5, -1)->lockState());
        $this->assertSame(Schedule::LOCK_ACTIVE, $this->book($project, -5, 0)->lockState());
        $this->assertSame(Schedule::LOCK_ACTIVE, $this->book($project, -5, 3)->lockState());
        $this->assertSame(Schedule::LOCK_FUTURE, $this->book($project, 0, 3)->lockState());
        $this->assertSame(Schedule::LOCK_FUTURE, $this->book($project, 2, 4)->lockState());
    }

    /**
     * A partial day occupies one date, so it needs no rule of its own: it is
     * history from the day after, and until then it is a booking that has not
     * begun - exactly as a whole-day range starting today has not begun.
     *
     * Its hours are guarded separately and always were: an hour that has gone
     * by today cannot be re-promised, whatever this says.
     */
    public function test_a_partial_day_locks_the_day_after_its_date(): void
    {
        $project = $this->project();

        $states = [];

        foreach ([-1, 0, 1] as $offset) {
            $schedule = Schedule::create([
                'project_id' => $project->project_id,
                'start_datetime' => $this->day($offset).' 08:00:00',
                'end_datetime' => $this->day($offset).' 12:00:00',
                'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
                'status' => 'scheduled',
            ]);

            $states[] = $schedule->lockState();
        }

        $this->assertSame(
            [Schedule::LOCK_LOCKED, Schedule::LOCK_FUTURE, Schedule::LOCK_FUTURE],
            $states
        );
    }

    /**
     * Measured against the office clock, not the server's. A range is not
     * history eight hours early because the server is on UTC.
     */
    public function test_the_line_is_drawn_on_the_business_clock(): void
    {
        $project = $this->project();
        $schedule = $this->book($project, 0, 0);

        $this->assertSame(Schedule::LOCK_FUTURE, $schedule->lockState());
        $this->assertFalse($schedule->isLocked());
        $this->assertSame(
            Schedule::businessToday()->toDateString(),
            CarbonImmutable::now(Schedule::BUSINESS_TIMEZONE)->toDateString()
        );
    }

    // ------------------------------------------------------------------
    // A locked range, as an Admin sees it
    // ------------------------------------------------------------------

    public function test_an_admin_cannot_edit_a_locked_range(): void
    {
        $this->actingAs($this->account('admin', 'admin@example.test'));

        $project = $this->project();
        $locked = $this->book($project, -8, -6);

        $this->save($project, [$this->range($locked, -9, -6)]);

        $this->assertStringContainsString('already ended', (string) session('error'));
        $this->assertSame(
            [['start' => $this->day(-8), 'end' => $this->day(-6)]],
            $this->rangesOf($project)
        );
    }

    /**
     * The rule that matters most. The editor draws a locked row read-only and
     * submits nothing for it, so an omission must NOT read as a deletion -
     * otherwise editing a future range would quietly destroy the record of a
     * past one every single time.
     */
    public function test_a_locked_range_survives_being_left_out_of_the_form(): void
    {
        $this->actingAs($this->account('admin', 'admin@example.test'));

        $project = $this->project();
        $locked = $this->book($project, -8, -6);
        $future = $this->book($project, 3, 5);

        // Exactly what the editor sends: the future row only.
        $this->save($project, [$this->range($future, 4, 6)])->assertSessionHasNoErrors();

        $this->assertSame([
            ['start' => $this->day(-8), 'end' => $this->day(-6)],
            ['start' => $this->day(4), 'end' => $this->day(6)],
        ], $this->rangesOf($project));

        $this->assertNotNull(Schedule::find($locked->schedule_id));
    }

    /**
     * The same protection against a form that submits everything, which is what
     * a stale page left open across midnight would do.
     */
    public function test_a_locked_range_resubmitted_unchanged_is_not_a_change(): void
    {
        $this->actingAs($this->account('admin', 'admin@example.test'));

        $project = $this->project();
        $locked = $this->book($project, -8, -6);
        $future = $this->book($project, 3, 5);

        $this->save($project, [
            $this->range($locked, -8, -6),
            $this->range($future, 3, 5),
        ])->assertSessionHasNoErrors();

        $this->assertSame([
            ['start' => $this->day(-8), 'end' => $this->day(-6)],
            ['start' => $this->day(3), 'end' => $this->day(5)],
        ], $this->rangesOf($project));
    }

    // ------------------------------------------------------------------
    // An active range
    // ------------------------------------------------------------------

    public function test_the_end_of_a_started_range_may_be_moved_forward(): void
    {
        $this->actingAs($this->account('admin', 'admin@example.test'));

        $project = $this->project();
        $active = $this->book($project, -3, 2);

        $this->save($project, [$this->range($active, -3, 6)])->assertSessionHasNoErrors();

        $this->assertSame(
            [['start' => $this->day(-3), 'end' => $this->day(6)]],
            $this->rangesOf($project)
        );
    }

    public function test_the_start_of_a_started_range_is_frozen_for_an_admin(): void
    {
        $this->actingAs($this->account('admin', 'admin@example.test'));

        $project = $this->project();
        $active = $this->book($project, -3, 4);

        $this->save($project, [$this->range($active, 0, 4)]);

        $this->assertStringContainsString('Super Admin access is required to move its start date', (string) session('error'));
        $this->assertSame(
            [['start' => $this->day(-3), 'end' => $this->day(4)]],
            $this->rangesOf($project)
        );
    }

    public function test_a_started_range_may_not_be_pulled_back_to_end_before_today(): void
    {
        $this->actingAs($this->account('admin', 'admin@example.test'));

        $project = $this->project();
        $active = $this->book($project, -5, 4);

        $this->save($project, [$this->range($active, -5, -2)]);

        $this->assertStringContainsString('Choose an end date of today or later', (string) session('error'));
        $this->assertSame(
            [['start' => $this->day(-5), 'end' => $this->day(4)]],
            $this->rangesOf($project)
        );
    }

    /**
     * The boundary: ending a booking today is shortening it as far as it goes.
     */
    public function test_a_started_range_may_be_shortened_to_end_today(): void
    {
        $this->actingAs($this->account('admin', 'admin@example.test'));

        $project = $this->project();
        $active = $this->book($project, -5, 4);

        $this->save($project, [$this->range($active, -5, 0)])->assertSessionHasNoErrors();

        $this->assertSame(
            [['start' => $this->day(-5), 'end' => $this->day(0)]],
            $this->rangesOf($project)
        );
    }

    // ------------------------------------------------------------------
    // A future range is untouched by any of this
    // ------------------------------------------------------------------

    public function test_a_future_range_is_still_fully_editable(): void
    {
        $this->actingAs($this->account('admin', 'admin@example.test'));

        $project = $this->project();
        $future = $this->book($project, 3, 5);

        $this->save($project, [$this->range($future, 6, 9)])->assertSessionHasNoErrors();

        $this->assertSame(
            [['start' => $this->day(6), 'end' => $this->day(9)]],
            $this->rangesOf($project)
        );
    }

    public function test_a_future_range_may_still_be_deleted_by_leaving_it_out(): void
    {
        $this->actingAs($this->account('admin', 'admin@example.test'));

        $project = $this->project();
        $this->book($project, 3, 5);

        $this->save($project, [])->assertSessionHasNoErrors();

        $this->assertSame([], $this->rangesOf($project));
    }

    /**
     * Unchanged: a brand new booking may not be made about a day that has gone.
     */
    public function test_a_new_range_still_cannot_start_in_the_past(): void
    {
        $this->actingAs($this->account('admin', 'admin@example.test'));

        $project = $this->project();

        $this->save($project, [[
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => $this->day(-3),
            'end_date' => $this->day(2),
        ]]);

        $this->assertStringContainsString('cannot be in the past', (string) session('error'));
        $this->assertSame([], $this->rangesOf($project));
    }

    // ------------------------------------------------------------------
    // The Super Admin override
    // ------------------------------------------------------------------

    public function test_a_super_admin_may_correct_a_locked_range(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $locked = $this->book($project, -8, -6);

        $this->save($project, [$this->range($locked, -9, -7)], override: true)
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [['start' => $this->day(-9), 'end' => $this->day(-7)]],
            $this->rangesOf($project)
        );
    }

    public function test_a_super_admin_may_delete_a_locked_range_with_the_override(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $locked = $this->book($project, -8, -6);

        $this->save($project, [], override: true)->assertSessionHasNoErrors();

        $this->assertNull(Schedule::find($locked->schedule_id));
        $this->assertSame([], $this->rangesOf($project));
    }

    /**
     * The role is what makes the override count. A form flag on its own is not
     * a permission, which is what stops a crafted request standing in for one.
     */
    public function test_the_override_flag_does_nothing_for_an_admin(): void
    {
        $this->actingAs($this->account('admin', 'admin@example.test'));

        $project = $this->project();
        $locked = $this->book($project, -8, -6);

        $this->save($project, [$this->range($locked, -9, -7)], override: true);

        $this->assertStringContainsString('already ended', (string) session('error'));
        $this->assertSame(
            [['start' => $this->day(-8), 'end' => $this->day(-6)]],
            $this->rangesOf($project)
        );
    }

    /**
     * And the confirmation is what makes it deliberate. A Super Admin who has
     * not confirmed is refused exactly as an Admin is.
     */
    public function test_a_super_admin_without_the_confirmation_is_refused(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $locked = $this->book($project, -8, -6);

        $this->save($project, [$this->range($locked, -9, -7)]);

        $this->assertStringContainsString('already ended', (string) session('error'));
        $this->assertSame(
            [['start' => $this->day(-8), 'end' => $this->day(-6)]],
            $this->rangesOf($project)
        );
    }

    /**
     * Overriding a booking that is UNDER WAY only releases its frozen start,
     * and releases it forwards. Stretching a live booking back over days
     * nobody worked would be inventing history rather than correcting it.
     */
    public function test_an_override_cannot_stretch_a_started_range_further_back(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $active = $this->book($project, -3, 4);

        $this->save($project, [$this->range($active, -6, 4)], override: true);

        $this->assertStringContainsString('Choose a start date of today or later', (string) session('error'));
        $this->assertSame(
            [['start' => $this->day(-3), 'end' => $this->day(4)]],
            $this->rangesOf($project)
        );
    }

    public function test_an_override_may_move_a_started_range_forward(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $active = $this->book($project, -3, 4);

        $this->save($project, [$this->range($active, 0, 4)], override: true)
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [['start' => $this->day(0), 'end' => $this->day(4)]],
            $this->rangesOf($project)
        );
    }

    /**
     * An override must never be STRICTER than no override.
     *
     * The floor on a live booking's start is where that booking already
     * starts, not today - so confirming an override still lets a Super Admin
     * do the ordinary thing an Admin can: leave the start alone and push the
     * end out. Measuring against today instead refused the start the booking
     * already held, which made the override useless on exactly the rows it was
     * meant to help with.
     */
    public function test_an_override_still_allows_extending_a_started_range(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $active = $this->book($project, -3, 2);

        $this->save($project, [$this->range($active, -3, 6)], override: true)
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [['start' => $this->day(-3), 'end' => $this->day(6)]],
            $this->rangesOf($project)
        );
    }

    /**
     * And the floor it does apply: forward from where the booking stands.
     */
    public function test_an_override_may_move_a_started_range_forward_by_one_day(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $active = $this->book($project, -3, 6);

        $this->save($project, [$this->range($active, -2, 6)], override: true)
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [['start' => $this->day(-2), 'end' => $this->day(6)]],
            $this->rangesOf($project)
        );
    }

    /**
     * The editor has to be able to SHOW the start it is offering to change.
     *
     * The date picker takes this floor as its minimum, and flatpickr blanks a
     * value that sits below its minimum - so a floor of today on a booking
     * that began earlier left the field looking empty, as though the date had
     * been lost.
     */
    public function test_the_editor_can_display_the_start_of_a_started_range(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->book($project, -3, 4);

        $this->get(route('super-admin.schedules.index'))
            ->assertOk()
            // The floor never excludes the value the field is showing.
            ->assertSee('data-earliest-start="'.$this->day(-3).'"', false)
            ->assertSee('value="'.$this->day(-3).'"', false);
    }

    /**
     * The override lifts the lock and nothing else. A correction that
     * double-books somebody is not a correction.
     */
    public function test_an_override_is_still_checked_against_technician_availability(): void
    {
        $this->actingAsSuperAdmin();

        $shared = $this->technician('Jose Garcia');

        $elsewhere = $this->project([$shared]);
        $this->book($elsewhere, 3, 6);

        $project = $this->project([$shared]);
        $locked = $this->book($project, -8, -6);

        // Corrected forwards onto days the technician is already booked.
        $this->save($project, [$this->range($locked, 4, 5)], override: true);

        $this->assertNotNull(session('error'));
        $this->assertSame(
            [['start' => $this->day(-8), 'end' => $this->day(-6)]],
            $this->rangesOf($project)
        );
    }

    public function test_an_override_is_recorded_as_an_override(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $locked = $this->book($project, -8, -6);

        $this->save($project, [$this->range($locked, -9, -7)], override: true);

        $this->assertTrue(
            ActivityLog::query()
                ->where('action', ActivityLog::PROJECT_RESCHEDULED)
                ->get()
                ->contains(fn (ActivityLog $log): bool => str_contains(
                    (string) $log->description,
                    'Overrode the past-schedule lock'
                ))
        );
    }

    /**
     * Moving the start of a booking already under way is a correction to the
     * record too: that day was worked, the start is frozen for everybody else,
     * and only the override releases it. The trail has to say so, or the one
     * change that rewrote a worked day reads as a routine reschedule.
     */
    public function test_moving_a_started_range_start_is_recorded_as_an_override(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $active = $this->book($project, -3, 4);

        $this->save($project, [$this->range($active, -1, 4)], override: true)
            ->assertSessionHasNoErrors();

        $this->assertTrue(
            ActivityLog::query()
                ->where('action', ActivityLog::PROJECT_RESCHEDULED)
                ->get()
                ->contains(fn (ActivityLog $log): bool => str_contains(
                    (string) $log->description,
                    'Overrode the past-schedule lock'
                ))
        );
    }

    /**
     * Extending the END of a live booking is ordinary work, though - those are
     * days still to come, and nothing about the record changes.
     */
    public function test_extending_a_started_range_is_not_recorded_as_an_override(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $active = $this->book($project, -3, 4);

        $this->save($project, [$this->range($active, -3, 8)], override: true)
            ->assertSessionHasNoErrors();

        $this->assertFalse(
            ActivityLog::query()
                ->where('action', ActivityLog::PROJECT_RESCHEDULED)
                ->get()
                ->contains(fn (ActivityLog $log): bool => str_contains(
                    (string) $log->description,
                    'Overrode the past-schedule lock'
                ))
        );
    }

    /**
     * A save that touches nothing locked is a routine reschedule, and must not
     * be filed as a correction to the record.
     */
    public function test_an_ordinary_reschedule_is_not_recorded_as_an_override(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $locked = $this->book($project, -8, -6);
        $future = $this->book($project, 3, 5);

        // The locked row resubmitted untouched beside a future one that moved -
        // which is what a stale form sends.
        $this->save($project, [
            $this->range($locked, -8, -6),
            $this->range($future, 6, 8),
        ], override: true)->assertSessionHasNoErrors();

        $this->assertFalse(
            ActivityLog::query()
                ->where('action', ActivityLog::PROJECT_RESCHEDULED)
                ->get()
                ->contains(fn (ActivityLog $log): bool => str_contains(
                    (string) $log->description,
                    'Overrode the past-schedule lock'
                ))
        );
    }

    // ------------------------------------------------------------------
    // What the editor draws
    // ------------------------------------------------------------------

    public function test_the_editor_draws_a_locked_row_read_only_for_an_admin(): void
    {
        $this->actingAs($this->account('admin', 'admin@example.test'));

        $project = $this->project();
        $this->book($project, -8, -6);

        $this->get(route('super-admin.schedules.index'))
            ->assertOk()
            ->assertSee('data-lock-state="locked"', false)
            ->assertSee('data-locked="1"', false)
            ->assertSee('Super Admin access is required to make changes.', false)
            // A locked row gets no date picker, so nothing would otherwise turn
            // its stored value into the format every other row shows. The same
            // booking must not read two different ways depending on who opened
            // the page.
            ->assertSee('value="'.Schedule::businessToday()->subDays(8)->format('M j, Y').'"', false);
    }

    /**
     * A Super Admin sees the same row live, so the confirmation on save is the
     * only thing between them and a correction - which is what was asked for.
     */
    public function test_the_editor_leaves_a_locked_row_live_for_a_super_admin(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->book($project, -8, -6);

        $this->get(route('super-admin.schedules.index'))
            ->assertOk()
            ->assertSee('data-lock-state="locked"', false)
            ->assertSee('data-locked="0"', false)
            ->assertDontSee('Super Admin access is required to make changes.', false);
    }

    public function test_the_editor_freezes_the_start_of_a_started_row(): void
    {
        $this->actingAs($this->account('admin', 'admin@example.test'));

        $project = $this->project();
        $this->book($project, -3, 4);

        $this->get(route('super-admin.schedules.index'))
            ->assertOk()
            ->assertSee('data-lock-state="active"', false)
            ->assertSee('data-start-frozen="1"', false)
            // The end may still move, but not back past today.
            ->assertSee('data-earliest-end="'.$this->day(0).'"', false)
            ->assertSee('Its start date is fixed', false);
    }

    /**
     * A row being ADDED is a promise about work still to come, so its pickers
     * start at today.
     *
     * The template rows the editor clones for a new range used to carry no
     * floor at all, which left last month there to be clicked - and clicked,
     * then refused on save by validateDateBased(). The picker now offers
     * exactly what the save accepts.
     */
    public function test_a_new_range_row_cannot_reach_into_the_past(): void
    {
        $this->actingAsSuperAdmin();

        $this->project();

        $response = $this->get(route('super-admin.schedules.index'));
        $response->assertOk();

        $today = Schedule::businessToday()->toDateString();

        // This project has no saved bookings, so the only rows on the page are
        // the two the editor clones for a new range - the whole-day one and
        // the partial-day one. Both carry the floor at both ends.
        $content = $response->getContent();

        $this->assertSame(2, substr_count($content, 'data-earliest-start="'.$today.'"'));
        $this->assertSame(2, substr_count($content, 'data-earliest-end="'.$today.'"'));

        // And nothing on the page offers an unbounded start any more.
        $this->assertSame(0, substr_count($content, 'data-earliest-start=""'));
    }

    public function test_the_new_range_floor_is_the_one_the_save_enforces(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();

        $limits = app(ScheduleModeRules::class)->limitsForNewRange();

        $this->assertTrue($limits['earliestStart']->equalTo(Schedule::businessToday()));
        $this->assertTrue($limits['earliestEnd']->equalTo(Schedule::businessToday()));

        // A date one day below that floor is what the save refuses, so the
        // picker and the validator are drawing the same line.
        $this->save($project, [[
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => $this->day(-1),
            'end_date' => $this->day(3),
        ]]);

        $this->assertSame([], $this->rangesOf($project));
    }

    // ------------------------------------------------------------------
    // Everything else that touches schedules is left alone
    // ------------------------------------------------------------------

    /**
     * A hold is the system dividing bookings at the day it was placed, not a
     * person retyping dates - so it still keeps what was worked on the near
     * side of that line and preserves the rest as the project's proposal,
     * locked rows included. The lock rules do not stand in its way.
     */
    public function test_a_hold_still_divides_bookings_at_the_day_it_is_placed(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([$this->technician('Jose Garcia')]);
        $this->book($project, -8, -6);
        $this->book($project, -2, 5);
        $this->book($project, 8, 10);

        $this->put(route('super-admin.projects.hold', $project->project_id), [
            'hold_reason' => 'Waiting on materials from the supplier.',
        ]);

        $this->assertSame([
            // Ended before the hold: kept as it stands.
            ['start' => $this->day(-8), 'end' => $this->day(-6)],
            // Crossed it: shortened to the day of the hold...
            ['start' => $this->day(-2), 'end' => $this->day(0)],
            // ...with the rest of it preserved as a range of its own.
            ['start' => $this->day(1), 'end' => $this->day(5)],
            // Entirely ahead of it: preserved whole.
            ['start' => $this->day(8), 'end' => $this->day(10)],
        ], $this->rangesOf($project));
    }

    /**
     * A completed project is closed to everybody, override or not: the project
     * gate is asked before the row's, so the reason names the project.
     */
    public function test_a_read_only_project_is_refused_before_the_lock_is_consulted(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $locked = $this->book($project, -8, -6);

        $project->forceFill(['status' => 'completed'])->save();

        $this->save($project, [$this->range($locked, -9, -7)], override: true);

        $this->assertStringContainsString('completed', (string) session('error'));
        $this->assertSame(
            [['start' => $this->day(-8), 'end' => $this->day(-6)]],
            $this->rangesOf($project)
        );
    }
}
