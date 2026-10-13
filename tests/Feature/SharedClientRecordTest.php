<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use App\Services\ClientProjects;
use App\Services\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One client record behind several projects, and one person behind a client
 * record and its client account.
 *
 * The client details edited on Project Details are written to the client, so
 * doing it from any one of their projects reaches all of them - and the name,
 * number and company reach the client's account too, as a change on the
 * account reaches the client record.
 */
class SharedClientRecordTest extends TestCase
{
    use RefreshDatabase;

    private static int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
    }

    private function clientAccount(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);

        $user->forceFill(['role' => User::ROLE_CLIENT, 'status' => User::STATUS_ACTIVE] + $this->acceptedTerms())->save();

        return $user;
    }

    private function projectFor(Client $client): Project
    {
        $sequence = ++self::$sequence;

        return Project::create([
            'client_id' => $client->client_id,
            'name' => 'Shared Job '.$sequence,
            'reference_no' => 'REF-SHARED-'.$sequence,
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 1000,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function editPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'address' => 'Address',
            'contact_number' => '09123456789',
            'email_address' => 'maria@example.test',
            'quotation' => 1000,
            'project_description' => 'Description',
            'project_types' => [ProjectType::firstOrCreate(['type_name' => 'Roofing'])->type_id],
        ], $overrides);
    }

    public function test_editing_the_client_on_a_project_renames_their_account(): void
    {
        $account = $this->clientAccount('maria@example.test');
        $client = Client::factory()->commercial('Old Holdings')->create([
            'email_address' => 'maria@example.test',
            'user_id' => $account->id,
        ]);
        $project = $this->projectFor($client);

        $this->put(route('super-admin.projects.update', $project->project_id), $this->editPayload([
            'first_name' => 'Maria',
            'middle_initial' => 'L',
            'last_name' => 'Reyes',
            'company_name' => 'New Holdings',
            'contact_number' => '09998887777',
        ]))->assertSessionHasNoErrors();

        $account->refresh();

        $this->assertSame('Maria L Reyes', $account->fullName());
        $this->assertSame('09998887777', $account->contact_number);
        $this->assertSame('New Holdings', $account->company_name);
        // Never the sign-in address.
        $this->assertSame('maria@example.test', $account->email);
    }

    public function test_a_client_renaming_themselves_renames_every_project(): void
    {
        $account = $this->clientAccount('maria@example.test');
        $client = Client::factory()->create(['email_address' => 'maria@example.test', 'user_id' => $account->id]);
        $first = $this->projectFor($client);
        $second = $this->projectFor($client);

        $this->actingAs($account)->put(route('profile.information'), [
            'first_name' => 'Mariel',
            'middle_name' => 'C',
            'last_name' => 'Santos',
            'contact_number' => '09170001111',
            'email' => 'maria@example.test',
        ])->assertSessionHasNoErrors();

        foreach ([$first, $second] as $project) {
            $shared = $project->fresh()->client;

            $this->assertSame('Mariel C Santos', $shared->fullname);
            $this->assertSame('Mariel', $shared->firstname);
            $this->assertSame('Santos', $shared->surname);
            $this->assertSame('09170001111', $shared->contact_number);
        }
    }

    public function test_an_administrator_renaming_the_account_renames_the_client(): void
    {
        $account = $this->clientAccount('maria@example.test');
        $client = Client::factory()->create(['email_address' => 'maria@example.test', 'user_id' => $account->id]);

        $this->post(route('super-admin.configuration.users.clients.update', $account), [
            'first_name' => 'Marie',
            'last_name' => 'Cruz',
            'contact_number' => '09175550000',
        ])->assertOk();

        $this->assertSame('Marie Cruz', $client->fresh()->fullname);
        $this->assertSame('09175550000', $client->fresh()->contact_number);
    }

    public function test_a_commercial_client_sees_their_company_on_their_profile(): void
    {
        $account = $this->clientAccount('office@example.test');
        $account->forceFill(['company_name' => 'Acme Holdings'])->save();

        $this->actingAs($account)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Acme Holdings')
            ->assertSee('Contact us to change.');
    }

    public function test_editing_client_details_on_one_project_changes_them_on_all(): void
    {
        $client = Client::factory()->create(['email_address' => 'maria@example.test', 'fullname' => 'Old Name']);
        $first = $this->projectFor($client);
        $second = $this->projectFor($client);

        $this->put(route('super-admin.projects.update', $first->project_id), $this->editPayload([
            'contact_number' => '09998887777',
        ]))->assertSessionHasNoErrors();

        $shared = $second->fresh()->client;

        $this->assertSame($client->client_id, $shared->client_id);
        $this->assertSame('Maria Santos', $shared->fullname);
        $this->assertSame('09998887777', $shared->contact_number);
        $this->assertSame(1, Client::query()->count());
    }

    public function test_an_edit_may_keep_the_clients_own_address(): void
    {
        $client = Client::factory()->create(['email_address' => 'maria@example.test']);
        $project = $this->projectFor($client);

        $this->put(route('super-admin.projects.update', $project->project_id), $this->editPayload([
            'email_address' => ' MARIA@example.test',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('maria@example.test', $client->fresh()->email_address);
    }

    public function test_an_edit_cannot_take_another_clients_address(): void
    {
        Client::factory()->create(['email_address' => 'taken@example.test']);
        $client = Client::factory()->create(['email_address' => 'maria@example.test']);
        $project = $this->projectFor($client);

        $this->put(route('super-admin.projects.update', $project->project_id), $this->editPayload([
            'email_address' => 'Taken@example.test',
        ]))->assertSessionHasErrors(['email_address' => 'Client already exists.']);

        $this->assertSame('maria@example.test', $client->fresh()->email_address);
    }

    public function test_registering_claims_every_project_booked_under_the_address(): void
    {
        $client = Client::factory()->create(['email_address' => 'newcomer@example.test']);
        $this->projectFor($client);
        $this->projectFor($client);

        // An administrator opening the account, which claims the client
        // booked under that address - see UserAccountService.
        $account = app(UserAccountService::class)->createClient($client, [
            'birthdate' => '1990-01-01',
        ])['user'];

        $this->assertSame($account->id, $client->fresh()->user_id);
        $this->assertCount(2, app(ClientProjects::class)->forUser($account));
    }

    public function test_the_project_page_says_changes_reach_every_project(): void
    {
        $account = $this->clientAccount('maria@example.test');
        $project = $this->projectFor(Client::factory()->create([
            'email_address' => 'maria@example.test',
            'user_id' => $account->id,
        ]));

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('data-shared-client-note', false)
            ->assertSee('Applies to all their projects.');
    }
}
