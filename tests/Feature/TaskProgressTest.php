<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\TaskUpdateImage;
use App\Models\Technician;
use App\Models\User;
use App\Services\TaskScheduleRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 4: a new task starts tomorrow at the earliest, and a task's progress
 * is posted day by day before it is completed.
 */
class TaskProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $leadAccount;

    private User $mateAccount;

    private Technician $lead;

    private Technician $mate;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->leadAccount = $this->account('lead_technician', 'lead@example.test');
        $this->lead = Technician::create(['account_id' => $this->leadAccount->id, 'role' => 'lead_technician']);

        $this->mateAccount = $this->account('technician', 'mate@example.test');
        $this->mate = Technician::create(['account_id' => $this->mateAccount->id, 'role' => 'technician']);

        $this->project = $this->project('REF-PRG-1');
        $this->assign($this->project, $this->lead);
        $this->assign($this->project, $this->mate);

        // Booked from two days ago to ten days ahead, so today is a work day.
        $this->schedule($this->project, -2, 10);
    }

    // ------------------------------------------------------------------
    // New tasks start tomorrow at the earliest
    // ------------------------------------------------------------------

    public function test_a_lead_cannot_raise_a_task_for_today(): void
    {
        $this->actingAs($this->leadAccount);

        $this->postJson(route('technician.tasks.store', $this->project), $this->taskPayload(['start_date' => $this->day(0)]))
            ->assertStatus(422)
            ->assertJsonPath('error', TaskScheduleRules::NEW_TASK_REFUSAL);

        $this->postJson(route('technician.tasks.store', $this->project), $this->taskPayload(['start_date' => $this->day(1)]))
            ->assertCreated();
    }

    public function test_the_office_cannot_raise_a_task_for_today_either(): void
    {
        $this->actingAs($this->account('super_admin', 'owner@example.test'));

        $this->post(route('super-admin.task.store', $this->project->project_id), $this->taskPayload(['start_date' => $this->day(0)]))
            ->assertSessionHasErrors(['start_date' => TaskScheduleRules::NEW_TASK_REFUSAL]);

        $this->assertSame(0, Task::count());
    }

    public function test_an_existing_task_keeps_its_dates_when_edited(): void
    {
        $task = $this->task($this->mate, 'Already running', 'pending', -1);

        $this->actingAs($this->leadAccount);

        $this->postJson(route('technician.tasks.update', $task), $this->taskPayload([
            'task_title' => 'Already running, renamed',
            'start_date' => $this->day(-1),
            'due_date' => $this->day(5),
        ]))->assertOk();

        $this->assertSame('Already running, renamed', $task->fresh()->task_title);
    }

    // ------------------------------------------------------------------
    // Daily updates
    // ------------------------------------------------------------------

    public function test_the_holder_posts_today_and_the_task_becomes_ongoing(): void
    {
        $task = $this->task($this->mate, 'Run copper');

        $this->actingAs($this->mateAccount)
            ->post(route('technician.tasks.updates.store', $task), ['notes' => 'Ran 6 m of copper.'])
            ->assertSessionHas('success', 'Update posted.');

        $update = TaskUpdate::query()->firstOrFail();

        $this->assertSame('Ran 6 m of copper.', $update->notes);
        $this->assertSame($this->mate->technician_id, $update->technician_id);
        $this->assertSame('ongoing', $task->fresh()->status);
        $this->assertSame(1, ActivityLog::query()->where('action', ActivityLog::TASK_PROGRESS_POSTED)->count());

        // Posting again the same day edits that day's update.
        $this->actingAs($this->mateAccount)
            ->post(route('technician.tasks.updates.store', $task), ['notes' => 'Ran 8 m of copper.'])
            ->assertSessionHas('success');

        $this->assertSame(1, TaskUpdate::count());
        $this->assertSame('Ran 8 m of copper.', $update->fresh()->notes);
    }

    public function test_a_lead_posts_on_a_technicians_behalf(): void
    {
        $task = $this->task($this->mate, 'Mount bracket');

        $this->actingAs($this->leadAccount)
            ->postJson(route('technician.tasks.updates.store', $task), ['notes' => 'Bracket mounted.'])
            ->assertOk();

        $update = TaskUpdate::query()->firstOrFail();

        $this->assertSame($this->mate->technician_id, $update->technician_id);
        $this->assertSame($this->leadAccount->id, $update->posted_by);
        $this->assertTrue($update->wasPostedOnBehalf());
    }

    public function test_a_technician_cannot_post_on_someone_elses_task(): void
    {
        $task = $this->task($this->lead, "The lead's own");

        $this->actingAs($this->mateAccount)
            ->postJson(route('technician.tasks.updates.store', $task), ['notes' => 'Not mine.'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Not your task.');

        $this->assertSame(0, TaskUpdate::count());
    }

    public function test_nothing_is_posted_before_the_task_starts_or_on_a_paused_project(): void
    {
        $later = $this->task($this->mate, 'Later work', 'pending', 3);

        $this->actingAs($this->mateAccount)
            ->postJson(route('technician.tasks.updates.store', $later), ['notes' => 'Too early.'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'This task has not started yet.');

        $now = $this->task($this->mate, 'Current work');
        $this->project->forceFill(['on_hold' => true])->save();

        $this->actingAs($this->mateAccount)
            ->postJson(route('technician.tasks.updates.store', $now), ['notes' => 'Paused.'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'On hold. Resume it first.');
    }

    public function test_nothing_is_posted_on_a_day_the_project_is_not_booked(): void
    {
        Schedule::query()->where('project_id', $this->project->project_id)->delete();
        $this->schedule($this->project, -5, -1);

        $task = $this->task($this->mate, 'Yesterday work', 'pending', -3);

        $this->actingAs($this->mateAccount)
            ->postJson(route('technician.tasks.updates.store', $task), ['notes' => 'Off day.'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Not a work day for this project.');
    }

    public function test_photos_go_with_the_update_and_only_the_crew_reads_them(): void
    {
        Storage::fake('uploads');

        $task = $this->task($this->mate, 'Photo day');

        $this->actingAs($this->mateAccount)
            ->post(route('technician.tasks.updates.store', $task), [
                'notes' => 'With a photo.',
                'images' => [UploadedFile::fake()->image('site.jpg')],
            ])
            ->assertSessionHas('success');

        $image = TaskUpdateImage::query()->firstOrFail();

        $this->actingAs($this->mateAccount)->get($image->url())->assertOk();

        $outsider = $this->account('technician', 'outsider@example.test');
        Technician::create(['account_id' => $outsider->id, 'role' => 'technician']);

        // Turned away to their own portal, as every refused page is.
        $this->actingAs($outsider)->get($image->url())->assertRedirect(route('technician.schedule'));
    }

    public function test_the_task_dialog_shows_the_progress_and_the_button(): void
    {
        $task = $this->task($this->mate, 'Visible progress');

        TaskUpdate::create([
            'task_id' => $task->task_id,
            'technician_id' => $this->mate->technician_id,
            'update_date' => $this->day(-1),
            'notes' => 'Yesterday we wired the unit.',
            'posted_by' => $this->mateAccount->id,
        ]);

        $this->actingAs($this->mateAccount);

        $this->getTablePage(route('technician.projects.tasks.table', $this->project))
            ->assertSee('Yesterday we wired the unit.')
            ->assertSee('taskUpdateModal'.$task->task_id, false);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function account(string $role, string $email): User
    {
        $sequence = User::count() + 1;

        return User::create([
            'user_code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => 'Test Person '.$sequence,
            'first_name' => 'Test',
            'last_name' => 'Person '.$sequence,
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);
    }

    private function project(string $reference): Project
    {
        $project = Project::create([
            'client_id' => Client::factory()->create()->client_id,
            'name' => 'Aircon Retrofit',
            'reference_no' => $reference,
            'status' => 'ongoing',
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);

        $this->finalizePhases($project);

        return $project;
    }

    private function assign(Project $project, Technician $technician): ProjectTechnician
    {
        return ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);
    }

    private function schedule(Project $project, int $from, int $to): Schedule
    {
        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $this->day($from).' 00:00:00',
            'end_datetime' => $this->day($to).' 23:59:59',
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
        return CarbonImmutable::today()->addDays($offset)->toDateString();
    }

    private function task(Technician $technician, string $title, string $status = 'pending', int $startsIn = 0): Task
    {
        return Task::create([
            'project_id' => $this->project->project_id,
            'phase_id' => $this->defaultPhaseId($this->project),
            'technician_id' => $technician->technician_id,
            'task_title' => $title,
            'task_description' => 'Do the thing',
            'start_date' => $this->day($startsIn),
            'due_date' => $this->day(max($startsIn, 0) + 5),
            'status' => $status,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function taskPayload(array $overrides = []): array
    {
        return array_merge([
            'task_title' => 'Install condenser',
            'task_description' => 'Mount and wire the outdoor unit.',
            'phase_id' => $this->defaultPhaseId($this->project),
            'technician_id' => $this->mate->technician_id,
            'start_date' => $this->day(1),
            'due_date' => $this->day(3),
        ], $overrides);
    }
}
