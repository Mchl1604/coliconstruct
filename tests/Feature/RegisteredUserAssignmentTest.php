<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\Technician;
use App\Models\User;
use App\Services\ClientProjects;
use App\Services\SystemContentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which Registered User account a project is connected to.
 *
 * Two things are being pinned here and they are easy to confuse, which is the
 * whole reason for the tests. A project's CLIENT is contact information written
 * on the project - a name, an address, a number - and it is not touched by any
 * of this. A REGISTERED USER is an account on the public website, and the link
 * between the two is what puts a project on that person's My Projects page.
 *
 * The link itself is not new: tbl_clients.user_id has held it since accounts
 * were first tied to project contacts. What is new is an administrator being
 * able to set it, change it and clear it by hand, and every one of those has to
 * leave both records standing.
 */
class RegisteredUserAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private static int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::$sequence = 0;
    }

    private function account(string $role, string $email, string $first = 'Given'): User
    {
        $sequence = ++self::$sequence;

        return User::create([
            'user_code' => ($role === 'client' ? 'CLI-' : 'EMP-').str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => $first.' Surname'.$sequence,
            'first_name' => $first,
            'last_name' => 'Surname'.$sequence,
            'contact_number' => '09171234567',
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'terms_accepted_version' => app(SystemContentService::class)->termsVersion(),
            'terms_accepted_at' => now(),
            'password' => 'correct-password',
        ]);
    }

    private function project(string $contactEmail = 'contact@example.test', ?User $registeredUser = null): Project
    {
        $sequence = ++self::$sequence;

        $project = Project::create([
            'name' => 'Aircon Retrofit '.$sequence,
            'reference_no' => 'REF-RU-'.$sequence,
            'status' => 'ongoing',
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'user_id' => $registeredUser?->id,
            'client_type' => 'Commercial',
            'company_name' => 'Some Holdings',
            'firstname' => 'Contact',
            'surname' => 'Person',
            'fullname' => 'Contact Person',
            'email_address' => $contactEmail,
            'contact_number' => '09123456789',
        ]);

        Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => CarbonImmutable::today()->addDay()->toDateString().' 00:00:00',
            'end_datetime' => CarbonImmutable::today()->addDays(5)->toDateString().' 23:59:59',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'status' => 'scheduled',
        ]);

        return $project->refresh();
    }

    // ------------------------------------------------------------------
    // The panel on Project Details
    // ------------------------------------------------------------------

    public function test_project_details_names_the_assigned_registered_user(): void
    {
        $admin = $this->account('admin', 'admin@example.test');
        $client = $this->account('client', 'owner@example.test', 'Owning');
        $project = $this->project('owner@example.test', $client);

        $this->actingAs($admin)
            ->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('Registered User Account')
            ->assertSee($client->fullName())
            ->assertSee('owner@example.test')
            // The project's own client details are still there, under their
            // own name.
            ->assertSee('Client Information')
            ->assertSee('Contact Person');
    }

    public function test_project_details_says_so_when_nobody_is_assigned(): void
    {
        $admin = $this->account('admin', 'admin@example.test');
        $project = $this->project('nobody@example.test');

        $this->actingAs($admin)
            ->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('No Registered User Assigned');
    }

    // ------------------------------------------------------------------
    // Assigning, changing, removing
    // ------------------------------------------------------------------

    public function test_an_admin_assigns_a_registered_user_to_a_project(): void
    {
        $admin = $this->account('admin', 'admin@example.test');
        $client = $this->account('client', 'owner@example.test', 'Owning');
        $project = $this->project('someone.else@example.test');

        $this->actingAs($admin)
            ->put(route('super-admin.projects.registered-user.update', $project->project_id), [
                'registered_user_id' => $client->id,
            ])
            ->assertRedirect();

        $this->assertSame($client->id, $project->fresh()->registeredUserAccount()?->id);

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::REGISTERED_USER_ASSIGNED,
            'subject_id' => $client->id,
        ]);
    }

    public function test_a_super_admin_changes_the_assignment(): void
    {
        $superAdmin = $this->account('super_admin', 'owner@example.test');
        $first = $this->account('client', 'first@example.test', 'First');
        $second = $this->account('client', 'second@example.test', 'Second');
        $project = $this->project('first@example.test', $first);

        $this->actingAs($superAdmin)
            ->put(route('super-admin.projects.registered-user.update', $project->project_id), [
                'registered_user_id' => $second->id,
            ])
            ->assertRedirect();

        $this->assertSame($second->id, $project->fresh()->registeredUserAccount()?->id);

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::REGISTERED_USER_ASSIGNMENT_CHANGED,
            'subject_id' => $second->id,
        ]);

        // The account that lost the project keeps everything else it had, and
        // the project stops being theirs even though the contact row still
        // carries their address.
        $this->assertDatabaseHas('users', ['id' => $first->id, 'email' => 'first@example.test']);
        $this->assertTrue(app(ClientProjects::class)->forUser($first)->isEmpty());
        $this->assertSame(1, app(ClientProjects::class)->forUser($second)->count());
    }

    public function test_removing_the_assignment_keeps_both_records(): void
    {
        $admin = $this->account('admin', 'admin@example.test');
        $client = $this->account('client', 'owner@example.test', 'Owning');
        $project = $this->project('owner@example.test', $client);

        $this->actingAs($admin)
            ->delete(route('super-admin.projects.registered-user.destroy', $project->project_id))
            ->assertRedirect();

        $project = $project->fresh();

        $this->assertNull($project->registeredUserAccount());
        $this->assertDatabaseHas('users', ['id' => $client->id, 'email' => 'owner@example.test']);
        $this->assertDatabaseHas('tbl_projects', ['project_id' => $project->project_id]);
        // The contact details themselves are untouched.
        $this->assertDatabaseHas('tbl_clients', [
            'project_id' => $project->project_id,
            'fullname' => 'Contact Person',
            'email_address' => 'owner@example.test',
            'user_id' => null,
        ]);

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::REGISTERED_USER_REMOVED,
            'subject_id' => $client->id,
        ]);

        $this->actingAs($admin)
            ->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('No Registered User Assigned');
    }

    /**
     * The removal has to survive the address, which is the whole reason
     * user_unlinked_at exists: the contact row still carries the account's own
     * email, and the fallback match would otherwise hand the project straight
     * back on the next page load.
     */
    public function test_a_removed_assignment_is_not_undone_by_the_matching_address(): void
    {
        $admin = $this->account('admin', 'admin@example.test');
        $client = $this->account('client', 'owner@example.test', 'Owning');
        $project = $this->project('owner@example.test', $client);

        $this->assertSame(1, app(ClientProjects::class)->forUser($client)->count());

        $this->actingAs($admin)
            ->delete(route('super-admin.projects.registered-user.destroy', $project->project_id));

        $this->assertTrue(app(ClientProjects::class)->forUser($client->fresh())->isEmpty());
        $this->assertNull(app(ClientProjects::class)->findForUser($client->fresh(), $project->project_id));
    }

    public function test_assigning_the_same_account_twice_changes_nothing(): void
    {
        $admin = $this->account('admin', 'admin@example.test');
        $client = $this->account('client', 'owner@example.test', 'Owning');
        $project = $this->project('owner@example.test', $client);

        $this->actingAs($admin)
            ->put(route('super-admin.projects.registered-user.update', $project->project_id), [
                'registered_user_id' => $client->id,
            ])
            ->assertRedirect();

        $this->assertSame($client->id, $project->fresh()->registeredUserAccount()?->id);
        $this->assertDatabaseMissing('tbl_activity_logs', [
            'action' => ActivityLog::REGISTERED_USER_ASSIGNMENT_CHANGED,
        ]);
    }

    public function test_an_employee_account_cannot_be_assigned(): void
    {
        $admin = $this->account('admin', 'admin@example.test');
        $technician = $this->account('technician', 'tech@example.test');
        $project = $this->project();

        $this->actingAs($admin)
            ->put(route('super-admin.projects.registered-user.update', $project->project_id), [
                'registered_user_id' => $technician->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($project->fresh()->registeredUserAccount());
    }

    // ------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------

    public function test_a_technician_cannot_manage_the_assignment(): void
    {
        $account = $this->account('lead_technician', 'lead@example.test');

        Technician::create(['account_id' => $account->id, 'role' => 'lead_technician']);

        $client = $this->account('client', 'owner@example.test', 'Owning');
        $project = $this->project('owner@example.test', $client);

        $this->actingAs($account)
            ->putJson(route('super-admin.projects.registered-user.update', $project->project_id), [
                'registered_user_id' => $client->id,
            ])
            ->assertForbidden();

        $this->actingAs($account)
            ->deleteJson(route('super-admin.projects.registered-user.destroy', $project->project_id))
            ->assertForbidden();

        $this->assertSame($client->id, $project->fresh()->registeredUserAccount()?->id);
    }

    public function test_a_registered_user_cannot_manage_the_assignment(): void
    {
        $client = $this->account('client', 'owner@example.test', 'Owning');
        $other = $this->account('client', 'other@example.test', 'Other');
        $project = $this->project('owner@example.test', $client);

        $this->actingAs($client)
            ->putJson(route('super-admin.projects.registered-user.update', $project->project_id), [
                'registered_user_id' => $other->id,
            ])
            ->assertForbidden();

        $this->actingAs($client)
            ->deleteJson(route('super-admin.projects.registered-user.destroy', $project->project_id))
            ->assertForbidden();

        $this->assertSame($client->id, $project->fresh()->registeredUserAccount()?->id);
    }

    // ------------------------------------------------------------------
    // The other direction: a Registered User's projects
    // ------------------------------------------------------------------

    public function test_an_admin_reads_the_projects_connected_to_an_account(): void
    {
        $admin = $this->account('admin', 'admin@example.test');
        $client = $this->account('client', 'owner@example.test', 'Owning');

        $first = $this->project('owner@example.test', $client);
        $second = $this->project('owner@example.test', $client);

        $response = $this->actingAs($admin)
            ->getJson(route('super-admin.configuration.users.projects', $client->id))
            ->assertOk();

        $rows = $response->json('rows');

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [$first->project_id, $second->project_id],
            array_column($rows, 'id')
        );
        $this->assertSame($client->fullName(), $response->json('account.full_name'));
    }

    public function test_an_account_with_no_projects_comes_back_empty(): void
    {
        $superAdmin = $this->account('super_admin', 'owner@example.test');
        $client = $this->account('client', 'lonely@example.test', 'Lonely');

        $this->actingAs($superAdmin)
            ->getJson(route('super-admin.configuration.users.projects', $client->id))
            ->assertOk()
            ->assertJsonCount(0, 'rows');
    }

    public function test_the_projects_endpoint_refuses_an_employee_account(): void
    {
        $admin = $this->account('admin', 'admin@example.test');
        $technician = $this->account('technician', 'tech@example.test');

        $this->actingAs($admin)
            ->getJson(route('super-admin.configuration.users.projects', $technician->id))
            ->assertStatus(422);
    }

    public function test_a_registered_user_cannot_read_the_projects_endpoint(): void
    {
        $client = $this->account('client', 'owner@example.test', 'Owning');

        $this->actingAs($client)
            ->getJson(route('super-admin.configuration.users.projects', $client->id))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Terminology
    // ------------------------------------------------------------------

    public function test_the_account_type_reads_as_registered_user(): void
    {
        $client = $this->account('client', 'owner@example.test', 'Owning');

        $this->assertSame('Registered User', $client->roleLabel());
    }

    public function test_user_management_offers_the_registered_user_account_type(): void
    {
        $admin = $this->account('admin', 'admin@example.test');

        $this->actingAs($admin)
            ->get(route('super-admin.configuration.index'))
            ->assertOk()
            ->assertSee('Registered Users')
            ->assertSee('Every employee and Registered User account in the system.');
    }

    public function test_creating_one_reports_it_as_a_registered_user_account(): void
    {
        $admin = $this->account('admin', 'admin@example.test');

        $this->actingAs($admin)
            ->postJson(route('super-admin.configuration.users.clients.store'), [
                'full_name' => 'New Registered Person',
                'contact_number' => '09171234567',
                'birthdate' => CarbonImmutable::today()->subYears(30)->toDateString(),
                'email' => 'new.person@example.test',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Registered User account created.');

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::CLIENT_CREATED,
        ]);
    }

    /**
     * The project's own client field is a different thing and keeps its name.
     */
    public function test_the_projects_table_still_calls_the_project_contact_a_client(): void
    {
        $admin = $this->account('admin', 'admin@example.test');

        $this->project();

        $this->actingAs($admin)
            ->get(route('super-admin.projects'))
            ->assertOk()
            ->assertSee('<th>Client</th>', escape: false)
            ->assertSee('<th>Client Type</th>', escape: false);
    }
}
