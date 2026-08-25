<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\TaskImage;
use App\Models\Technician;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What a plain technician may see of the task board, and what a lead still may.
 *
 * The portal has always been shared by the two technician roles: one set of
 * pages, with reach decided by ProjectPolicy and TaskPolicy rather than by
 * separate screens. That stays. What changes here is that a plain technician's
 * board is now their own work alone - a colleague's task on the same project is
 * neither listed nor reachable - while a lead goes on running the whole board
 * for the projects they are on.
 *
 * The narrowing is done in SQL, by Task::scopeVisibleTo, rather than by hiding
 * rows in a template: a task a technician may not see should never reach the
 * page in the first place, and the same rule then covers the JSON the schedule
 * panel reads and the route that serves task photographs.
 */
class TechnicianTaskVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $leadAccount;

    private Technician $lead;

    private User $alexAccount;

    private Technician $alex;

    private User $bettyAccount;

    private Technician $betty;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->leadAccount = $this->account('lead_technician', 'lead@example.test');
        $this->lead = $this->technician($this->leadAccount);

        // Two plain technicians on one project - the whole point of the
        // feature is that neither of them sees the other's work.
        $this->alexAccount = $this->account('technician', 'alex@example.test');
        $this->alex = $this->technician($this->alexAccount);

        $this->bettyAccount = $this->account('technician', 'betty@example.test');
        $this->betty = $this->technician($this->bettyAccount);

        $this->project = $this->project('Aircon Retrofit', 'REF-VIS-1');

        foreach ([$this->lead, $this->alex, $this->betty] as $member) {
            $this->assign($this->project, $member);
        }

        $this->schedule($this->project, 10, 20);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function account(string $role, string $email): User
    {
        $sequence = User::count() + 1;

        return User::create([
            'user_code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => 'Test Person '.$sequence,
            'first_name' => 'Test',
            'last_name' => 'Person',
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);
    }

    private function technician(User $account): Technician
    {
        return Technician::create([
            'account_id' => $account->id,
            'role' => $account->role,
        ]);
    }

    private function project(string $name, string $reference, string $status = 'ongoing'): Project
    {
        return Project::create([
            'name' => $name,
            'reference_no' => $reference,
            'status' => $status,
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);
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

    private function task(?Technician $technician, string $title, string $status = 'pending', ?Project $project = null): Task
    {
        return Task::create([
            'project_id' => ($project ?? $this->project)->project_id,
            'technician_id' => $technician?->technician_id,
            'task_title' => $title,
            'task_description' => 'Do the thing',
            'start_date' => $this->day(11),
            'due_date' => $this->day(12),
            'status' => $technician === null ? 'unassigned' : $status,
        ]);
    }

    // ==================================================================
    // 1. The Tasks page
    // ==================================================================

    public function test_a_technician_sees_only_their_own_tasks_on_the_task_page(): void
    {
        $this->task($this->alex, 'Alex task');
        $this->task($this->betty, 'Betty task');
        $this->task($this->lead, 'Lead task');

        $response = $this->actingAs($this->alexAccount)->get(route('technician.tasks'));

        $response->assertOk();
        $response->assertSee('Alex task');
        $response->assertDontSee('Betty task');
        $response->assertDontSee('Lead task');
    }

    /**
     * A task nobody has been given is not the logged-in technician's task
     * either. The system only ever considers a task theirs when it is assigned
     * to them, so an unassigned one stays off their board.
     */
    public function test_an_unassigned_task_is_not_shown_to_a_technician(): void
    {
        $this->task($this->alex, 'Alex task');
        $this->task(null, 'Nobody has this yet');

        $response = $this->actingAs($this->alexAccount)->get(route('technician.tasks'));

        $response->assertOk();
        $response->assertSee('Alex task');
        $response->assertDontSee('Nobody has this yet');
    }

    /**
     * The count in the header follows the list rather than the project, so a
     * technician is not told there are three tasks on a board showing one.
     */
    public function test_the_task_count_follows_what_the_technician_can_see(): void
    {
        $this->task($this->alex, 'Alex task');
        $this->task($this->betty, 'Betty task');
        $this->task($this->lead, 'Lead task');

        $this->actingAs($this->alexAccount)
            ->get(route('technician.tasks'))
            ->assertSee('1 task');

        $this->actingAs($this->leadAccount)
            ->get(route('technician.tasks'))
            ->assertSee('3 tasks');
    }

    // ==================================================================
    // 2. The project page
    // ==================================================================

    public function test_the_project_page_shows_a_technician_only_their_own_tasks(): void
    {
        $this->task($this->alex, 'Alex task');
        $this->task($this->betty, 'Betty task');

        $response = $this->actingAs($this->alexAccount)
            ->get(route('technician.projects.show', $this->project));

        $response->assertOk();
        $response->assertSee('Alex task');
        $response->assertDontSee('Betty task');
    }

    /**
     * The dialogs are rendered per task, so a task that is off the list must
     * take its dialogs with it - otherwise a colleague's completion notes are
     * still in the markup, one console command away.
     */
    public function test_another_technicians_task_dialog_is_not_in_the_markup(): void
    {
        $mine = $this->task($this->alex, 'Alex task');
        $theirs = $this->task($this->betty, 'Betty task');
        $theirs->update([
            'status' => 'completed',
            'completion_notes' => 'Bettys private notes.',
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($this->alexAccount)
            ->get(route('technician.projects.show', $this->project));

        $response->assertOk();
        $response->assertSee('taskModal'.$mine->task_id, false);
        $response->assertDontSee('taskModal'.$theirs->task_id, false);
        $response->assertDontSee('Bettys private notes.');
    }

    // ==================================================================
    // 3. Direct access: JSON, ids and files
    // ==================================================================

    /**
     * The schedule panel reads this. Asking it directly is asking the same
     * question by another route, and it gets the same answer.
     */
    public function test_the_project_details_payload_is_narrowed_for_a_technician(): void
    {
        $this->task($this->alex, 'Alex task');
        $this->task($this->betty, 'Betty task');
        $this->task(null, 'Nobody has this yet');

        $response = $this->actingAs($this->alexAccount)
            ->getJson(route('technician.projects.details', $this->project));

        $response->assertOk();
        $response->assertJsonCount(1, 'tasks');
        $response->assertJsonPath('tasks.0.title', 'Alex task');
    }

    /**
     * mine_only=0 is the parameter somebody would reach for to widen the list.
     * It cannot: the narrowing does not depend on it.
     */
    public function test_a_technician_cannot_widen_the_payload_with_a_request_parameter(): void
    {
        $this->task($this->alex, 'Alex task');
        $this->task($this->betty, 'Betty task');

        $response = $this->actingAs($this->alexAccount)->getJson(
            route('technician.projects.details', $this->project).'?mine_only=0'
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'tasks');
        $response->assertJsonPath('tasks.0.title', 'Alex task');
    }

    /**
     * The photograph filed against a colleague's task is behind the same rule
     * as the task itself. Without this, a technician who could no longer see
     * the task could still fetch its picture by asking for the id.
     */
    public function test_a_technician_cannot_fetch_another_technicians_task_photo(): void
    {
        Storage::fake('local');

        $mine = $this->task($this->alex, 'Alex task');
        $theirs = $this->task($this->betty, 'Betty task');

        $myImage = TaskImage::create(['task_id' => $mine->task_id, 'image_path' => 'task-images/mine.jpg']);
        $theirImage = TaskImage::create(['task_id' => $theirs->task_id, 'image_path' => 'task-images/theirs.jpg']);

        $this->actingAs($this->alexAccount)
            ->get(route('media.task-image', $theirImage))
            ->assertForbidden();

        // Their own is refused only by the file being absent from the fake
        // disk, which is a 404 rather than a 403 - the authorization passed.
        $this->actingAs($this->alexAccount)
            ->get(route('media.task-image', $myImage))
            ->assertNotFound();
    }

    /**
     * Closing somebody else's task was already refused and still is. Stated
     * here so the write path is proved beside the read path.
     */
    public function test_a_technician_still_cannot_complete_another_technicians_task(): void
    {
        $theirs = $this->task($this->betty, 'Betty task');

        $this->actingAs($this->alexAccount)
            ->postJson(route('technician.tasks.complete', $theirs), [
                'completion_notes' => 'Not mine to close.',
            ])
            ->assertForbidden();

        $this->assertSame('pending', $theirs->refresh()->status);
    }

    /**
     * A technician on a project they are not assigned to sees nothing at all,
     * which is the outer fence the narrowing sits inside.
     */
    public function test_a_technician_cannot_reach_a_project_they_are_not_on(): void
    {
        $other = $this->project('Someone Elses Job', 'REF-VIS-2');
        $outsider = $this->account('technician', 'outsider@example.test');
        $this->technician($outsider);

        $this->actingAs($outsider)
            ->get(route('technician.projects.show', $other))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->getJson(route('technician.projects.details', $other))
            ->assertForbidden();
    }

    // ==================================================================
    // 4. The lead is unchanged
    // ==================================================================

    public function test_a_lead_still_sees_the_whole_board(): void
    {
        $this->task($this->alex, 'Alex task');
        $this->task($this->betty, 'Betty task');
        $this->task($this->lead, 'Lead task');
        $this->task(null, 'Nobody has this yet');

        $tasks = $this->actingAs($this->leadAccount)->get(route('technician.tasks'));
        $tasks->assertOk();
        $tasks->assertSee('Alex task');
        $tasks->assertSee('Betty task');
        $tasks->assertSee('Lead task');
        $tasks->assertSee('Nobody has this yet');

        $details = $this->actingAs($this->leadAccount)
            ->get(route('technician.projects.show', $this->project));
        $details->assertOk();
        $details->assertSee('Alex task');
        $details->assertSee('Betty task');
    }

    public function test_a_leads_payload_still_carries_the_whole_board(): void
    {
        $this->task($this->alex, 'Alex task');
        $this->task($this->betty, 'Betty task');

        $this->actingAs($this->leadAccount)
            ->getJson(route('technician.projects.details', $this->project))
            ->assertOk()
            ->assertJsonCount(2, 'tasks');
    }

    /**
     * And mine_only still narrows it for a lead, which is what the schedule
     * panel asks for. The new rule did not replace that one.
     */
    public function test_mine_only_still_narrows_a_leads_payload(): void
    {
        $this->task($this->alex, 'Alex task');
        $this->task($this->lead, 'Lead task');

        $this->actingAs($this->leadAccount)
            ->getJson(route('technician.projects.details', $this->project).'?mine_only=1')
            ->assertOk()
            ->assertJsonCount(1, 'tasks')
            ->assertJsonPath('tasks.0.title', 'Lead task');
    }

    public function test_a_lead_may_still_open_a_task_photo_on_their_project(): void
    {
        Storage::fake('local');

        $theirs = $this->task($this->betty, 'Betty task');
        $image = TaskImage::create(['task_id' => $theirs->task_id, 'image_path' => 'task-images/theirs.jpg']);

        // Not a 403: the authorization passes and only the missing file on the
        // fake disk stops it.
        $this->actingAs($this->leadAccount)
            ->get(route('media.task-image', $image))
            ->assertNotFound();
    }

    // ==================================================================
    // 5. The overdue notice
    // ==================================================================

    /**
     * The overdue banner asks its reader to close the project off or to have
     * the schedule extended. A plain technician can do neither, so they are
     * not shown it.
     */
    public function test_a_technician_is_not_shown_the_overdue_notice(): void
    {
        $overdue = $this->overdueProject();

        $response = $this->actingAs($this->alexAccount)
            ->get(route('technician.projects.show', $overdue));

        $response->assertOk();
        $response->assertDontSee('Last scheduled day was');
        $response->assertDontSee('This project is overdue');
        $response->assertDontSee('ask an administrator to extend the schedule');
    }

    /**
     * And the page still works: their own task is there, with the one action
     * they have on it.
     */
    public function test_an_overdue_project_still_shows_a_technician_their_work(): void
    {
        $overdue = $this->overdueProject();

        $task = Task::create([
            'project_id' => $overdue->project_id,
            'technician_id' => $this->alex->technician_id,
            'task_title' => 'Still to do',
            'task_description' => 'Do the thing',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->alexAccount)
            ->get(route('technician.projects.show', $overdue));

        $response->assertOk();
        $response->assertSee('Still to do');
        $response->assertSee('completeTaskModal'.$task->task_id, false);
    }

    /**
     * The lead keeps it: closing the project off is theirs to do, and asking
     * an administrator to extend the schedule is theirs to ask.
     */
    public function test_a_lead_is_still_shown_the_overdue_notice(): void
    {
        $overdue = $this->overdueProject();

        $response = $this->actingAs($this->leadAccount)
            ->get(route('technician.projects.show', $overdue));

        $response->assertOk();
        $response->assertSee('This project is overdue');
        $response->assertSee('Last scheduled day was');
    }

    /**
     * A project whose last booked day has passed while it is still running.
     */
    private function overdueProject(): Project
    {
        $overdue = $this->project('Late Job', 'REF-VIS-LATE');

        foreach ([$this->lead, $this->alex] as $member) {
            $this->assign($overdue, $member);
        }

        $this->schedule($overdue, -20, -5);

        return $overdue->refresh();
    }
}
