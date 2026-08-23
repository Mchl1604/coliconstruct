<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use App\Services\TechnicianTaskLoad;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Assign To cards in the Create Task dialog.
 *
 * They are built in the browser from JSON rather than in Blade, which is how
 * they came to show a generic icon while every other copy of the same card
 * showed the person's own picture. These pin the payload that feeds them.
 */
class TaskAssignPickerTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();

        $this->project = Project::create([
            'name' => 'Picker Project',
            'reference_no' => 'REF-PICKER',
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
        ]);

        $schedule = Schedule::create([
            'project_id' => $this->project->project_id,
            'start_datetime' => CarbonImmutable::today()->addDays(1)->toDateString().' 00:00:00',
            'end_datetime' => CarbonImmutable::today()->addDays(5)->toDateString().' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        // Deliberately added in an order that puts the lead last, so a payload
        // that simply echoes the assignment order cannot pass.
        foreach ([
            ['Zoe Alvarez', 'technician', null],
            ['Andres Bonifacio', 'technician', 'profile-photos/andres.jpg'],
            ['Rita Lead', 'lead_technician', null],
        ] as [$name, $role, $photo]) {
            $user = User::factory()->create([
                'name' => $name,
                'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            ]);

            $user->forceFill([
                'role' => $role,
                'profile_photo_path' => $photo,
            ])->save();

            $technician = Technician::create([
                'account_id' => $user->id,
                'role' => $role,
            ]);

            $assignment = ProjectTechnician::create([
                'project_id' => $this->project->project_id,
                'technician_id' => $technician->technician_id,
            ]);

            ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $assignment->project_technician_id,
            ]);
        }
    }

    private function formData(): array
    {
        $response = $this->getJson(
            route('super-admin.projects.task-form-data', $this->project->project_id)
        );

        $response->assertOk();

        return $response->json('technicians');
    }

    public function test_the_lead_technician_comes_first_in_the_assign_to_list(): void
    {
        $technicians = $this->formData();

        $this->assertSame('Rita Lead', $technicians[0]['name']);
        $this->assertTrue($technicians[0]['is_lead']);

        // Everyone else follows alphabetically.
        $this->assertSame(
            ['Rita Lead', 'Andres Bonifacio', 'Zoe Alvarez'],
            array_column($technicians, 'name')
        );
    }

    public function test_every_technician_carries_a_picture_to_show(): void
    {
        foreach ($this->formData() as $technician) {
            $this->assertArrayHasKey('avatar_url', $technician);
            $this->assertNotEmpty($technician['avatar_url']);
        }
    }

    public function test_a_technician_who_uploaded_a_photo_is_shown_with_it(): void
    {
        $technicians = collect($this->formData());

        $withPhoto = $technicians->firstWhere('name', 'Andres Bonifacio');
        $withoutPhoto = $technicians->firstWhere('name', 'Zoe Alvarez');

        // The URL names the account rather than the file: the picture is
        // served by a route that checks the caller, not by its path.
        $this->assertStringContainsString('/media/avatars/', $withPhoto['avatar_url']);
        $this->assertNotSame(asset('img/default-avatar.svg'), $withPhoto['avatar_url']);

        // Nothing uploaded falls back to the shared default rather than to a
        // broken image or an icon that does not match the other cards.
        $this->assertSame(asset('img/default-avatar.svg'), $withoutPhoto['avatar_url']);
    }

    // ------------------------------------------------------------------
    // "N Active Tasks"
    // ------------------------------------------------------------------

    /**
     * The count is this project's, not the technician's whole workload.
     *
     * It used to be counted across every project at once, so somebody holding
     * one task on another job read as "1 Active Task" on a project they had
     * nothing on - which is exactly backwards from what the picker is for.
     */
    public function test_the_active_count_ignores_work_on_other_projects(): void
    {
        $zoe = Technician::whereHas('account', fn ($query) => $query->where('name', 'Zoe Alvarez'))->first();

        // A second project, with Zoe on it and a task in her name.
        $other = Project::create([
            'name' => 'Other Project',
            'reference_no' => 'REF-OTHER',
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
        ]);

        ProjectTechnician::create([
            'project_id' => $other->project_id,
            'technician_id' => $zoe->technician_id,
        ]);

        Task::create([
            'project_id' => $other->project_id,
            'technician_id' => $zoe->technician_id,
            'task_title' => 'Work on the other job',
            'task_description' => 'Nothing to do with the picker project.',
            'status' => 'pending',
        ]);

        $counts = collect($this->formData())->pluck('active_task_count', 'name');

        $this->assertSame(0, $counts['Zoe Alvarez'], 'Another project\'s task is not this project\'s load.');
    }

    public function test_the_active_count_counts_this_project_s_open_work(): void
    {
        $zoe = Technician::whereHas('account', fn ($query) => $query->where('name', 'Zoe Alvarez'))->first();

        foreach ([['Fit the unit', 'pending'], ['Test the unit', 'ongoing'], ['Old job', 'completed']] as [$title, $status]) {
            Task::create([
                'project_id' => $this->project->project_id,
                'technician_id' => $zoe->technician_id,
                'task_title' => $title,
                'task_description' => 'On this project.',
                'status' => $status,
            ]);
        }

        // Nobody owns this one, so it is outstanding work on the project but
        // not a load on any technician.
        Task::create([
            'project_id' => $this->project->project_id,
            'technician_id' => null,
            'task_title' => 'Waiting for an owner',
            'task_description' => 'Unassigned.',
            'status' => 'unassigned',
        ]);

        $counts = collect($this->formData())->pluck('active_task_count', 'name');

        // Pending and ongoing count; completed does not.
        $this->assertSame(2, $counts['Zoe Alvarez']);
        $this->assertSame(0, $counts['Rita Lead']);
    }

    /**
     * The same technician on two projects shows a different figure on each,
     * which is what the per-project keying on the Tasks page is for.
     */
    public function test_each_project_reports_its_own_load_for_the_same_technician(): void
    {
        $zoe = Technician::whereHas('account', fn ($query) => $query->where('name', 'Zoe Alvarez'))->first();

        $other = Project::create([
            'name' => 'Other Project',
            'reference_no' => 'REF-OTHER-2',
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
        ]);

        ProjectTechnician::create([
            'project_id' => $other->project_id,
            'technician_id' => $zoe->technician_id,
        ]);

        Task::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $zoe->technician_id,
            'task_title' => 'Here',
            'task_description' => 'On the picker project.',
            'status' => 'pending',
        ]);

        foreach (['There one', 'There two'] as $title) {
            Task::create([
                'project_id' => $other->project_id,
                'technician_id' => $zoe->technician_id,
                'task_title' => $title,
                'task_description' => 'On the other project.',
                'status' => 'pending',
            ]);
        }

        $byProject = app(TechnicianTaskLoad::class)
            ->forProjects([$this->project->project_id, $other->project_id]);

        $this->assertSame(1, $byProject[$this->project->project_id][$zoe->technician_id]);
        $this->assertSame(2, $byProject[$other->project_id][$zoe->technician_id]);
    }
}
