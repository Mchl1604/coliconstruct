<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleCorrection;
use App\Models\ScheduleTechnician;
use App\Models\Technician;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editing a schedule that has already run.
 *
 * A Super Admin may correct history - the record has to be correctable or a
 * wrong entry is wrong forever. What may not happen is a day in the past
 * quietly landing on a project with nobody's name against it, because a
 * schedule row is the statement that this job was on site that day.
 *
 * So a save is measured against what is stored, day by day, and only the days
 * it NEWLY claims are asked about:
 *
 *   unchanged   resubmitted as it stands              nothing asked
 *   extended    reaches further back than it did      the days it gained
 *   created     a new range landing on days gone by   all of its past days
 *   moved       now occupies different days           the days it took up
 *   shrunk      gives days back                       nothing asked, still logged
 */
class HistoricalScheduleCorrectionTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function account(string $role, string $email, ?string $name = null): User
    {
        $user = User::factory()->create(['email' => $email]);

        $user->forceFill(array_filter([
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            // The name is asserted on all through this suite - it is what a
            // correction records against the days it adds - so it is set rather
            // than left to the factory.
            'name' => $name,
        ]))->save();

        return $user;
    }

    private function technician(string $name, string $role = 'technician'): Technician
    {
        return Technician::create([
            'account_id' => $this->account(
                $role,
                strtolower(str_replace(' ', '.', $name)).'@example.test',
                $name
            )->id,
            'role' => $role,
        ]);
    }

    /**
     * A crew recorded against a day has to have one of these on it - the same
     * rule ProjectTeamRules applies to a team being built - so most of the
     * technicians attributed in this suite are lead technicians.
     */
    private function leadTechnician(string $name): Technician
    {
        return $this->technician($name, 'lead_technician');
    }

    /**
     * @param  array<int, Technician>  $technicians
     */
    private function project(array $technicians = [], string $status = 'ongoing'): Project
    {
        $project = Project::create([
            'name' => 'Historical Test',
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
                // Long enough ago to cover every date these tests reach for,
                // so a membership never quietly fails to cover the days being
                // attributed for a reason the test did not intend.
                'joined_at' => Schedule::businessToday()->subDays(90),
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
     * @param  array<int, int>  $technicianIds
     */
    private function save(
        Project $project,
        array $ranges,
        bool $override = false,
        array $technicianIds = [],
        bool $addTechnicians = false
    ) {
        $payload = ['ranges' => $ranges];

        if ($override) {
            $payload['override_past_lock'] = '1';
        }

        if ($technicianIds !== []) {
            $payload['historical_technicians'] = $technicianIds;
        }

        if ($addTechnicians) {
            $payload['historical_add_technicians'] = '1';
        }

        return $this->put(route('super-admin.schedules.update', $project->project_id), $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function range(?Schedule $schedule, int $startOffset, int $endOffset): array
    {
        return array_filter([
            'schedule_id' => $schedule?->schedule_id,
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => $this->day($startOffset),
            'end_date' => $this->day($endOffset),
        ], fn ($value): bool => $value !== null);
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

    /**
     * Which technicians are booked on the row covering the given day.
     *
     * @return array<int, string>
     */
    private function crewOn(Project $project, string $date): array
    {
        return Schedule::query()
            ->where('project_id', $project->project_id)
            ->whereDate('start_datetime', '<=', $date)
            ->whereDate('end_datetime', '>=', $date)
            ->with('scheduleTechnicians.projectTechnician.technician')
            ->get()
            ->flatMap(fn (Schedule $schedule) => $schedule->scheduleTechnicians
                ->map(fn (ScheduleTechnician $link): ?string => $link->projectTechnician?->technician?->name))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------
    // 1. An existing past schedule, left alone
    // ------------------------------------------------------------------

    public function test_an_unchanged_past_schedule_saves_without_asking_anything(): void
    {
        $this->actingAsSuperAdmin();

        $jose = $this->technician('Jose Garcia');
        $project = $this->project([$jose]);
        $past = $this->book($project, -8, -6);

        $this->save($project, [$this->range($past, -8, -6)], override: true)
            ->assertSessionHasNoErrors();

        $this->assertSame([['start' => $this->day(-8), 'end' => $this->day(-6)]], $this->rangesOf($project));
        $this->assertSame(['Jose Garcia'], $this->crewOn($project, $this->day(-7)));
        // Nothing changed hands, so nothing was corrected.
        $this->assertSame(0, ScheduleCorrection::count());
    }

    /**
     * The crew on a past range belongs to the days it covers, and resaving the
     * form is not a staffing decision. Somebody added to the project since must
     * not appear against a week they were not on.
     */
    public function test_an_unchanged_past_schedule_keeps_the_crew_it_had(): void
    {
        $this->actingAsSuperAdmin();

        $jose = $this->technician('Jose Garcia');
        $project = $this->project([$jose]);
        $past = $this->book($project, -8, -6);

        $newcomer = $this->technician('Ana Reyes');
        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $newcomer->technician_id,
            'joined_at' => Schedule::businessToday(),
        ]);

        $this->save($project->refresh(), [$this->range($past, -8, -6)], override: true)
            ->assertSessionHasNoErrors();

        $this->assertSame(['Jose Garcia'], $this->crewOn($project, $this->day(-7)));
    }

    // ------------------------------------------------------------------
    // 2. A brand new past range
    // ------------------------------------------------------------------

    public function test_a_new_past_range_is_refused_until_somebody_is_named(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([$this->technician('Jose Garcia')]);

        $this->save($project, [$this->range(null, -5, -3)], override: true);

        $this->assertStringContainsString('Say who worked them', (string) session('error'));
        $this->assertSame([], $this->rangesOf($project));
        $this->assertSame(0, ScheduleCorrection::count());
    }

    public function test_a_new_past_range_is_saved_with_the_crew_that_worked_it(): void
    {
        $this->actingAsSuperAdmin();

        $jose = $this->leadTechnician('Jose Garcia');
        $project = $this->project([$jose, $this->technician('Ana Reyes')]);

        $this->save(
            $project,
            [$this->range(null, -5, -3)],
            override: true,
            technicianIds: [$jose->technician_id]
        )->assertSessionHasNoErrors();

        $this->assertSame([['start' => $this->day(-5), 'end' => $this->day(-3)]], $this->rangesOf($project));

        // Only the person named. A range wholly in the past is a record of who
        // was there, not an assignment handed to today's team.
        $this->assertSame(['Jose Garcia'], $this->crewOn($project, $this->day(-4)));
    }

    /**
     * An Admin is not refused for want of a name. They may not record work
     * already done at all, so the date itself is what is refused - the flag on
     * the form is not a permission, and neither is naming somebody.
     */
    public function test_an_admin_cannot_record_a_past_range_at_all(): void
    {
        $this->actingAs($this->account('admin', 'admin@example.test'));

        $jose = $this->technician('Jose Garcia');
        $project = $this->project([$jose]);

        $this->save(
            $project,
            [$this->range(null, -5, -3)],
            override: true,
            technicianIds: [$jose->technician_id]
        );

        $this->assertStringContainsString('cannot be in the past', (string) session('error'));
        $this->assertSame([], $this->rangesOf($project));
        $this->assertSame(0, ScheduleCorrection::count());
    }

    // ------------------------------------------------------------------
    // 3. An existing range extended backwards
    // ------------------------------------------------------------------

    /**
     * The case the whole feature is named for. Aug 20-26 becoming Aug 17-26 has
     * added three days and kept seven; only the three are asked about, and the
     * seven keep the crew they already had.
     */
    public function test_extending_a_range_backwards_asks_only_about_the_days_it_gained(): void
    {
        $this->actingAsSuperAdmin();

        $jose = $this->technician('Jose Garcia');
        $ana = $this->leadTechnician('Ana Reyes');
        $project = $this->project([$jose, $ana]);

        // Jose worked the week that is already on the record.
        $existing = $this->book($project, -7, -1);
        ScheduleTechnician::query()
            ->where('schedule_id', $existing->schedule_id)
            ->whereIn('project_technician_id', ProjectTechnician::query()
                ->where('project_id', $project->project_id)
                ->where('technician_id', $ana->technician_id)
                ->pluck('project_technician_id'))
            ->delete();

        $this->save(
            $project,
            [$this->range($existing, -10, -1)],
            override: true,
            technicianIds: [$ana->technician_id]
        )->assertSessionHasNoErrors();

        $this->assertSame([['start' => $this->day(-10), 'end' => $this->day(-1)]], $this->rangesOf($project));

        $correction = ScheduleCorrection::query()->firstOrFail();

        // Exactly the three days it gained, and nothing about the seven it
        // already held.
        $this->assertSame(
            [$this->day(-10), $this->day(-9), $this->day(-8)],
            $correction->added_dates
        );
        $this->assertSame(['Ana Reyes'], $correction->technicianNames());
        $this->assertSame([], $correction->removed_dates);
    }

    public function test_extending_a_range_backwards_without_a_name_changes_nothing(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([$this->technician('Jose Garcia')]);
        $existing = $this->book($project, -7, -1);

        $this->save($project, [$this->range($existing, -10, -1)], override: true);

        $this->assertStringContainsString('Say who worked them', (string) session('error'));
        $this->assertSame([['start' => $this->day(-7), 'end' => $this->day(-1)]], $this->rangesOf($project));
    }

    /**
     * The crew already on the extended row stays on it. They worked the days it
     * already covered, and the correction says nothing about them.
     */
    public function test_extending_a_range_backwards_keeps_the_existing_crew(): void
    {
        $this->actingAsSuperAdmin();

        $jose = $this->technician('Jose Garcia');
        $ana = $this->leadTechnician('Ana Reyes');
        $project = $this->project([$jose, $ana]);

        $existing = $this->book($project, -7, -1);

        $this->save(
            $project,
            [$this->range($existing, -10, -1)],
            override: true,
            technicianIds: [$ana->technician_id]
        )->assertSessionHasNoErrors();

        $this->assertSame(['Ana Reyes', 'Jose Garcia'], $this->crewOn($project, $this->day(-3)));
    }

    // ------------------------------------------------------------------
    // 4. Forwards, which is not history at all
    // ------------------------------------------------------------------

    public function test_extending_a_range_forwards_asks_nothing(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([$this->technician('Jose Garcia')]);
        $active = $this->book($project, -2, 2);

        $this->save($project, [$this->range($active, -2, 6)])
            ->assertSessionHasNoErrors();

        $this->assertSame([['start' => $this->day(-2), 'end' => $this->day(6)]], $this->rangesOf($project));
        $this->assertSame(0, ScheduleCorrection::count());
    }

    public function test_an_ordinary_future_reschedule_records_no_correction(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([$this->technician('Jose Garcia')]);
        $future = $this->book($project, 3, 5);

        $this->save($project, [$this->range($future, 4, 8)])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, ScheduleCorrection::count());
    }

    // ------------------------------------------------------------------
    // 5. Giving days back
    // ------------------------------------------------------------------

    public function test_removing_past_dates_is_allowed_and_asks_nobody(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([$this->technician('Jose Garcia')]);
        $past = $this->book($project, -8, -4);

        $this->save($project, [$this->range($past, -6, -4)], override: true)
            ->assertSessionHasNoErrors();

        $this->assertSame([['start' => $this->day(-6), 'end' => $this->day(-4)]], $this->rangesOf($project));

        $correction = ScheduleCorrection::query()->firstOrFail();

        $this->assertSame([], $correction->added_dates);
        $this->assertSame([$this->day(-8), $this->day(-7)], $correction->removed_dates);
        $this->assertSame([], $correction->technicians);
    }

    /**
     * A range deleted outright takes its days with it, and the record of them
     * going has to outlive the row that carried them.
     */
    public function test_deleting_a_past_range_is_recorded_as_a_correction(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([$this->technician('Jose Garcia')]);
        $past = $this->book($project, -8, -7);

        $this->save($project, [], override: true)->assertSessionHasNoErrors();

        $correction = ScheduleCorrection::query()->firstOrFail();

        $this->assertSame([$this->day(-8), $this->day(-7)], $correction->removed_dates);
        $this->assertNull($correction->new_range);
        $this->assertSame((int) $past->schedule_id, (int) $correction->schedule_id);
    }

    // ------------------------------------------------------------------
    // 6. Moved onto different past days
    // ------------------------------------------------------------------

    /**
     * Moving a past range is two things at once, and the old crew is not simply
     * carried across: the days it now occupies were not worked by anybody as
     * far as the record is concerned, so they have to be attributed.
     */
    public function test_moving_a_past_range_asks_about_the_days_it_now_occupies(): void
    {
        $this->actingAsSuperAdmin();

        $jose = $this->technician('Jose Garcia');
        $ana = $this->leadTechnician('Ana Reyes');
        $project = $this->project([$jose, $ana]);

        $past = $this->book($project, -8, -7);

        $this->save($project, [$this->range($past, -4, -3)], override: true);
        $this->assertStringContainsString('Say who worked them', (string) session('error'));

        $this->save(
            $project,
            [$this->range($past, -4, -3)],
            override: true,
            technicianIds: [$ana->technician_id]
        )->assertSessionHasNoErrors();

        $this->assertSame([['start' => $this->day(-4), 'end' => $this->day(-3)]], $this->rangesOf($project));

        $correction = ScheduleCorrection::query()->latest('schedule_correction_id')->firstOrFail();

        $this->assertSame([$this->day(-4), $this->day(-3)], $correction->added_dates);
        $this->assertSame([$this->day(-8), $this->day(-7)], $correction->removed_dates);
        $this->assertSame(['Ana Reyes'], $correction->technicianNames());
    }

    // ------------------------------------------------------------------
    // 7. Half in the past, half still to come
    // ------------------------------------------------------------------

    public function test_a_range_spanning_today_asks_only_about_its_past_days(): void
    {
        $this->actingAsSuperAdmin();

        $jose = $this->leadTechnician('Jose Garcia');
        $project = $this->project([$jose]);

        $this->save(
            $project,
            [$this->range(null, -2, 3)],
            override: true,
            technicianIds: [$jose->technician_id]
        )->assertSessionHasNoErrors();

        $correction = ScheduleCorrection::query()->firstOrFail();

        $this->assertSame([$this->day(-2), $this->day(-1)], $correction->added_dates);
    }

    // ------------------------------------------------------------------
    // 8. Who may be named
    // ------------------------------------------------------------------

    /**
     * A membership is a span, so "on the team" is a question about the dates
     * being attributed rather than about today. Somebody who joined after those
     * days did not work them, and naming them is refused unless the correction
     * says so deliberately.
     */
    public function test_somebody_who_was_not_on_the_project_then_is_refused(): void
    {
        $this->actingAsSuperAdmin();

        $jose = $this->technician('Jose Garcia');
        $project = $this->project([$jose]);

        $newcomer = $this->technician('Ana Reyes');
        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $newcomer->technician_id,
            'joined_at' => Schedule::businessToday()->subDay(),
        ]);

        $this->save(
            $project->refresh(),
            [$this->range(null, -5, -3)],
            override: true,
            technicianIds: [$newcomer->technician_id]
        );

        $this->assertStringContainsString('was not on this project', (string) session('error'));
        $this->assertSame([], $this->rangesOf($project));
    }

    /**
     * And accepted when the Super Admin says so, which widens the membership to
     * cover the days being recorded - otherwise the booking would sit outside
     * the very membership carrying it.
     */
    public function test_a_technician_may_be_added_through_the_correction(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([$this->technician('Jose Garcia')]);
        $outsider = $this->leadTechnician('Ana Reyes');

        $this->save(
            $project,
            [$this->range(null, -5, -3)],
            override: true,
            technicianIds: [$outsider->technician_id],
            addTechnicians: true
        )->assertSessionHasNoErrors();

        $this->assertSame(['Ana Reyes'], $this->crewOn($project, $this->day(-4)));

        $membership = ProjectTechnician::query()
            ->where('project_id', $project->project_id)
            ->where('technician_id', $outsider->technician_id)
            ->firstOrFail();

        $this->assertTrue($membership->coveredOn($this->day(-5)));
        $this->assertTrue($membership->coveredOn($this->day(-3)));
        // The span covers the work and stops there: recording that somebody
        // worked last week is not a decision to put them on the team today.
        $this->assertFalse($membership->coveredOn(Schedule::businessToday()->toDateString()));
    }

    // ------------------------------------------------------------------
    // A crew has a lead, and one lead
    // ------------------------------------------------------------------

    /**
     * The same rule ProjectTeamRules applies to a team being built. Days
     * recorded with nobody of that rank on them read as days nobody was in
     * charge of, and every lead-only action on the project is gated on the
     * account's role rather than on which id was submitted.
     */
    public function test_a_correction_needs_a_lead_technician_among_the_crew(): void
    {
        $this->actingAsSuperAdmin();

        $hand = $this->technician('Ana Reyes');
        $project = $this->project([$hand, $this->leadTechnician('Jose Garcia')]);

        $this->save(
            $project,
            [$this->range(null, -5, -3)],
            override: true,
            technicianIds: [$hand->technician_id]
        );

        $this->assertStringContainsString('Name the Lead Technician', (string) session('error'));
        $this->assertSame([], $this->rangesOf($project));
        $this->assertSame(0, ScheduleCorrection::count());
    }

    /**
     * And one of them. Two lead-role technicians on the same days leave which
     * of the two led it to row order, which is not a thing the record should be
     * able to say.
     */
    public function test_a_correction_refuses_two_lead_technicians(): void
    {
        $this->actingAsSuperAdmin();

        $jose = $this->leadTechnician('Jose Garcia');
        $ana = $this->leadTechnician('Ana Reyes');
        $project = $this->project([$jose, $ana]);

        $this->save(
            $project,
            [$this->range(null, -5, -3)],
            override: true,
            technicianIds: [$jose->technician_id, $ana->technician_id]
        );

        $this->assertStringContainsString('both Lead Technicians', (string) session('error'));
        $this->assertSame([], $this->rangesOf($project));
    }

    public function test_a_lead_may_be_recorded_alongside_the_rest_of_the_crew(): void
    {
        $this->actingAsSuperAdmin();

        $jose = $this->leadTechnician('Jose Garcia');
        $ana = $this->technician('Ana Reyes');
        $project = $this->project([$jose, $ana]);

        $this->save(
            $project,
            [$this->range(null, -5, -3)],
            override: true,
            technicianIds: [$jose->technician_id, $ana->technician_id]
        )->assertSessionHasNoErrors();

        $this->assertSame(['Ana Reyes', 'Jose Garcia'], $this->crewOn($project, $this->day(-4)));
    }

    /**
     * A name that does not belong on those days is the more specific complaint
     * of the two, so it is the one made - being refused for the shape of the
     * crew while a name on it was never there would send somebody looking for
     * the wrong problem.
     */
    public function test_a_name_that_was_not_there_is_reported_before_the_lead_rule(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([$this->leadTechnician('Jose Garcia')]);
        $stranger = $this->technician('Ana Reyes');

        $this->save(
            $project,
            [$this->range(null, -5, -3)],
            override: true,
            technicianIds: [$stranger->technician_id]
        );

        $this->assertStringContainsString('was not on this project', (string) session('error'));
    }

    // ------------------------------------------------------------------
    // 9. Historical work is not a claim on anybody's diary
    // ------------------------------------------------------------------

    /**
     * A day that has gone cannot be double-booked, so a correction is not
     * refused because the technician's name is on another job that week - that
     * is a fact about the past rather than a reason to leave the record wrong.
     */
    public function test_a_past_correction_is_not_blocked_by_other_work_that_week(): void
    {
        $this->actingAsSuperAdmin();

        $shared = $this->leadTechnician('Jose Garcia');

        $elsewhere = $this->project([$shared]);
        $this->book($elsewhere, -5, -3);

        $project = $this->project([$shared]);

        $this->save(
            $project,
            [$this->range(null, -5, -3)],
            override: true,
            technicianIds: [$shared->technician_id]
        )->assertSessionHasNoErrors();

        $this->assertSame([['start' => $this->day(-5), 'end' => $this->day(-3)]], $this->rangesOf($project));
    }

    /**
     * The other half of the same rule: work still to come is checked exactly as
     * it always was, so a correction that reaches forward onto a busy day is
     * still refused.
     */
    public function test_a_correction_reaching_into_the_future_is_still_checked(): void
    {
        $this->actingAsSuperAdmin();

        $shared = $this->technician('Jose Garcia');

        $elsewhere = $this->project([$shared]);
        $this->book($elsewhere, 3, 6);

        $project = $this->project([$shared]);
        $past = $this->book($project, -8, -7);

        $this->save(
            $project,
            [$this->range($past, 4, 5)],
            override: true,
            technicianIds: [$shared->technician_id]
        );

        $this->assertNotNull(session('error'));
        $this->assertSame([['start' => $this->day(-8), 'end' => $this->day(-7)]], $this->rangesOf($project));
    }

    /**
     * And recording last week does not make anybody unavailable for next week.
     */
    public function test_recorded_history_does_not_block_future_scheduling(): void
    {
        $this->actingAsSuperAdmin();

        $shared = $this->leadTechnician('Jose Garcia');
        $recorded = $this->project([$shared]);

        $this->save(
            $recorded,
            [$this->range(null, -5, -3)],
            override: true,
            technicianIds: [$shared->technician_id]
        )->assertSessionHasNoErrors();

        $other = $this->project([$shared]);

        $this->save($other, [$this->range(null, 2, 4)])->assertSessionHasNoErrors();

        $this->assertSame([['start' => $this->day(2), 'end' => $this->day(4)]], $this->rangesOf($other));
    }

    // ------------------------------------------------------------------
    // 10. The audit trail
    // ------------------------------------------------------------------

    public function test_a_correction_records_who_what_and_when(): void
    {
        $superAdmin = $this->actingAsSuperAdmin();

        $jose = $this->leadTechnician('Jose Garcia');
        $project = $this->project([$jose]);
        $existing = $this->book($project, -6, -4);

        $this->save(
            $project,
            [$this->range($existing, -8, -4)],
            override: true,
            technicianIds: [$jose->technician_id]
        )->assertSessionHasNoErrors();

        $correction = ScheduleCorrection::query()->firstOrFail();

        $this->assertSame((int) $superAdmin->id, (int) $correction->actor_id);
        $this->assertSame('super_admin', $correction->actor_role);
        $this->assertSame((int) $project->project_id, (int) $correction->project_id);

        // The range as it was, and as it now stands.
        $this->assertStringContainsString(
            CarbonImmutable::parse($this->day(-6))->format('M j'),
            (string) $correction->original_range
        );
        $this->assertStringContainsString(
            CarbonImmutable::parse($this->day(-8))->format('M j'),
            (string) $correction->new_range
        );

        $this->assertSame([$this->day(-8), $this->day(-7)], $correction->added_dates);
        $this->assertSame(
            [['technician_id' => (int) $jose->technician_id, 'name' => 'Jose Garcia']],
            $correction->technicians
        );
        $this->assertNotNull($correction->created_at);
    }

    public function test_a_correction_also_names_the_days_in_the_activity_trail(): void
    {
        $this->actingAsSuperAdmin();

        $jose = $this->leadTechnician('Jose Garcia');
        $project = $this->project([$jose]);

        $this->save(
            $project,
            [$this->range(null, -5, -4)],
            override: true,
            technicianIds: [$jose->technician_id]
        )->assertSessionHasNoErrors();

        $this->assertTrue(
            ActivityLog::query()
                ->where('action', ActivityLog::PROJECT_RESCHEDULED)
                ->get()
                ->contains(fn (ActivityLog $log): bool => str_contains((string) $log->description, 'Jose Garcia')
                    && str_contains((string) $log->description, 'Recorded'))
        );
    }

    /**
     * Nothing is written when the save fails: the audit row and the schedule it
     * describes are one act, and a correction that did not happen must leave no
     * trace claiming it did.
     */
    public function test_a_refused_correction_records_nothing(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([$this->technician('Jose Garcia')]);

        $this->save($project, [$this->range(null, -5, -3)], override: true);

        $this->assertSame(0, ScheduleCorrection::count());
    }

    // ------------------------------------------------------------------
    // The question the editor asks before it saves
    // ------------------------------------------------------------------

    public function test_the_editor_is_told_which_days_need_a_name(): void
    {
        $this->actingAsSuperAdmin();

        $jose = $this->leadTechnician('Jose Garcia');
        $project = $this->project([$jose]);
        $existing = $this->book($project, -6, -4);

        $response = $this->postJson(
            route('super-admin.schedules.historical-check', $project->project_id),
            ['ranges' => [$this->range($existing, -8, -4)]]
        );

        $response->assertOk()
            ->assertJsonPath('required', true)
            ->assertJsonPath('dates', [$this->day(-8), $this->day(-7)]);

        $this->assertSame(
            ['Jose Garcia'],
            array_column($response->json('members'), 'name')
        );

        // The search box matches on name, code, address and role, and the chips
        // mark the lead - so every one of those travels with the name.
        $member = $response->json('members.0');

        $this->assertTrue($member['is_lead']);
        $this->assertSame('Lead Technician', $member['role_label']);
        $this->assertSame('jose.garcia@example.test', $member['email']);
        $this->assertArrayHasKey('code', $member);
    }

    /**
     * The crew the project holds today is the answer most corrections have, so
     * the editor fills it in rather than making it be typed. It is a suggestion
     * and nothing more - the chips can be taken off, and the save records what
     * is left.
     */
    public function test_the_current_crew_is_suggested_for_the_added_dates(): void
    {
        $this->actingAsSuperAdmin();

        $jose = $this->leadTechnician('Jose Garcia');
        $ana = $this->technician('Ana Reyes');
        $project = $this->project([$jose, $ana]);
        $existing = $this->book($project, -6, -4);

        $response = $this->postJson(
            route('super-admin.schedules.historical-check', $project->project_id),
            ['ranges' => [$this->range($existing, -8, -4)]]
        );

        $response->assertOk()->assertJsonPath('required', true);

        $suggested = array_column(
            array_filter($response->json('members'), fn (array $member): bool => $member['suggested']),
            'name'
        );

        $this->assertSame(['Ana Reyes', 'Jose Garcia'], $suggested);

        // Nobody outside the project is ever filled in: choosing one of those
        // writes a membership as well as a booking.
        foreach ($response->json('others') as $candidate) {
            $this->assertFalse($candidate['suggested']);
        }
    }

    /**
     * Somebody who covers the dates but has since been taken off the project is
     * still offered - they may well have worked those days - but is not filled
     * in. Doing so would have the editor assert something about a person who is
     * no longer on the job.
     */
    public function test_a_departed_member_is_offered_but_not_suggested(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([$this->leadTechnician('Jose Garcia')]);

        $departed = $this->technician('Ana Reyes');
        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $departed->technician_id,
            'joined_at' => Schedule::businessToday()->subDays(90),
            // Off the project since yesterday, so the span still covers the
            // days being recorded.
            'removed_at' => Schedule::businessToday()->subDay(),
        ]);

        $response = $this->postJson(
            route('super-admin.schedules.historical-check', $project->project_id),
            ['ranges' => [$this->range(null, -5, -3)]]
        );

        $response->assertOk()->assertJsonPath('required', true);

        $members = collect($response->json('members'))->keyBy('name');

        $this->assertTrue($members->has('Ana Reyes'));
        $this->assertFalse($members['Ana Reyes']['suggested']);
        $this->assertStringContainsString('removed', $members['Ana Reyes']['note']);
        $this->assertTrue($members['Jose Garcia']['suggested']);
    }

    public function test_the_editor_is_told_when_nothing_needs_a_name(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([$this->technician('Jose Garcia')]);
        $existing = $this->book($project, -6, -4);

        $this->postJson(
            route('super-admin.schedules.historical-check', $project->project_id),
            ['ranges' => [$this->range($existing, -6, -4)]]
        )->assertOk()->assertJsonPath('required', false);
    }

    /**
     * The step itself is drawn only for the reader who can use it, along with
     * the address it asks its question at. An Admin's editor has neither, so
     * their save behaves exactly as it did before any of this existed.
     */
    public function test_the_editor_draws_the_historical_step_for_a_super_admin_only(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([$this->technician('Jose Garcia')]);
        $this->book($project, 2, 4);

        $this->get(route('super-admin.schedules.index'))
            ->assertOk()
            ->assertSee('data-historical-step', false)
            ->assertSee('data-may-correct-history="1"', false)
            ->assertSee(
                route('super-admin.schedules.historical-check', $project->project_id),
                false
            )
            ->assertSee('Who worked these dates?', false)
            // Searched rather than listed: the box, the dropdown it fills, and
            // the chips the picks collect in.
            ->assertSee('data-historical-input', false)
            ->assertSee('data-historical-results', false)
            ->assertSee('data-historical-chosen', false);

        $this->actingAs($this->account('admin', 'plain.admin@example.test'));

        $this->get(route('super-admin.schedules.index'))
            ->assertOk()
            ->assertDontSee('data-historical-step', false)
            ->assertSee('data-may-correct-history="0"', false);
    }

    public function test_an_admin_cannot_reach_the_historical_check(): void
    {
        $this->actingAs($this->account('admin', 'admin@example.test'));

        $project = $this->project([$this->technician('Jose Garcia')]);

        $this->postJson(
            route('super-admin.schedules.historical-check', $project->project_id),
            ['ranges' => []]
        )->assertForbidden();
    }

    /**
     * Somebody who joined half way through the days being attributed was not on
     * the project for the rest of them, so they are offered as a deliberate
     * addition rather than as an ordinary member.
     */
    public function test_a_part_time_member_is_offered_as_a_deliberate_addition(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();

        $latecomer = $this->technician('Ana Reyes');
        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $latecomer->technician_id,
            'joined_at' => Schedule::businessToday()->subDays(4),
        ]);

        $response = $this->postJson(
            route('super-admin.schedules.historical-check', $project->project_id),
            ['ranges' => [$this->range(null, -6, -3)]]
        );

        $response->assertOk()->assertJsonPath('required', true);

        $this->assertSame([], $response->json('members'));
        $this->assertSame(['Ana Reyes'], array_column($response->json('others'), 'name'));
    }
}
