<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectMaterialEntry;
use App\Models\Task;
use App\Models\TechnicianReport;
use App\Models\User;
use App\Services\ProjectMaterialLedger;
use App\Support\BusinessTime;
use App\Support\RoleAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who may do what inside the office portal: creating projects, writing tasks
 * and reports, and changing a project's materials. See RoleAccess.
 */
class RoleCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, bool, bool, bool, bool}>
     */
    public static function roles(): array
    {
        // Creates projects, manages tasks, files reports, manages materials.
        return [
            'super admin' => [User::ROLE_SUPER_ADMIN, true, true, true, true],
            'admin officer' => [User::ROLE_ADMIN_OFFICER, true, false, false, true],
            'hr admin' => [User::ROLE_ADMIN, false, false, false, false],
            'purchasing admin' => [User::ROLE_PURCHASING_ADMIN, false, false, false, true],
            'lead technician' => [User::ROLE_LEAD_TECHNICIAN, false, false, false, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_each_role_holds_its_own_capabilities(string $role, bool $projects, bool $tasks, bool $reports, bool $materials): void
    {
        $account = $this->account($role);

        $this->assertSame($projects, RoleAccess::can($account, RoleAccess::CREATE_PROJECTS));
        $this->assertSame($tasks, RoleAccess::can($account, RoleAccess::MANAGE_TASKS));
        $this->assertSame($reports, RoleAccess::can($account, RoleAccess::FILE_REPORTS));
        $this->assertSame($materials, RoleAccess::can($account, RoleAccess::MANAGE_PROJECT_MATERIALS));
    }

    public function test_an_hr_admin_cannot_create_a_project_but_runs_the_rest(): void
    {
        $this->actingAs($this->account(User::ROLE_ADMIN));

        $this->get(route('super-admin.projects.create'))->assertRedirect();
        $this->assertRefused($this->post(route('super-admin.projects.create.store'), []));

        $this->get(route('super-admin.projects'))->assertOk()->assertDontSee('Add Project');
        $this->get(route('super-admin.dashboard'))->assertOk()->assertDontSee('New Project');

        foreach (['super-admin.schedules.index', 'super-admin.technicians.index', 'super-admin.configuration.index'] as $page) {
            $this->get(route($page))->assertOk();
        }
    }

    public function test_an_admin_officer_creates_projects(): void
    {
        $this->actingAs($this->account(User::ROLE_ADMIN_OFFICER));

        $this->get(route('super-admin.projects.create'))->assertOk();
        $this->get(route('super-admin.projects'))->assertOk()->assertSee('Add Project');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function readOnlyOffice(): array
    {
        return [
            'admin officer' => [User::ROLE_ADMIN_OFFICER],
            'hr admin' => [User::ROLE_ADMIN],
        ];
    }

    #[DataProvider('readOnlyOffice')]
    public function test_the_office_reads_tasks_and_reports_but_cannot_write_them(string $role): void
    {
        $project = $this->project('REF-CAP-'.$role);
        $task = Task::create([
            'project_id' => $project->project_id,
            'task_title' => 'Mount the bracket',
            'task_description' => 'Description',
            'status' => 'pending',
        ]);

        $this->actingAs($this->account($role));

        $this->assertRefused($this->post(route('super-admin.task.store', $project->project_id), []));
        $this->assertRefused($this->put(route('super-admin.tasks.update', $task->task_id), []));
        $this->assertRefused($this->patch(route('super-admin.tasks.complete', $task->task_id), []));
        $this->assertRefused($this->delete(route('super-admin.tasks.destroy', $task->task_id)));
        $this->assertRefused($this->post(route('super-admin.technician.reports.store', $project->project_id), [
            'report_type' => 'progress',
            'report_title' => 'Refused',
            'report_description' => 'Refused',
        ]));

        $this->assertSame(0, TechnicianReport::count());
        $this->assertSame('pending', $task->fresh()?->status);

        $this->get(route('super-admin.progress-reports.create'))->assertRedirect();
        $this->get(route('super-admin.incident-reports.create'))->assertRedirect();

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertDontSee('id="addTaskModal"', false)
            ->assertDontSee('data-bs-target="#addTaskModal"', false)
            ->assertDontSee(route('super-admin.progress-reports.create'), false);

        $this->get(route('super-admin.tasks.index'))->assertOk()->assertDontSee('Add New Task');
        $this->get(route('super-admin.reports.index'))->assertOk()->assertDontSee('data-create-report-modal', false);
    }

    public function test_a_super_admin_files_on_the_same_forms_a_lead_uses(): void
    {
        $project = $this->project('REF-CAP-SA');

        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee(route('super-admin.progress-reports.create', ['project' => $project->project_id]), false)
            ->assertSee(route('super-admin.incident-reports.create', ['project' => $project->project_id]), false)
            ->assertDontSee('addTechnicianReportModal', false);

        $this->get(route('super-admin.progress-reports.create', ['project' => $project->project_id]))
            ->assertOk()
            ->assertSee(route('super-admin.progress-reports.create'), false)
            ->assertSee('REF-CAP-SA');

        $this->get(route('super-admin.incident-reports.create', ['project' => $project->project_id]))
            ->assertOk()
            ->assertSee(route('super-admin.incident-reports.store'), false);
    }

    public function test_a_purchasing_admin_manages_a_projects_materials_only(): void
    {
        $project = $this->project('REF-CAP-BUY');
        $this->finalizePhases($project);
        $buyer = $this->account(User::ROLE_PURCHASING_ADMIN);
        $material = Material::factory()->measured()->create(['name' => 'Copper Tube 1/4']);

        $this->actingAs($buyer);

        $this->get(route('inventory.projects'))->assertOk();
        $this->getTablePage(route('inventory.projects.table'))->assertSee('REF-CAP-BUY');

        $this->get(route('inventory.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('Add Material')
            ->assertSee('Record Return')
            ->assertDontSee('Record Use');

        // The scheduling side's page stays closed to them.
        $this->get(route('super-admin.projects.show', $project->project_id))->assertRedirect();

        $this->postJson(route('inventory.stock.receive'), [
            'material_id' => $material->material_id,
            'movement_type' => 'stock_in',
            'quantity' => 10,
            'movement_date' => BusinessTime::today()->toDateString(),
        ])->assertOk();

        $this->post(route('inventory.projects.materials.add', $project->project_id), [
            'material_id' => $material->material_id,
            'quantity' => 4,
        ])->assertRedirect(route('inventory.projects.show', $project->project_id).'#project-materials')
            ->assertSessionHas('success');

        $line = ProjectMaterial::query()->where('project_id', $project->project_id)->firstOrFail();
        $entry = ProjectMaterialEntry::query()->firstOrFail();

        $this->assertRefused($this->post(route('inventory.projects.materials.record', $project->project_id), [
            'project_material_id' => $line->project_material_id,
            'entry_type' => 'used',
            'quantity' => 1,
            'entry_date' => BusinessTime::today()->toDateString(),
        ]));

        $this->post(route('inventory.projects.materials.record', $project->project_id), [
            'project_material_id' => $line->project_material_id,
            'entry_type' => 'returned',
            'quantity' => 1,
            'entry_date' => BusinessTime::today()->toDateString(),
        ])->assertSessionHas('success');

        $this->assertSame(7.0, $material->fresh()->onHand());

        $this->post(route('inventory.projects.materials.void', [$project->project_id, $entry->material_entry_id]))
            ->assertSessionHas('error', 'Already used. Void later entries first.');
    }

    public function test_an_hr_admin_reads_a_projects_materials_and_changes_nothing(): void
    {
        $project = $this->project('REF-CAP-HR');
        $this->finalizePhases($project);
        $material = Material::factory()->measured()->create();
        $admin = $this->account(User::ROLE_ADMIN);

        $officer = $this->account(User::ROLE_ADMIN_OFFICER);
        app(ProjectMaterialLedger::class)->add($project, $material, 2, today(), null, $officer, fromStock: false);
        $entry = ProjectMaterialEntry::query()->firstOrFail();

        $this->actingAs($admin);

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('Material History')
            ->assertDontSee('data-bs-target="#addMaterialModal"', false)
            ->assertDontSee('data-bs-target="#recordMaterialModal"', false);

        $this->assertRefused($this->post(route('super-admin.projects.materials.add', $project->project_id), [
            'material_id' => $material->material_id,
            'quantity' => 1,
        ]));

        $this->assertRefused($this->post(route('super-admin.projects.materials.void', [$project->project_id, $entry->material_entry_id])));

        $this->assertSame(1, ProjectMaterialEntry::count());
    }

    public function test_the_sidebar_gives_purchasing_its_projects_and_requests(): void
    {
        $this->actingAs($this->account(User::ROLE_PURCHASING_ADMIN));

        $this->get(route('inventory.index'))
            ->assertSee('href="'.route('inventory.projects').'"', false)
            ->assertSee('href="'.route('inventory.material-requests.index').'"', false);

        $this->actingAs($this->account(User::ROLE_ADMIN_OFFICER));

        $this->get(route('inventory.index'))
            ->assertDontSee('href="'.route('inventory.projects').'"', false)
            ->assertSee('href="'.route('inventory.material-requests.index').'"', false);
    }

    /**
     * Refused, however the portal answers it: a form is sent back with a toast
     * where it can be, and turned away outright where it cannot.
     */
    private function assertRefused(TestResponse $response): void
    {
        $this->assertContains($response->getStatusCode(), [302, 403]);
        $this->assertNotSame(session('success'), 'Report submitted successfully.');
    }

    private function project(string $reference): Project
    {
        return Project::create([
            'client_id' => Client::factory()->create()->client_id,
            'name' => 'Aircon Installation',
            'reference_no' => $reference,
            'status' => 'ongoing',
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);
    }

    private function account(string $role): User
    {
        $sequence = User::count() + 1;

        return User::create([
            'user_code' => sprintf('EMP-%04d', $sequence),
            'name' => 'Person '.$sequence,
            'first_name' => 'Person',
            'last_name' => (string) $sequence,
            'email' => 'person'.$sequence.'@example.test',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);
    }
}
