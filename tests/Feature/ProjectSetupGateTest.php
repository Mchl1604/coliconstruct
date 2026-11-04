<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Technician;
use App\Models\User;
use Database\Seeders\DetailFormSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A project nobody has set up: everyone who can see it may open it, only a
 * Super Admin or its lead may set it up, and nobody changes it until then.
 */
class ProjectSetupGateTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $admin;

    private User $officer;

    private User $leadAccount;

    private User $techAccount;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = $this->account('super_admin');
        $this->admin = $this->account('admin');
        $this->officer = $this->account('admin_officer');
        $this->leadAccount = $this->account('lead_technician');
        $this->techAccount = $this->account('technician');

        $this->project = Project::create([
            'client_id' => Client::factory()->create()->client_id,
            'name' => 'Aircon Retrofit',
            'reference_no' => 'REF-GATE-1',
            'status' => 'ongoing',
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);

        foreach ([[$this->leadAccount, 'lead_technician'], [$this->techAccount, 'technician']] as [$account, $role]) {
            $technician = Technician::create(['account_id' => $account->id, 'role' => $role]);

            ProjectTechnician::create([
                'project_id' => $this->project->project_id,
                'technician_id' => $technician->technician_id,
                'joined_at' => now()->subDays(5),
            ]);
        }
    }

    public function test_only_a_super_admin_or_the_lead_may_open_setup(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.projects.phases.setup', $this->project->project_id))
            ->assertOk();

        $this->actingAs($this->leadAccount)
            ->get(route('technician.projects.phases.setup', $this->project->project_id))
            ->assertOk();

        foreach ([$this->admin, $this->officer] as $account) {
            $this->assertNotSame(200, $this->actingAs($account)
                ->get(route('super-admin.projects.phases.setup', $this->project->project_id))
                ->getStatusCode());

            $this->assertNotSame(200, $this->actingAs($account)
                ->post(route('super-admin.projects.phases.finalize', $this->project->project_id), [])
                ->getStatusCode());

            $this->assertTrue($this->project->fresh()->awaitingInitialSetup());
        }
    }

    public function test_every_role_can_open_the_project_and_sees_the_sign(): void
    {
        foreach ([$this->superAdmin, $this->admin, $this->officer] as $account) {
            $this->actingAs($account)
                ->get(route('super-admin.projects.show', $this->project->project_id))
                ->assertOk()
                ->assertSee('This project is not set up yet.');
        }

        foreach ([$this->leadAccount, $this->techAccount] as $account) {
            $this->actingAs($account)
                ->get(route('technician.projects.show', $this->project->project_id))
                ->assertOk()
                ->assertSee('This project is not set up yet.');
        }
    }

    public function test_the_sign_offers_set_up_only_to_those_who_can(): void
    {
        $setupUrl = route('super-admin.projects.phases.setup', $this->project->project_id);

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.projects.show', $this->project->project_id))
            ->assertSee('Set Up Now')
            ->assertSee($setupUrl, false);

        $this->actingAs($this->admin)
            ->get(route('super-admin.projects.show', $this->project->project_id))
            ->assertDontSee('Set Up Now')
            ->assertDontSee($setupUrl, false)
            ->assertSee('A Super Admin or Lead Technician must set it up.');

        $this->actingAs($this->techAccount)
            ->get(route('technician.projects.show', $this->project->project_id))
            ->assertDontSee('Set Up Now');
    }

    public function test_changes_are_disabled_on_the_page(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.projects.show', $this->project->project_id))
            ->assertSee('title="'.Project::SET_UP_FIRST.'"', false)
            ->assertSee('data-bs-target="#editProjectDetailsModal" disabled', false)
            ->assertSee('data-bs-target="#cancelProjectModal"', false);
    }

    public function test_the_endpoints_refuse_changes_until_setup(): void
    {
        $id = $this->project->project_id;

        $this->actingAs($this->superAdmin);

        $this->from(route('super-admin.projects.show', $id))
            ->put(route('super-admin.projects.hold', $id), ['on_hold_reason' => 'Waiting'])
            ->assertSessionHas('error', Project::SET_UP_FIRST);
        $this->assertFalse((bool) $this->project->fresh()->on_hold);

        $this->post(route('super-admin.projects.cancel', $id), ['cancellation_reason' => 'Mistake'])
            ->assertSessionHas('error', Project::SET_UP_FIRST);
        $this->assertSame('ongoing', $this->project->fresh()->status);

        $this->put(route('super-admin.projects.update', $id), ['name' => 'Changed'])
            ->assertSessionHas('error', Project::SET_UP_FIRST);
        $this->assertSame('Aircon Retrofit', $this->project->fresh()->name);

        $this->actingAs($this->leadAccount)
            ->post(route('technician.projects.complete', $id), [])
            ->assertSessionHas('error', Project::SET_UP_FIRST);
    }

    public function test_the_team_and_schedule_stay_open_before_setup(): void
    {
        $id = $this->project->project_id;
        $page = route('super-admin.projects.show', $id);
        $joiner = Technician::create(['account_id' => $this->account('technician', 'joiner@example.test')->id, 'role' => 'technician']);

        foreach ([$this->admin, $this->officer] as $account) {
            $this->actingAs($account)
                ->get($page)
                ->assertOk()
                ->assertSee('title="Edit assigned team"', false)
                ->assertSee(route('super-admin.schedules.index', ['openSchedule' => $id]), false);
        }

        $this->from($page)
            ->put(route('super-admin.projects.team.update', $id), [
                'lead_tech' => $this->leadAccount->technicianRecord()->technician_id,
                'technicians' => [$this->techAccount->technicianRecord()->technician_id, $joiner->technician_id],
            ])
            ->assertSessionHas('success');

        $this->assertTrue($this->project->projectTechnicians()->where('technician_id', $joiner->technician_id)->exists());
        $this->assertTrue($this->project->fresh()->awaitingInitialSetup());
    }

    public function test_the_gate_opens_once_set_up(): void
    {
        $this->finalizePhases($this->project);

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertDontSee('This project is not set up yet.')
            ->assertDontSee('data-bs-target="#editProjectDetailsModal" disabled', false);

        $this->from(route('super-admin.projects.show', $this->project->project_id))
            ->put(route('super-admin.projects.hold', $this->project->project_id), ['on_hold_reason' => 'Waiting'])
            ->assertSessionMissing('error');
    }

    public function test_the_removed_fields_are_gone_from_the_shipped_forms(): void
    {
        $labels = collect(DetailFormSeeder::forms())
            ->flatMap(fn (array $form): array => array_column($form['fields'], 'label'))
            ->map(fn (string $label): string => mb_strtolower($label));

        foreach (['model', 'equipment tag', 'indoor serial no.', 'outdoor serial no.', 'serial no.'] as $removed) {
            $this->assertNotContains($removed, $labels);
        }

        $this->assertContains('capacity (hp)', $labels);
        $this->assertContains('quantity', $labels);
    }

    private function account(string $role, ?string $email = null): User
    {
        $sequence = User::count() + 1;

        return User::create([
            'user_code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => 'Test Person '.$sequence,
            'first_name' => 'Test',
            'last_name' => 'Person '.$sequence,
            'email' => $email ?? $role.'@example.test',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);
    }
}
