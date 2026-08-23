<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Completing a task records what was done and a photo of it, and the view
 * modal reads both back.
 *
 * The dialogs are shared components, so this holds for the Super Admin portal
 * as well as the technician one - a task closed in either place carries the
 * same record.
 */
class TaskCompletionRecordTest extends TestCase
{
    use RefreshDatabase;

    private Technician $technician;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();

        $account = User::factory()->create(['email' => 'tech@example.test']);
        $account->forceFill(['role' => 'technician'])->save();

        $this->technician = Technician::create([
            'account_id' => $account->id,
            'role' => 'technician',
        ]);

        $this->project = Project::create([
            'name' => 'Completion Project',
            'reference_no' => 'REF-COMPLETE',
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 1000,
        ]);

        ProjectTechnician::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $this->technician->technician_id,
        ]);

        Schedule::create([
            'project_id' => $this->project->project_id,
            'start_datetime' => CarbonImmutable::today()->addDays(1)->toDateString().' 00:00:00',
            'end_datetime' => CarbonImmutable::today()->addDays(10)->toDateString().' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);
    }

    private function task(string $status = 'pending'): Task
    {
        return Task::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $this->technician->technician_id,
            'task_title' => 'Fit the unit',
            'task_description' => 'Mount and wire it.',
            'start_date' => CarbonImmutable::today()->addDays(2)->toDateString(),
            'due_date' => CarbonImmutable::today()->addDays(4)->toDateString(),
            'status' => $status,
        ]);
    }

    public function test_completing_a_task_records_the_notes_and_photos(): void
    {
        Storage::fake('uploads');

        $task = $this->task();

        $response = $this->patch(route('super-admin.tasks.complete', $task->task_id), [
            'completion_notes' => 'Unit fitted, tested and signed off.',
            'images' => [UploadedFile::fake()->image('done.jpg')],
        ]);

        $response->assertRedirect();

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame('Unit fitted, tested and signed off.', $task->completion_notes);
        $this->assertNotNull($task->completed_at);
        $this->assertCount(1, $task->images);
        Storage::disk('uploads')->assertExists($task->images->first()->image_path);
    }

    /**
     * An administrator did not do the work, so nothing is demanded of them.
     * The record says who closed it instead.
     */
    public function test_an_administrator_may_close_a_task_with_no_details(): void
    {
        $admin = auth()->user();
        $task = $this->task();

        $this->patch(route('super-admin.tasks.complete', $task->task_id), [])
            ->assertRedirect();

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertNull($task->completion_notes);
        $this->assertCount(0, $task->images);
        $this->assertSame($admin->id, (int) $task->completed_by);
        $this->assertTrue($task->wasClosedOnBehalf());
    }

    /**
     * The panel has to say so, rather than leaving a reader to wonder why the
     * record is bare.
     */
    public function test_a_task_closed_on_behalf_says_who_closed_it(): void
    {
        $task = $this->task();

        $this->patch(route('super-admin.tasks.complete', $task->task_id), []);

        $response = $this->get(route('super-admin.tasks.index'));

        $response->assertOk();
        $response->assertSee('Marked complete by');
        $response->assertSee('Test Administrator');
        $response->assertSee('The assigned technician did not submit completion details');
        $response->assertSee("None submitted &mdash; the task was closed on the technician's behalf.", false);
    }

    /**
     * The eye icon stays on a completed task, and what it opens is the
     * completion record rather than an editable form.
     */
    public function test_a_completed_task_reads_back_view_only_on_both_pages(): void
    {
        $task = $this->task('completed');
        $task->update([
            'completion_notes' => 'Coil cleaned and refrigerant topped up.',
            'completed_at' => now(),
        ]);
        $image = $task->images()->create(['image_path' => 'task-images/proof.jpg']);

        foreach ([
            route('super-admin.tasks.index'),
            route('super-admin.projects.show', $this->project->project_id),
        ] as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $response->assertSee('Completion Details');
            $response->assertSee('Coil cleaned and refrigerant topped up.');
            $response->assertSee($image->url(), escape: false);
            $response->assertSee('View Task');
            $response->assertDontSee('Edit Task');
        }
    }

    /**
     * A task completed before the notes existed still opens, and says so
     * rather than showing an empty panel.
     */
    public function test_a_task_completed_without_a_record_says_so(): void
    {
        $task = $this->task('completed');

        $response = $this->get(route('super-admin.tasks.index'));

        $response->assertOk();
        $response->assertSee('No completion description was recorded for this task.');
        $response->assertSee('No image available.');
    }

    /**
     * modal-dialog-scrollable caps the modal content and hands the scrolling
     * to the body. That only works while the body is a direct child of the
     * element carrying `modal-content` - a <form> in between caps the height
     * but never lets anything scroll, so the tail of the dialog, completion
     * photos included, is silently clipped.
     */
    public function test_the_task_dialog_keeps_its_scrollable_structure(): void
    {
        $task = $this->task('completed');

        $response = $this->get(route('super-admin.tasks.index'));
        $response->assertOk();

        // Just this dialog: the page carries other modals with a shape of
        // their own.
        $dialog = Str::before(
            Str::after($response->getContent(), 'id="taskModal'.$task->task_id.'"'),
            '</form>'
        );

        $this->assertStringContainsString(
            '<form class="modal-content"',
            $dialog,
            'The task dialog must make the form its modal content; nesting one inside breaks scrolling.'
        );
        $this->assertStringNotContainsString('<div class="modal-content"', $dialog);
        // The completion photos live at the very bottom, so they are what a
        // broken scroll container hides first.
        $this->assertStringContainsString('Completion Images', $dialog);
    }

    /**
     * A read-only dialog names the technician who holds the task instead of
     * listing everyone as a disabled choice, which reads like a picker that
     * merely happens to be greyed out.
     */
    public function test_a_completed_task_names_only_its_own_technician(): void
    {
        $other = User::factory()->create(['email' => 'other@example.test']);
        $other->forceFill(['role' => 'technician'])->save();

        $otherTechnician = Technician::create([
            'account_id' => $other->id,
            'role' => 'technician',
        ]);

        ProjectTechnician::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $otherTechnician->technician_id,
        ]);

        $this->task('completed');

        $response = $this->get(route('super-admin.tasks.index'));

        $response->assertOk();
        $response->assertSee('task-assign-static', false);
        // No radio for the assignee, and none for anybody else either.
        $response->assertDontSee('name="technician_id" value="'.$otherTechnician->technician_id.'"', false);
    }

    /**
     * An open task still offers the full picker, so editing is unaffected.
     */
    public function test_an_open_task_still_offers_every_technician(): void
    {
        $this->task();

        $response = $this->get(route('super-admin.tasks.index'));

        $response->assertOk();
        $response->assertSee('task-assign-row', false);
        $response->assertSee('name="technician_id"', false);
        $response->assertDontSee('task-assign-static', false);
    }

    /**
     * A plain technician has no administrative reach, but they can still read
     * back what was submitted on their own finished work.
     */
    public function test_a_technician_can_view_their_own_completed_task(): void
    {
        $task = $this->task('completed');
        $task->update([
            'completion_notes' => 'Swapped the capacitor.',
            'completed_at' => now(),
        ]);
        $image = $task->images()->create(['image_path' => 'task-images/tech-proof.jpg']);

        $this->actingAs($this->technician->account);

        $response = $this->get(route('technician.tasks'));

        $response->assertOk();
        $response->assertSee('Completion Details');
        $response->assertSee('Swapped the capacitor.');
        $response->assertSee($image->url(), escape: false);
        // Read only: no save button and no reassignment picker.
        $response->assertDontSee('Save Changes');
        $response->assertDontSee('task-assign-row', false);
    }
}
