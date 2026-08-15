<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A project's schedule can have gaps - booked day 10-15 and day 20-25, say.
 *
 * A task may SPAN such a gap: "start when we arrive, finish before we leave"
 * is a sensible task on a project that runs in two visits, and the days
 * between the visits are the project's own business.
 *
 * What it may not do is BEGIN or END on a day nobody is booked. A day in the
 * gap is not a day this project exists on, so a task cannot start then and a
 * deadline cannot fall then. Reaching outside the period altogether is
 * refused for the same reason.
 */
class TaskScheduleRangeTest extends TestCase
{
    use RefreshDatabase;

    private Technician $technician;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        // Every administrative route is behind `auth` and a role check.
        $this->actingAsSuperAdmin();

        $user = User::factory()->create([
            'name' => 'Ana Mendoza',
            'email' => 'ana.mendoza@example.test',
        ]);
        $user->forceFill(['role' => 'technician'])->save();

        $this->technician = Technician::create([
            'account_id' => $user->id,
            'role' => 'technician',
        ]);

        $this->project = Project::create([
            'name' => 'Split Schedule Project',
            'reference_no' => 'REF-SPLIT',
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
        ]);

        $assignment = ProjectTechnician::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $this->technician->technician_id,
        ]);

        // Two ranges with a four-day gap between them.
        foreach ([[10, 15], [20, 25]] as [$from, $to]) {
            $schedule = Schedule::create([
                'project_id' => $this->project->project_id,
                'start_datetime' => $this->day($from).' 00:00:00',
                'end_datetime' => $this->day($to).' 23:59:59',
                'status' => 'scheduled',
                'remarks' => 'Booking',
            ]);

            ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $assignment->project_technician_id,
            ]);
        }
    }

    private function day(int $offset): string
    {
        return CarbonImmutable::today()->addDays($offset)->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $startDate, string $dueDate): array
    {
        return [
            'task_title' => 'Some Task',
            'task_description' => 'Description',
            'technician_id' => $this->technician->technician_id,
            'start_date' => $startDate,
            'due_date' => $dueDate,
        ];
    }

    private function storeTask(string $startDate, string $dueDate)
    {
        return $this->post(
            route('super-admin.task.store', $this->project->project_id),
            $this->payload($startDate, $dueDate)
        );
    }

    // ------------------------------------------------------------------
    // Inside the period
    // ------------------------------------------------------------------

    public function test_it_accepts_a_task_inside_the_first_range(): void
    {
        $this->storeTask($this->day(11), $this->day(14))->assertSessionHasNoErrors();

        $this->assertSame(1, Task::count());
    }

    public function test_it_accepts_a_task_inside_the_second_range(): void
    {
        $this->storeTask($this->day(21), $this->day(24))->assertSessionHasNoErrors();

        $this->assertSame(1, Task::count());
    }

    public function test_it_accepts_a_task_that_spans_the_gap(): void
    {
        // Starts inside range one, ends inside range two.
        $this->storeTask($this->day(14), $this->day(21))->assertSessionHasNoErrors();

        $this->assertSame(1, Task::count());
    }

    /**
     * The one case the old rule got wrong. Both endpoints land on days nobody
     * is booked, so there is no day for the work to start on and no day for it
     * to be handed in.
     */
    public function test_it_rejects_a_task_lying_wholly_in_the_gap(): void
    {
        $this->storeTask($this->day(16), $this->day(19))
            ->assertSessionHasErrors(['start_date', 'due_date']);

        $this->assertSame(0, Task::count());
    }

    public function test_it_rejects_a_task_starting_in_the_gap(): void
    {
        // Ends on a booked day, but begins on one nobody is on site for.
        $this->storeTask($this->day(16), $this->day(21))
            ->assertSessionHasErrors('start_date');

        $this->assertSame(0, Task::count());
    }

    public function test_it_rejects_a_task_due_in_the_gap(): void
    {
        $this->storeTask($this->day(14), $this->day(19))
            ->assertSessionHasErrors('due_date');

        $this->assertSame(0, Task::count());
    }

    public function test_it_accepts_a_task_covering_the_whole_period(): void
    {
        $this->storeTask($this->day(10), $this->day(25))->assertSessionHasNoErrors();

        $this->assertSame(1, Task::count());
    }

    // ------------------------------------------------------------------
    // Outside it
    // ------------------------------------------------------------------

    public function test_it_rejects_a_task_starting_before_the_period(): void
    {
        // Only the start is wrong, and only the start is complained about:
        // somebody who picked a good deadline should not be told to change it.
        $this->storeTask($this->day(8), $this->day(12))
            ->assertSessionHasErrors('start_date');

        $this->assertSame(0, Task::count());
    }

    public function test_it_rejects_a_task_ending_after_the_period(): void
    {
        $this->storeTask($this->day(10), $this->day(30))
            ->assertSessionHasErrors('due_date');

        $this->assertSame(0, Task::count());
    }

    public function test_it_rejects_dates_outside_the_period_entirely(): void
    {
        $this->storeTask($this->day(1), $this->day(3))
            ->assertSessionHasErrors(['start_date', 'due_date']);

        $this->storeTask($this->day(30), $this->day(31))
            ->assertSessionHasErrors(['start_date', 'due_date']);

        $this->assertSame(0, Task::count());
    }

    // ------------------------------------------------------------------
    // Editing is held to the same rule
    // ------------------------------------------------------------------

    private function existingTask(): Task
    {
        return Task::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $this->technician->technician_id,
            'task_title' => 'Existing Task',
            'task_description' => 'Description',
            'start_date' => $this->day(11),
            'due_date' => $this->day(12),
            'status' => 'pending',
        ]);
    }

    private function updateTask(Task $task, string $startDate, string $dueDate)
    {
        return $this->put(route('super-admin.tasks.update', $task->task_id), [
            'task_title' => 'Existing Task',
            'task_description' => 'Description',
            'technician_id' => $this->technician->technician_id,
            'start_date' => $startDate,
            'due_date' => $dueDate,
        ]);
    }

    public function test_updating_a_task_across_the_gap_succeeds(): void
    {
        $task = $this->existingTask();

        $this->updateTask($task, $this->day(14), $this->day(21))->assertSessionHasNoErrors();

        $this->assertSame($this->day(14), CarbonImmutable::parse($task->refresh()->start_date)->toDateString());
    }

    public function test_updating_a_task_outside_the_period_is_rejected(): void
    {
        $task = $this->existingTask();

        $this->updateTask($task, $this->day(11), $this->day(40))
            ->assertSessionHasErrors('due_date');

        $this->assertSame($this->day(11), CarbonImmutable::parse($task->refresh()->start_date)->toDateString());
        $this->assertSame($this->day(12), CarbonImmutable::parse($task->refresh()->due_date)->toDateString());
    }

    // ------------------------------------------------------------------
    // What the form is told
    // ------------------------------------------------------------------

    /**
     * The pickers work the period out from the ranges, and the ranges are
     * still what the screens show a person as actually booked.
     */
    public function test_the_task_form_endpoint_exposes_every_range(): void
    {
        $response = $this->getJson(
            route('super-admin.projects.task-form-data', $this->project->project_id)
        );

        $response->assertOk();
        $response->assertJsonCount(2, 'ranges');
        $response->assertJsonPath('ranges.0.start', $this->day(10));
        $response->assertJsonPath('ranges.0.end', $this->day(15));
        $response->assertJsonPath('ranges.1.start', $this->day(20));
        $response->assertJsonPath('ranges.1.end', $this->day(25));

        // The outer bounds are the period a task may be dated in.
        $response->assertJsonPath('schedule_start', $this->day(10));
        $response->assertJsonPath('schedule_end', $this->day(25));
    }

    /**
     * The reason this rule was widened: splitting a range must not strand the
     * work that was booked across it.
     */
    public function test_a_task_survives_the_range_it_sat_in_being_split(): void
    {
        $task = Task::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $this->technician->technician_id,
            'task_title' => 'Spans the split',
            'task_description' => 'Description',
            'start_date' => $this->day(11),
            'due_date' => $this->day(14),
            'status' => 'pending',
        ]);

        $schedule = Schedule::where('project_id', $this->project->project_id)
            ->orderBy('start_datetime')
            ->firstOrFail();

        $this->deleteJson(route('super-admin.schedules.dates.destroy', [
            'schedule' => $schedule->schedule_id,
            'date' => $this->day(13),
        ]))->assertOk()->assertJsonPath('cleared_tasks', 0);

        $this->assertSame($this->day(11), CarbonImmutable::parse($task->refresh()->start_date)->toDateString());
        $this->assertSame($this->day(14), CarbonImmutable::parse($task->refresh()->due_date)->toDateString());
    }
}
