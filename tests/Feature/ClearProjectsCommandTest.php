<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `projects:clear`: every project and everything recorded on it goes, so the
 * demonstration data can be seeded afresh. Accounts and settings stay.
 */
class ClearProjectsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function projectWithWork(): Project
    {
        $project = Project::create([
            'client_id' => Client::factory()->create()->client_id,
            'name' => 'Old Job',
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
        ]);

        Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => now()->addDay()->startOfDay(),
            'end_datetime' => now()->addDays(2)->endOfDay(),
            'status' => 'scheduled',
        ]);

        Task::create([
            'project_id' => $project->project_id,
            'task_title' => 'Old task',
            'task_description' => 'Description',
            'status' => 'pending',
        ]);

        return $project;
    }

    public function test_every_project_and_its_records_are_deleted(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $project = $this->projectWithWork();

        DB::table('tbl_activity_logs')->insert([
            'actor_name' => 'Test Super Admin',
            'action' => 'Project Updated',
            'description' => 'About the old job.',
            'record_type' => 'Project',
            'record_id' => $project->project_id,
            'created_at' => now(),
        ]);

        Notification::create([
            'user_id' => $admin->id,
            'title' => 'Project updated',
            'message' => 'Old job changed.',
            'module' => 'Projects',
            'reference_type' => 'Project',
            'reference_id' => $project->project_id,
            'url' => route('super-admin.projects.show', $project->project_id),
        ]);

        // About an account, not a project: stays.
        Notification::create([
            'user_id' => $admin->id,
            'title' => 'Account created',
            'message' => 'Someone joined.',
            'module' => 'User Management',
            'reference_type' => 'User',
            'reference_id' => $admin->id,
        ]);

        $this->artisan('projects:clear', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Project::query()->count());
        $this->assertSame(0, Client::query()->count());
        $this->assertSame(0, Schedule::query()->count());
        $this->assertSame(0, Task::query()->count());
        $this->assertSame(0, DB::table('tbl_activity_logs')->where('record_type', 'Project')->count());
        $this->assertSame(['User'], Notification::query()->pluck('reference_type')->all());
    }

    public function test_accounts_are_left_alone(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $account = User::factory()->create();
        $this->projectWithWork();

        $this->artisan('projects:clear', ['--force' => true])->assertSuccessful();

        $this->assertTrue(User::query()->whereKey([$admin->id, $account->id])->count() === 2);
    }

    public function test_it_asks_first_and_a_no_keeps_everything(): void
    {
        $this->projectWithWork();

        $this->artisan('projects:clear')
            ->expectsConfirmation('Delete all 1 project(s), 1 client record(s) and everything recorded on them? This cannot be undone.', 'no')
            ->assertFailed();

        $this->assertSame(1, Project::query()->count());
    }
}
