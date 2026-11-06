<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HR Admin edits a project's team and schedule and nothing else about it.
 * Changing the project itself is the Super Admin's and the Admin Officer's.
 */
class HrAdminProjectAccessTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role, string $email): User
    {
        return User::create([
            'user_code' => strtoupper(substr($role, 0, 3)).'-'.random_int(1000, 9999),
            'name' => ucfirst($role),
            'first_name' => ucfirst($role),
            'last_name' => 'Person',
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'is_archived' => false,
            'must_change_password' => false,
            'password' => 'password',
        ] + $this->acceptedTerms());
    }

    private function project(): Project
    {
        $type = ProjectType::create(['type_name' => 'Aircon Installation']);

        $client = $this->fixtureClient([
            'client_type' => 'Residential',
            'firstname' => 'Juan',
            'surname' => 'Dela Cruz',
            'fullname' => 'Juan Dela Cruz',
            'email_address' => 'juan@example.test',
            'contact_number' => '09171234567',
        ]);

        $project = Project::create([
            'client_id' => $client->client_id,
            'reference_no' => 'PRJ-HR-1',
            'name' => 'Dela Cruz',
            'status' => 'ongoing',
            'quotation' => 1500,
            'address' => '12 Mabini Street',
            'description' => 'Work',
        ]);

        $project->projectTypes()->sync([$type->type_id]);

        Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => now()->subDay(),
            'end_datetime' => now()->addDay(),
            'status' => 'scheduled',
        ]);

        Document::create([
            'project_id' => $project->project_id,
            'document_type' => 'quotation',
            'document_name' => 'q.pdf',
            'document_path' => 'uploads/quotation/q.pdf',
            'uploaded_at' => now(),
        ]);

        $this->finalizePhases($project);

        return $project->refresh();
    }

    public function test_hr_admin_cannot_change_the_project_itself(): void
    {
        $project = $this->project();
        $document = $project->documents()->sole();
        $id = $project->project_id;

        $this->actingAs($this->account(User::ROLE_ADMIN, 'hr@example.test'));

        $attempts = [
            ['put', route('super-admin.projects.update', $id), ['quotation' => 99999]],
            ['put', route('super-admin.projects.client.change', $id), []],
            ['put', route('super-admin.projects.hold', $id), ['hold_reason' => 'Waiting on the client.']],
            ['put', route('super-admin.projects.resume', $id), []],
            ['put', route('super-admin.projects.resume-schedule', $id), []],
            ['post', route('super-admin.projects.cancel', $id), ['cancel_reason' => 'Client called it off.']],
            ['post', route('super-admin.projects.reopen', $id), ['reopen_reason' => 'More work came up.']],
            ['post', route('super-admin.projects.client-confirmation', $id), []],
            ['delete', route('super-admin.projects.documents.destroy', [$id, $document->document_id]), []],
        ];

        foreach ($attempts as [$method, $url, $data]) {
            $response = $this->{$method}($url, $data);

            $this->assertContains($response->getStatusCode(), [302, 403], $url);
            $this->assertFalse(session()->has('success'), $url.' reported success.');
            session()->forget('success');
        }

        $project->refresh();

        $this->assertSame('ongoing', $project->status);
        $this->assertFalse((bool) $project->on_hold);
        $this->assertSame('1500.00', (string) $project->quotation);
        $this->assertSame(1, $project->documents()->count());
    }

    public function test_hr_admin_sees_the_team_and_schedule_controls_and_no_others(): void
    {
        $project = $this->project();

        $this->actingAs($this->account(User::ROLE_ADMIN, 'hr@example.test'))
            ->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('data-bs-target="#editAssignedTeamModal"', false)
            ->assertSee('Update Schedule')
            ->assertDontSee('data-bs-target="#editProjectDetailsModal"', false)
            ->assertDontSee('data-bs-target="#onHoldModal"', false)
            ->assertDontSee('data-bs-target="#cancelProjectModal"', false)
            ->assertDontSee('data-document-remove', false);

        $this->actingAs($this->account(User::ROLE_ADMIN_OFFICER, 'officer@example.test'))
            ->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('data-bs-target="#editProjectDetailsModal"', false)
            ->assertSee('data-bs-target="#onHoldModal"', false)
            ->assertSee('data-bs-target="#cancelProjectModal"', false);
    }
}
