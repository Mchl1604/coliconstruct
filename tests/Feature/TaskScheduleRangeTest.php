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
 * A project's schedule can have gaps - July 10-15 and July 20-25, say.
 * A task must sit entirely inside ONE of those ranges. Checking only the
 * earliest start and latest end would wrongly accept July 16-19.
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

    /**
     * The exact reported bug: dates that sit in the gap between two ranges.
     */
    public function test_it_rejects_task_dates_inside_the_gap_between_ranges(): void
    {
        $response = $this->storeTask($this->day(16), $this->day(19));

        $response->assertSessionHasErrors(['start_date', 'due_date']);
        $this->assertSame(0, Task::count());
    }

    public function test_it_rejects_a_task_that_spans_the_gap(): void
    {
        // Starts inside range one, ends inside range two.
        $response = $this->storeTask($this->day(14), $this->day(21));

        $response->assertSessionHasErrors(['start_date', 'due_date']);
        $this->assertSame(0, Task::count());
    }

    public function test_it_rejects_a_task_that_starts_in_a_range_but_ends_in_the_gap(): void
    {
        $response = $this->storeTask($this->day(13), $this->day(18));

        $response->assertSessionHasErrors(['start_date', 'due_date']);
        $this->assertSame(0, Task::count());
    }

    public function test_it_rejects_a_task_that_starts_in_the_gap_and_ends_in_a_range(): void
    {
        $response = $this->storeTask($this->day(18), $this->day(22));

        $response->assertSessionHasErrors(['start_date', 'due_date']);
        $this->assertSame(0, Task::count());
    }

    public function test_it_rejects_dates_outside_every_range(): void
    {
        $this->storeTask($this->day(1), $this->day(3))
            ->assertSessionHasErrors(['start_date', 'due_date']);

        $this->storeTask($this->day(30), $this->day(31))
            ->assertSessionHasErrors(['start_date', 'due_date']);

        $this->assertSame(0, Task::count());
    }

    public function test_it_accepts_a_task_inside_the_first_range(): void
    {
        $response = $this->storeTask($this->day(11), $this->day(14));

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, Task::count());
    }

    public function test_it_accepts_a_task_inside_the_second_range(): void
    {
        $response = $this->storeTask($this->day(21), $this->day(24));

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, Task::count());
    }

    public function test_it_accepts_a_task_matching_a_range_exactly(): void
    {
        $response = $this->storeTask($this->day(20), $this->day(25));

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, Task::count());
    }

    /**
     * Editing an existing task is held to the same rule.
     */
    public function test_updating_a_task_into_the_gap_is_rejected(): void
    {
        $task = Task::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $this->technician->technician_id,
            'task_title' => 'Existing Task',
            'task_description' => 'Description',
            'start_date' => $this->day(11),
            'due_date' => $this->day(12),
            'status' => 'pending',
        ]);

        $response = $this->put(route('super-admin.tasks.update', $task->task_id), [
            'task_title' => 'Existing Task',
            'task_description' => 'Description',
            'technician_id' => $this->technician->technician_id,
            'start_date' => $this->day(16),
            'due_date' => $this->day(19),
        ]);

        $response->assertSessionHasErrors(['start_date', 'due_date']);

        $task->refresh();
        $this->assertSame($this->day(11), CarbonImmutable::parse($task->start_date)->toDateString());
    }

    public function test_updating_a_task_within_one_range_succeeds(): void
    {
        $task = Task::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $this->technician->technician_id,
            'task_title' => 'Existing Task',
            'task_description' => 'Description',
            'start_date' => $this->day(11),
            'due_date' => $this->day(12),
            'status' => 'pending',
        ]);

        $response = $this->put(route('super-admin.tasks.update', $task->task_id), [
            'task_title' => 'Existing Task',
            'task_description' => 'Description',
            'technician_id' => $this->technician->technician_id,
            'start_date' => $this->day(21),
            'due_date' => $this->day(23),
        ]);

        $response->assertSessionHasNoErrors();

        $task->refresh();
        $this->assertSame($this->day(21), CarbonImmutable::parse($task->start_date)->toDateString());
    }

    /**
     * The Add Task modal needs the individual ranges to build its pickers;
     * min/max alone is what allowed gap dates to be chosen.
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
    }
}
