<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\User;
use App\Services\SystemContentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Client accounts and the projects they are connected to.
 *
 * A project's CLIENT is the client record - a name, an address, a number. A
 * CLIENT ACCOUNT is an account on the public website, opened for a client on
 * record and linked to it (tbl_clients.user_id), which is what puts that
 * client's projects on the account's My Projects page.
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

        // One address is one client, so a second project for the same
        // contact is another project of the same client.
        $client = Client::query()->firstOrCreate(['email_address' => $contactEmail], [
            'user_id' => $registeredUser?->id,
            'client_type' => 'Commercial',
            'company_name' => 'Some Holdings',
            'firstname' => 'Contact',
            'surname' => 'Person',
            'fullname' => 'Contact Person',
            'contact_number' => '09123456789',
        ]);

        $project = Project::create([
            'client_id' => $client->client_id,
            'name' => 'Aircon Retrofit '.$sequence,
            'reference_no' => 'REF-RU-'.$sequence,
            'status' => 'ongoing',
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
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
    // No Linked Account panel
    // ------------------------------------------------------------------

    /**
     * An account follows its client: it is linked when it is opened, from
     * the client record, and a project changes hands through Change client.
     * So Project Details no longer links, unlinks or copies addresses itself.
     */
    public function test_project_details_has_no_linked_account_panel(): void
    {
        $admin = $this->account('admin', 'admin@example.test');
        $client = $this->account('client', 'owner@example.test', 'Owning');
        $project = $this->project('owner@example.test', $client);

        $this->actingAs($admin)
            ->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertDontSee('Linked account:')
            ->assertDontSee('editRegisteredUserModal', false)
            ->assertDontSee('Use account email')
            ->assertSee('Change client')
            // The client details themselves are still there.
            ->assertSee('Client Information')
            ->assertSee('Contact Person');
    }

    public function test_the_linking_endpoints_are_gone(): void
    {
        $this->assertFalse(app('router')->has('super-admin.projects.registered-user.update'));
        $this->assertFalse(app('router')->has('super-admin.projects.registered-user.destroy'));
        $this->assertFalse(app('router')->has('super-admin.projects.contact-email.account'));
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

        // Each row carries the key the shared status colours are painted from.
        foreach ($rows as $row) {
            $this->assertSame(Project::find($row['id'])->statusKey(), $row['status_key']);
        }
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

    public function test_a_client_account_cannot_read_the_projects_endpoint(): void
    {
        $client = $this->account('client', 'owner@example.test', 'Owning');

        $this->actingAs($client)
            ->getJson(route('super-admin.configuration.users.projects', $client->id))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Terminology
    // ------------------------------------------------------------------

    public function test_the_account_type_reads_as_client_account(): void
    {
        $client = $this->account('client', 'owner@example.test', 'Owning');

        $this->assertSame('Client Account', $client->roleLabel());
    }

    public function test_user_management_offers_the_client_account_type(): void
    {
        $admin = $this->account('admin', 'admin@example.test');

        $this->actingAs($admin)
            ->get(route('super-admin.configuration.index'))
            ->assertOk()
            ->assertSee('Client Accounts')
            ->assertDontSee('Registered User')
            ->assertSee('All employee and client accounts.');
    }

    public function test_creating_one_reports_it_as_a_client_account(): void
    {
        $admin = $this->account('admin', 'admin@example.test');

        $this->actingAs($admin)
            ->postJson(route('super-admin.configuration.users.clients.store'), [
                'client_id' => $this->bookedClient('new.person@example.test')->client_id,
                'birthdate' => CarbonImmutable::today()->subYears(30)->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Client account created.');

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
