<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Technician;
use App\Models\User;
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

        $this->assertStringContainsString('andres.jpg', $withPhoto['avatar_url']);

        // Nothing uploaded falls back to the shared default rather than to a
        // broken image or an icon that does not match the other cards.
        $this->assertSame(asset('img/default-avatar.svg'), $withoutPhoto['avatar_url']);
    }
}
