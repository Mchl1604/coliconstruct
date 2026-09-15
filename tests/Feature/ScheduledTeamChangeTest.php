<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\SystemReportService;
use App\Services\TaskAssignmentRules;
use App\Services\TechnicianAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Taking technicians off a project without rewriting anything they have done.
 *
 * Two places change a team, and they mean different things:
 *
 *   Project Details - Assigned Team is the master control. It changes the team
 *   as it stands today, and whoever it takes off comes off completely.
 *
 *   Technicians page - Remove Schedule takes one technician off a project for
 *   some days (off, then back the day after) or from a day onward. Either may
 *   start today or later.
 *
 * Every date here is on the office's calendar (Schedule::businessToday()),
 * which is the clock a removal is measured against.
 */
class ScheduledTeamChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function day(int $offset): string
    {
        return Schedule::businessToday()->addDays($offset)->toDateString();
    }

    private function label(int $offset): string
    {
        return CarbonImmutable::parse($this->day($offset))->format('M j, Y');
    }

    private function technician(string $name, string $role = 'technician'): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => $role])->save();

        return Technician::create(['account_id' => $user->id, 'role' => $role]);
    }

    /**
     * An ongoing project booked from a week ago to a month from now, with a
     * lead and a technician who have been on it since before it began.
     *
     * @return array{0: Project, 1: Technician, 2: Technician, 3: Schedule}
     */
    private function runningProject(): array
    {
        $project = Project::create([
            'name' => 'Warehouse Fit-out',
            'reference_no' => 'PRJ-'.strtoupper(substr(md5(uniqid()), 0, 8)),
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
        ]);

        $this->finalizePhases($project);

        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $this->day(-7).' 00:00:00',
            'end_datetime' => $this->day(30).' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        $lead = $this->technician('John Lead', 'lead_technician');
        $tech = $this->technician('Ana Mendoza');

        foreach ([$lead, $tech] as $member) {
            $span = ProjectTechnician::create([
                'project_id' => $project->project_id,
                'technician_id' => $member->technician_id,
                'team_role' => $member->account->role,
                'joined_at' => $this->day(-10).' 08:00:00',
            ]);

            ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $span->project_technician_id,
            ]);
        }

        return [$project, $lead, $tech, $schedule];
    }

    /**
     * Technicians page: off from $from onward.
     */
    private function removeFrom(Project $project, Technician $technician, string $from, ?Technician $replacement = null, array $resolutions = [])
    {
        return $this->deleteJson(route('super-admin.technicians.projects.destroy', [$technician->technician_id, $project->project_id]), [
            'mode' => 'from',
            'from' => $from,
            'replacement_lead_id' => $replacement?->technician_id,
            'task_resolutions' => $resolutions,
        ]);
    }

    /**
     * Technicians page: off from $from to $until, back the day after.
     */
    private function daysOff(Project $project, Technician $technician, string $from, string $until, ?Technician $standIn = null, array $resolutions = [])
    {
        return $this->deleteJson(route('super-admin.technicians.projects.destroy', [$technician->technician_id, $project->project_id]), [
            'mode' => 'days',
            'from' => $from,
            'until' => $until,
            'replacement_lead_id' => $standIn?->technician_id,
            'task_resolutions' => $resolutions,
        ]);
    }

    /**
     * Project Details: the master control.
     */
    private function saveTeam(Project $project, Technician $lead, array $technicians, array $resolutions = [])
    {
        return $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => array_map(fn (Technician $technician): int => (int) $technician->technician_id, $technicians),
            'task_resolutions' => $resolutions,
        ]);
    }

    private function task(Project $project, Technician $holder, string $start, string $due, string $title = 'Fit the ducting'): Task
    {
        return Task::create([
            'project_id' => $project->project_id,
            'phase_id' => $this->defaultPhaseId($project),
            'technician_id' => $holder->technician_id,
            'task_title' => $title,
            'task_description' => 'Work',
            'status' => 'pending',
            'start_date' => $start,
            'due_date' => $due,
        ]);
    }

    /**
     * @return Collection<int, ProjectTechnician>
     */
    private function spansOf(Project $project, Technician $technician)
    {
        return ProjectTechnician::query()
            ->where('project_id', $project->project_id)
            ->where('technician_id', $technician->technician_id)
            ->orderBy('joined_at')
            ->get();
    }

    private function crewOn(Project $project, int $offset): array
    {
        return $project->fresh()->crewOn($this->day($offset))->pluck('technician_id')->map(fn ($id): int => (int) $id)->all();
    }

    // ------------------------------------------------------------------
    // Project Details: the master control
    // ------------------------------------------------------------------

    public function test_removing_in_assigned_team_takes_the_technician_off_completely_today(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        // Days off first, so they have a return still to come.
        $this->daysOff($project, $tech, $this->day(5), $this->day(6))->assertOk();
        $this->assertCount(2, $this->spansOf($project, $tech));

        $this->saveTeam($project, $lead, [])->assertSessionHas('success');

        $spans = $this->spansOf($project, $tech);

        // The return is gone, and the span they had closes today.
        $this->assertCount(1, $spans);
        $this->assertSame($this->day(0), $spans->first()->endDate());
        $this->assertNotContains($tech->technician_id, $this->crewOn($project, 10));
        $this->assertContains($tech->technician_id, $this->crewOn($project, -3));
    }

    public function test_the_master_control_ignores_any_date_it_is_sent(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [],
            'effective_date' => $this->day(10),
        ])->assertSessionHas('success');

        $this->assertSame($this->day(0), $this->spansOf($project, $tech)->first()->endDate());
    }

    // ------------------------------------------------------------------
    // Technicians page: from a date onward
    // ------------------------------------------------------------------

    public function test_a_technician_removed_from_a_later_day_stays_on_the_team_until_the_day_before(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $this->removeFrom($project, $tech, $this->day(10))->assertOk();

        $span = $this->spansOf($project, $tech)->first();

        $this->assertSame($this->day(10), $span->endDate());
        $this->assertTrue($span->isLeaving());

        $this->assertContains($tech->technician_id, $project->fresh()->projectTechnicians->pluck('technician_id')->all());
        $this->assertContains($tech->technician_id, $this->crewOn($project, 9));
        $this->assertNotContains($tech->technician_id, $this->crewOn($project, 10));
    }

    public function test_a_later_removal_frees_the_technician_from_that_day_on(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $this->removeFrom($project, $tech, $this->day(10))->assertOk();

        $busy = app(TechnicianAvailabilityService::class)->unavailableDatesByTechnician(
            [$tech->technician_id],
            [['start' => CarbonImmutable::parse($this->day(5)), 'end' => CarbonImmutable::parse($this->day(15))]]
        )[$tech->technician_id] ?? [];

        $this->assertArrayHasKey($this->day(9), $busy);
        $this->assertArrayNotHasKey($this->day(10), $busy);
    }

    public function test_a_later_removal_releases_only_the_bookings_that_start_after_it(): void
    {
        [$project, $lead, $tech, $running] = $this->runningProject();

        $later = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $this->day(40).' 00:00:00',
            'end_datetime' => $this->day(45).' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        ScheduleTechnician::create([
            'schedule_id' => $later->schedule_id,
            'project_technician_id' => $this->spansOf($project, $tech)->first()->project_technician_id,
        ]);

        $this->removeFrom($project, $tech, $this->day(10))->assertOk();

        $links = ScheduleTechnician::query()
            ->where('project_technician_id', $this->spansOf($project, $tech)->first()->project_technician_id)
            ->pluck('schedule_id')
            ->all();

        $this->assertContains($running->schedule_id, $links);
        $this->assertNotContains($later->schedule_id, $links);
    }

    public function test_a_removal_cannot_take_effect_on_a_day_that_has_passed(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $this->removeFrom($project, $tech, $this->day(-1))
            ->assertStatus(422)
            ->assertJsonPath('error', 'A team change cannot take effect on a day that has already passed.');
    }

    public function test_a_lead_removed_from_a_later_day_hands_over_on_that_day(): void
    {
        [$project, $john, $tech] = $this->runningProject();
        $mary = $this->technician('Mary Santos', 'lead_technician');

        $this->removeFrom($project, $john, $this->day(10))
            ->assertStatus(422)
            ->assertJsonPath('error', 'John Lead leads this project. Choose a replacement first.');

        $this->removeFrom($project, $john, $this->day(10), $mary)->assertOk();

        $project = $project->fresh();

        $this->assertSame((int) $john->technician_id, (int) $project->leadAssignment()->technician_id);
        $this->assertSame((int) $john->technician_id, (int) $project->leadOn($this->day(9))->technician_id);
        $this->assertSame((int) $mary->technician_id, (int) $project->leadOn($this->day(10))->technician_id);
        $this->assertTrue($this->spansOf($project, $mary)->first()->isUpcoming());
    }

    public function test_a_scheduled_lead_can_view_the_project_but_not_run_it_before_their_first_day(): void
    {
        [$project, $john] = $this->runningProject();
        $mary = $this->technician('Mary Santos', 'lead_technician');

        $this->removeFrom($project, $john, $this->day(10), $mary)->assertOk();

        $policy = app(ProjectPolicy::class);

        $this->assertTrue($policy->viewAssigned($mary->account, $project->fresh()));
        $this->assertFalse($policy->manageTasks($mary->account, $project->fresh()));
        $this->assertTrue($policy->manageTasks($john->account, $project->fresh()));
    }

    // ------------------------------------------------------------------
    // Technicians page: some days off
    // ------------------------------------------------------------------

    public function test_days_off_take_the_technician_off_and_bring_them_back_the_day_after(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $this->daysOff($project, $tech, $this->day(5), $this->day(6))
            ->assertOk()
            ->assertJsonPath('message', sprintf('Ana Mendoza is off Warehouse Fit-out from %s to %s.', $this->label(5), $this->label(6)));

        $this->assertContains($tech->technician_id, $this->crewOn($project, 4));
        $this->assertNotContains($tech->technician_id, $this->crewOn($project, 5));
        $this->assertNotContains($tech->technician_id, $this->crewOn($project, 6));
        $this->assertContains($tech->technician_id, $this->crewOn($project, 7));
        $this->assertContains($tech->technician_id, $this->crewOn($project, 30));

        // Two spans, split around the days.
        $spans = $this->spansOf($project, $tech);
        $this->assertSame([$this->day(5), null], $spans->map->endDate()->all());
        $this->assertSame($this->day(7), $spans->last()->startDate());
    }

    public function test_one_day_off_today_is_allowed(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $this->daysOff($project, $tech, $this->day(0), $this->day(0))->assertOk();

        $this->assertNotContains($tech->technician_id, $this->crewOn($project, 0));
        $this->assertContains($tech->technician_id, $this->crewOn($project, 1));
    }

    public function test_a_lead_given_days_off_needs_a_stand_in_for_exactly_those_days(): void
    {
        [$project, $john, $tech] = $this->runningProject();
        $mary = $this->technician('Mary Santos', 'lead_technician');

        $this->daysOff($project, $john, $this->day(5), $this->day(6))
            ->assertStatus(422)
            ->assertJsonPath('error', 'John Lead leads this project. Choose a lead technician to stand in for those days.');

        $this->daysOff($project, $john, $this->day(5), $this->day(6), $mary)->assertOk();

        $project = $project->fresh();

        $this->assertSame((int) $john->technician_id, (int) $project->leadOn($this->day(4))->technician_id);
        $this->assertSame((int) $mary->technician_id, (int) $project->leadOn($this->day(5))->technician_id);
        $this->assertSame((int) $mary->technician_id, (int) $project->leadOn($this->day(6))->technician_id);
        $this->assertSame((int) $john->technician_id, (int) $project->leadOn($this->day(7))->technician_id);
    }

    public function test_days_off_the_technician_is_not_assigned_for_are_refused(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $this->removeFrom($project, $tech, $this->day(10))->assertOk();

        $this->daysOff($project, $tech, $this->day(8), $this->day(12))
            ->assertStatus(422)
            ->assertJsonPath('error', sprintf('Ana Mendoza is not assigned to this project for every day from %s to %s.', $this->label(8), $this->label(12)));
    }

    // ------------------------------------------------------------------
    // Tasks the change strands
    // ------------------------------------------------------------------

    public function test_work_on_removed_days_needs_a_decision_and_other_work_does_not(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $before = $this->task($project, $tech, $this->day(2), $this->day(4), 'Before');
        $during = $this->task($project, $tech, $this->day(5), $this->day(6), 'During');
        $spanning = $this->task($project, $tech, $this->day(4), $this->day(8), 'Spanning');
        $after = $this->task($project, $tech, $this->day(8), $this->day(9), 'After');

        $response = $this->daysOff($project, $tech, $this->day(5), $this->day(6))->assertStatus(422)->assertJsonPath('needs_decisions', true);

        $this->assertEqualsCanonicalizing([$during->task_id, $spanning->task_id], array_column($response->json('conflicts'), 'task_id'));
        $this->assertCount(1, $this->spansOf($project, $tech));

        $this->daysOff($project, $tech, $this->day(5), $this->day(6), null, [
            $during->task_id => (string) $lead->technician_id,
            $spanning->task_id => 'keep',
        ])->assertOk();

        $this->assertSame($tech->technician_id, $before->fresh()->technician_id);
        $this->assertSame($tech->technician_id, $after->fresh()->technician_id);
        $this->assertSame($lead->technician_id, $during->fresh()->technician_id);

        // Kept - and flagged, until somebody sorts it out.
        $this->assertSame($tech->technician_id, $spanning->fresh()->technician_id);
        $this->assertTrue(Task::query()->needsAssignment()->withAssignmentGap(Task::GAP_OFF_TEAM)->whereKey($spanning->task_id)->exists());
        $this->assertFalse(Task::query()->needsAssignment()->whereKey($before->task_id)->exists());
    }

    public function test_keeping_a_stranded_task_is_not_offered_for_a_change_taking_effect_today(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $task = $this->task($project, $tech, $this->day(2), $this->day(5));

        $this->saveTeam($project, $lead, [], [$task->task_id => 'keep'])->assertSessionHas('error');

        $this->assertSame($tech->technician_id, $task->fresh()->technician_id);
    }

    public function test_the_master_control_asks_about_tasks_before_it_removes_anybody(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $task = $this->task($project, $tech, $this->day(2), $this->day(5));

        $this->postJson(route('super-admin.projects.team.preview', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [],
        ])->assertOk()->assertJsonPath('conflicts.0.task_id', $task->task_id);

        $this->saveTeam($project, $lead, [], [$task->task_id => 'unassign'])->assertSessionHas('success');

        $this->assertNull($task->fresh()->technician_id);
    }

    // ------------------------------------------------------------------
    // Giving a task to somebody
    // ------------------------------------------------------------------

    public function test_a_task_must_sit_inside_one_period_of_its_technician(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $this->removeFrom($project, $tech, $this->day(10))->assertOk();

        $store = fn (string $start, string $due) => $this->post(route('super-admin.task.store', $project->project_id), [
            'task_title' => 'Task '.$start.$due,
            'task_description' => 'Work',
            'phase_id' => $this->defaultPhaseId($project),
            'technician_id' => $tech->technician_id,
            'start_date' => $start,
            'due_date' => $due,
        ]);

        $store($this->day(6), $this->day(9))->assertSessionHasNoErrors();

        $store($this->day(8), $this->day(11))->assertSessionHasErrors('technician_id');
        $this->assertStringContainsString('assigned to this project until', session('errors')->first('technician_id'));
    }

    public function test_a_task_may_not_run_across_a_technicians_days_off(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $this->daysOff($project, $tech, $this->day(10), $this->day(19))->assertOk();

        $this->post(route('super-admin.task.store', $project->project_id), [
            'task_title' => 'Across the days off',
            'task_description' => 'Work',
            'phase_id' => $this->defaultPhaseId($project),
            'technician_id' => $tech->technician_id,
            'start_date' => $this->day(8),
            'due_date' => $this->day(22),
        ])->assertSessionHasErrors('technician_id');

        $this->assertStringContainsString('which this task runs across', session('errors')->first('technician_id'));
    }

    public function test_a_task_whose_holder_left_stays_editable_while_nothing_about_it_changes(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $task = $this->task($project, $tech, $this->day(2), $this->day(12));

        $this->spansOf($project, $tech)->first()->update(['removed_at' => $this->day(10)]);

        $this->put(route('super-admin.tasks.update', $task->task_id), [
            'task_title' => 'Renamed',
            'task_description' => 'Work',
            'phase_id' => $task->phase_id,
            'technician_id' => $tech->technician_id,
            'start_date' => $this->day(2),
            'due_date' => $this->day(12),
        ])->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $task->fresh()->task_title);
    }

    // ------------------------------------------------------------------
    // Calling it off
    // ------------------------------------------------------------------

    private function cancel(Project $project, ProjectTechnician $span)
    {
        return $this->delete(route('super-admin.projects.team.scheduled.cancel', [
            'id' => $project->project_id,
            'membership' => $span->project_technician_id,
        ]));
    }

    public function test_cancelling_a_scheduled_removal_keeps_the_technician_on(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $this->removeFrom($project, $tech, $this->day(10))->assertOk();

        $this->cancel($project, $this->spansOf($project, $tech)->first())->assertSessionHas('success');

        $this->assertNull($this->spansOf($project, $tech)->first()->removed_at);
    }

    public function test_cancelling_days_off_folds_the_span_back_together(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $this->daysOff($project, $tech, $this->day(5), $this->day(6))->assertOk();

        $this->cancel($project, $this->spansOf($project, $tech)->first())
            ->assertSessionHas('success', 'Scheduled change cancelled: Ana Mendoza is no longer taking those days off.');

        $spans = $this->spansOf($project, $tech);
        $this->assertCount(1, $spans);
        $this->assertNull($spans->first()->removed_at);
        $this->assertContains($tech->technician_id, $this->crewOn($project, 5));
    }

    public function test_a_return_that_is_itself_removed_later_shows_both_and_either_can_be_cancelled(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $this->daysOff($project, $tech, $this->day(5), $this->day(6))->assertOk();
        $this->removeFrom($project, $tech, $this->day(12))->assertOk();

        $return = $this->spansOf($project, $tech)->last();

        $this->assertSame($this->day(12), $return->endDate());

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSeeInOrder(['Returns', $this->label(7), 'Cancel return'])
            ->assertSeeInOrder(['Leaving', $this->label(12), 'Cancel removal'])
            ->assertSee('name="part" value="removal"', false);

        // Just the removal: they still come back, and stay on.
        $this->delete(route('super-admin.projects.team.scheduled.cancel', [
            'id' => $project->project_id,
            'membership' => $return->project_technician_id,
        ]), ['part' => 'removal'])->assertSessionHas('success', 'Scheduled change cancelled: Ana Mendoza will stay on the team.');

        $spans = $this->spansOf($project, $tech);
        $this->assertCount(2, $spans);
        $this->assertSame($this->day(7), $spans->last()->startDate());
        $this->assertNull($spans->last()->removed_at);
    }

    public function test_task_refusals_name_the_days_off_or_the_end(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $this->daysOff($project, $tech, $this->day(5), $this->day(6))->assertOk();

        $rules = app(TaskAssignmentRules::class);

        $this->assertSame(
            sprintf('Ana Mendoza is off this project from %s to %s, and this task starts %s.', $this->label(5), $this->label(6), $this->label(5)),
            $rules->periodRefusal($tech, (int) $project->project_id, $this->day(5), $this->day(8))
        );

        $this->assertSame(
            sprintf('Ana Mendoza is off this project from %s to %s, and this task is due %s.', $this->label(5), $this->label(6), $this->label(6)),
            $rules->periodRefusal($tech, (int) $project->project_id, $this->day(3), $this->day(6))
        );

        $this->removeFrom($project, $tech, $this->day(10))->assertOk();

        $this->assertSame(
            sprintf('Ana Mendoza is assigned to this project until %s, and this task starts %s.', $this->label(9), $this->label(12)),
            $rules->periodRefusal($tech, (int) $project->project_id, $this->day(12), $this->day(14))
        );
    }

    public function test_cancelling_a_leads_days_off_cancels_the_stand_in_too(): void
    {
        [$project, $john] = $this->runningProject();
        $mary = $this->technician('Mary Santos', 'lead_technician');

        $this->daysOff($project, $john, $this->day(5), $this->day(6), $mary)->assertOk();

        // From the stand-in's side.
        $this->cancel($project, $this->spansOf($project, $mary)->first())->assertSessionHas('success');

        $this->assertCount(0, $this->spansOf($project, $mary));
        $this->assertCount(1, $this->spansOf($project, $john));
        $this->assertNull($this->spansOf($project, $john)->first()->removed_at);
    }

    public function test_cancelling_either_half_of_a_lead_handover_cancels_both(): void
    {
        [$project, $john] = $this->runningProject();
        $mary = $this->technician('Mary Santos', 'lead_technician');

        $this->removeFrom($project, $john, $this->day(10), $mary)->assertOk();

        $this->cancel($project, $this->spansOf($project, $mary)->first())->assertSessionHas('success');

        $this->assertCount(0, $this->spansOf($project, $mary));
        $this->assertNull($this->spansOf($project, $john)->first()->removed_at);
    }

    public function test_cancelling_a_project_calls_off_its_scheduled_team_changes(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $this->daysOff($project, $tech, $this->day(5), $this->day(6))->assertOk();

        $this->post(route('super-admin.projects.cancel', $project->project_id), [
            'cancellation_date' => $this->day(0),
            'cancellation_reason' => 'Client withdrew',
        ]);

        $this->assertSame('cancelled', $project->fresh()->status);

        $spans = $this->spansOf($project, $tech);
        $this->assertCount(1, $spans);
        $this->assertNull($spans->first()->removed_at);
    }

    // ------------------------------------------------------------------
    // The pages
    // ------------------------------------------------------------------

    public function test_project_details_shows_what_is_scheduled_with_a_way_to_cancel_it(): void
    {
        [$project, $john, $tech] = $this->runningProject();
        $mary = $this->technician('Mary Santos', 'lead_technician');

        $this->daysOff($project, $tech, $this->day(5), $this->day(6))->assertOk();
        $this->daysOff($project, $john, $this->day(10), $this->day(11), $mary)->assertOk();

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            // In each technician's schedule dialog, one change to a line.
            ->assertSee('data-team-schedule-modal', false)
            ->assertSeeInOrder(['Days off', $this->label(5).' - '.$this->label(6), 'Cancel days off'])
            ->assertSeeInOrder(['Covers as lead', $this->label(10).' - '.$this->label(11), 'Cancel cover'])
            ->assertDontSee('data-team-effective-date', false)
            ->assertSee('data-assignment-periods', false);
    }

    public function test_the_task_pages_carry_each_technicians_periods(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $this->task($project, $tech, $this->day(2), $this->day(5));

        $this->get(route('super-admin.tasks.index'))
            ->assertOk()
            ->assertSee('data-assignment-periods', false);

        $this->getJson(route('super-admin.projects.task-form-data', ['id' => $project->project_id]))
            ->assertOk()
            ->assertJsonPath('technicians.0.periods.0.end', null);
    }

    public function test_a_stand_in_lead_is_told_on_the_project_page(): void
    {
        [$project, $john] = $this->runningProject();
        $mary = $this->technician('Mary Santos', 'lead_technician');

        $this->daysOff($project, $john, $this->day(10), $this->day(11), $mary)->assertOk();

        $mary->account->forceFill([
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ] + $this->acceptedTerms())->save();

        $this->actingAs($mary->account)
            ->get(route('technician.projects.show', $project->project_id))
            ->assertOk()
            // Said in Your Schedule, not in a banner over the page.
            ->assertDontSee('You lead this project from')
            ->assertSeeInOrder(['Your Schedule', $this->label(10).' - '.$this->label(11), 'Covers as lead', 'Project Schedule'])
            // The team card: a view-only schedule dialog per technician, with
            // John's days off in his and nothing to cancel.
            ->assertSee('data-team-schedule-modal', false)
            ->assertSeeInOrder(['John Lead', 'Days off', $this->label(10).' - '.$this->label(11)])
            ->assertDontSee('data-team-cancel-form', false);
    }

    public function test_a_technician_sees_their_own_schedule_apart_from_the_projects(): void
    {
        [$project, , $ana] = $this->runningProject();

        $ana->account->forceFill([
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ] + $this->acceptedTerms())->save();

        $this->daysOff($project, $ana, $this->day(5), $this->day(6))->assertOk();

        $project = $project->fresh();

        $this->assertSame(
            [$this->label(-7).' - '.$this->label(4), $this->label(7).' - '.$this->label(30)],
            array_column($project->bookedDaysFor((int) $ana->technician_id), 'label')
        );
        $this->assertSame(
            ['Days off', 'Returns'],
            array_column($project->scheduledChangesFor((int) $ana->technician_id), 'title')
        );

        $this->actingAs($ana->account)
            ->get(route('technician.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('data-my-schedule', false)
            ->assertSeeInOrder([
                'Your Schedule',
                $this->label(-7).' - '.$this->label(4),
                $this->label(7).' - '.$this->label(30),
                'Days off',
                $this->label(5).' - '.$this->label(6),
                'Project Schedule',
            ]);

        $this->actingAs($ana->account)
            ->getJson(route('technician.projects.details', $project->project_id).'?mine_only=1')
            ->assertOk()
            ->assertJsonPath('my_schedule.days.1.label', $this->label(7).' - '.$this->label(30))
            ->assertJsonPath('my_schedule.changes.0.title', 'Days off')
            ->assertJsonPath('my_schedule.changes.1.when', $this->label(7));
    }

    public function test_a_technician_on_days_off_keeps_the_project_on_their_calendar_in_its_colours(): void
    {
        [$project, , $ana] = $this->runningProject();

        $ana->account->forceFill([
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ] + $this->acceptedTerms())->save();

        $this->daysOff($project, $ana, $this->day(0), $this->day(2))->assertOk();

        $events = collect($this->actingAs($ana->account)
            ->get(route('technician.schedule'))
            ->assertOk()
            ->viewData('events'));

        $this->assertCount(2, $events);
        $this->assertTrue($events->every(fn (array $event): bool => $event['extendedProps']['isFormer'] === false
            && ! isset($event['classNames'])));

        $this->actingAs($ana->account)
            ->getJson(route('technician.projects.details', $project->project_id))
            ->assertOk();
    }

    public function test_the_technician_panel_offers_stand_ins_for_the_days_asked_about(): void
    {
        [$project, $john] = $this->runningProject();
        $this->technician('Mary Santos', 'lead_technician');

        $this->getJson(route('super-admin.technicians.assignment', [$john->technician_id, $project->project_id]).'?mode=days&from='.$this->day(5).'&until='.$this->day(6))
            ->assertOk()
            ->assertJsonPath('is_lead', true)
            ->assertJsonPath('mode', 'days')
            ->assertJsonPath('replacement_leads.0.name', 'Mary Santos');
    }

    // ------------------------------------------------------------------
    // What the record says
    // ------------------------------------------------------------------

    public function test_the_technician_calendar_draws_each_stretch_around_days_off(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $this->daysOff($project, $tech, $this->day(5), $this->day(6))->assertOk();

        $events = collect($this->getJson(route('super-admin.technicians.calendar', $tech->technician_id))->assertOk()->json('events'))
            ->where('extendedProps.projectId', $project->project_id)
            ->map(fn (array $event): array => [$event['start'], $event['end']])
            ->sort()
            ->values()
            ->all();

        // Exclusive ends, FullCalendar's way.
        $this->assertSame([[$this->day(-7), $this->day(5)], [$this->day(7), $this->day(31)]], $events);
    }

    public function test_a_lead_cannot_be_replaced_today_over_a_handover_already_booked(): void
    {
        [$project, $lead] = $this->runningProject();
        $booked = $this->technician('Jose Booked', 'lead_technician');
        $incoming = $this->technician('Juan Incoming', 'lead_technician');

        $this->removeFrom($project, $lead, $this->day(10), $booked)->assertOk();

        $listing = $this->getJson(route('super-admin.technicians.assignable', $incoming->technician_id))->assertOk();

        $this->assertNotContains($project->project_id, collect($listing->json('projects'))->pluck('project_id')->all());
        $this->assertStringContainsString(
            'would both lead this project',
            (string) collect($listing->json('blocked'))->firstWhere('project_id', $project->project_id)['reason']
        );

        $this->postJson(route('super-admin.technicians.projects.store', $incoming->technician_id), [
            'project_ids' => [$project->project_id],
            'lead_replacements' => [[
                'project_id' => $project->project_id,
                'replacing_technician_id' => $lead->technician_id,
            ]],
        ])->assertStatus(422);

        $this->assertFalse(ProjectTechnician::query()
            ->where('project_id', $project->project_id)
            ->where('technician_id', $incoming->technician_id)
            ->exists());
    }

    public function test_cancelling_a_project_keeps_a_technician_whose_days_off_are_under_way(): void
    {
        [$project, $lead, $ana] = $this->runningProject();

        $this->daysOff($project, $ana, $this->day(0), $this->day(2))->assertOk();

        $this->post(route('super-admin.projects.cancel', $project->project_id), [
            'cancellation_date' => $this->day(0),
            'cancellation_reason' => 'Client withdrew',
        ]);

        $this->assertSame('cancelled', $project->fresh()->status);

        $spans = $this->spansOf($project, $ana);

        // The days already off stay on the record; the return is brought
        // forward to the closing, and nothing is left to come.
        $this->assertCount(2, $spans);
        $this->assertNull($spans->last()->removed_at);
        $this->assertSame($this->day(0), $spans->last()->startDate());
        $this->assertTrue($project->fresh()->rosterTechnicians->contains('technician_id', $ana->technician_id));
        $this->assertSame([], $project->fresh()->scheduledChangesFor((int) $ana->technician_id));
    }

    public function test_completing_a_project_keeps_a_technician_whose_days_off_are_under_way(): void
    {
        [$project, $lead, $ana] = $this->runningProject();

        $this->daysOff($project, $ana, $this->day(0), $this->day(2))->assertOk();

        $this->post(route('super-admin.projects.complete', $project->project_id), [
            'completion_date' => $this->day(0),
            'completion_summary' => 'Everything on site is finished.',
            'completion_override_reason' => 'QA: closing with phases outstanding.',
        ])->assertRedirect();

        $project = $project->fresh();

        $this->assertTrue($project->isWorkFinished());
        $this->assertTrue($project->rosterTechnicians->contains('technician_id', $ana->technician_id));
        $this->assertSame([], $project->scheduledChangesFor((int) $ana->technician_id));
    }

    public function test_closing_during_a_leads_days_off_leaves_one_lead(): void
    {
        [$project, $john] = $this->runningProject();
        $mary = $this->technician('Mary Santos', 'lead_technician');

        $this->daysOff($project, $john, $this->day(0), $this->day(2), $mary)->assertOk();

        $this->post(route('super-admin.projects.cancel', $project->project_id), [
            'cancellation_date' => $this->day(0),
            'cancellation_reason' => 'Client withdrew',
        ]);

        $project = $project->fresh();

        $leads = $project->rosterTechnicians->filter(fn (ProjectTechnician $span): bool => $span->heldLeadRole());

        $this->assertSame([$john->technician_id], $leads->pluck('technician_id')->map(fn ($id): int => (int) $id)->values()->all());
        $this->assertSame([], $project->scheduledChangesFor((int) $john->technician_id));
        $this->assertSame([], $project->scheduledChangesFor((int) $mary->technician_id));
    }

    public function test_days_off_on_no_working_day_are_not_listed(): void
    {
        [$project, $john, $ana, $schedule] = $this->runningProject();
        $mary = $this->technician('Mary Santos', 'lead_technician');

        // A gap in the schedule from day 5 to day 9.
        $schedule->update(['end_datetime' => $this->day(4).' 23:59:59']);
        Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $this->day(10).' 00:00:00',
            'end_datetime' => $this->day(30).' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'After the gap',
        ]);

        $this->daysOff($project, $ana, $this->day(6), $this->day(8))->assertOk();
        $this->daysOff($project, $john, $this->day(5), $this->day(9), $mary)->assertOk();

        $project = $project->fresh();

        $this->assertSame([], $project->scheduledChangesFor((int) $ana->technician_id));
        $this->assertSame([], $project->scheduledChangesFor((int) $john->technician_id));
        $this->assertSame([], $project->scheduledChangesFor((int) $mary->technician_id));

        // Days off reaching a working day are listed, return and all.
        $this->daysOff($project, $ana, $this->day(12), $this->day(13))->assertOk();

        $this->assertSame(
            ['Days off', 'Returns'],
            array_column($project->fresh()->scheduledChangesFor((int) $ana->technician_id), 'title')
        );
    }

    public function test_one_day_off_is_named_as_one_day(): void
    {
        [$project, $lead, $ana] = $this->runningProject();

        $this->daysOff($project, $ana, $this->day(5), $this->day(5))->assertOk();

        $this->assertSame('Off '.$this->label(5), $project->fresh()->scheduledChangeLabel($this->spansOf($project, $ana)->first()));
    }

    public function test_the_schedules_day_panel_tells_days_off_from_leaving(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $this->daysOff($project, $tech, $this->day(5), $this->day(7))->assertOk();

        $crew = collect($this->getJson(route('super-admin.schedules.date', $this->day(2)))
            ->assertOk()
            ->json('projects.0.technicians'))
            ->keyBy('name');

        $this->assertSame('Off '.$this->label(5).' - '.$this->label(7), $crew['Ana Mendoza']['change']);
        $this->assertNull($crew['John Lead']['change']);

        $this->removeFrom($project, $tech, $this->day(3))->assertOk();

        $crew = collect($this->getJson(route('super-admin.schedules.date', $this->day(2)))
            ->json('projects.0.technicians'))
            ->keyBy('name');

        $this->assertSame('Leaving '.$this->label(3), $crew['Ana Mendoza']['change']);
    }

    public function test_the_assigned_projects_report_says_leaving_with_role_and_dates(): void
    {
        [$project, $lead, $tech] = $this->runningProject();

        $this->removeFrom($project, $tech, $this->day(10))->assertOk();

        $period = [
            'start' => CarbonImmutable::parse($this->day(-30))->startOfDay(),
            'end' => CarbonImmutable::parse($this->day(30))->endOfDay(),
        ];

        $section = collect(app(SystemReportService::class)->exportReport('technician', $period, ['technician_kind' => 'assigned'])['sections'])
            ->firstWhere('key', 'assigned');

        $row = collect($section['rows'])->firstWhere('technician_id', (int) $tech->technician_id);

        $this->assertSame('Leaving', $row['assignment_status']);
        $this->assertSame('Technician', $row['role']);
        $this->assertSame($this->label(10), $row['removed_on']);
    }
}
