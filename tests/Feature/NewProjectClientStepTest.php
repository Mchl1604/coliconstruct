<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Technician;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The New Project wizard's Client step: pick a client already on record, or
 * type a new one in - and never the same address twice.
 *
 * One client may have many projects. Existing client uses the stored client
 * as it is; New client creates one, and is refused when the address already
 * belongs to a client, both by the lookup behind Next and by the save itself.
 */
class NewProjectClientStepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();

        ProjectType::create(['type_name' => 'Aircon Installation']);
    }

    private function technician(string $role, string $name): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => $role])->save();

        return Technician::create(['account_id' => $user->id, 'role' => $role]);
    }

    /**
     * Everything the wizard posts, for a new client unless told otherwise.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $lead = $this->technician('lead_technician', 'Lead '.uniqid());
        $technician = $this->technician('technician', 'Tech '.uniqid());

        return array_merge([
            'client_mode' => 'new',
            'client_type' => 'Residential',
            'surname' => 'Dela Cruz',
            'firstname' => 'Juan',
            'middle_name' => 'S',
            'client_email' => 'juan.dela.cruz@example.test',
            'client_phone' => '09123456789',
            'project_address' => '123 Sample Street, Sample City',
            'quotation_amount' => '1250.00',
            'project_types' => ['Aircon Installation'],
            'assessment_report' => [UploadedFile::fake()->create('assessment.pdf', 12, 'application/pdf')],
            'approved_quotation' => [UploadedFile::fake()->create('quotation.pdf', 12, 'application/pdf')],
            'project_description' => 'Install two split-type units.',
            'target_end_date' => CarbonImmutable::today()->addDays(30)->toDateString(),
            'lead_tech' => $lead->technician_id,
            'technicians' => [$technician->technician_id],
            'start_date' => CarbonImmutable::today()->addDays(10)->toDateString(),
            'end_date' => CarbonImmutable::today()->addDays(12)->toDateString(),
        ], $overrides);
    }

    /**
     * A client already on record, with one project of theirs.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function existingClient(array $attributes = []): Client
    {
        $client = Client::factory()->create($attributes + [
            'firstname' => 'Maria',
            'surname' => 'Santos',
            'fullname' => 'Maria Santos',
            'email_address' => 'maria.santos@example.test',
            'contact_number' => '09998887777',
        ]);

        Project::create([
            'client_id' => $client->client_id,
            'name' => 'Earlier Job',
            'status' => 'completed',
            'address' => 'Address',
            'description' => 'Description',
        ]);

        return $client;
    }

    private function clientAccount(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);

        $user->forceFill(['role' => User::ROLE_CLIENT, 'status' => User::STATUS_ACTIVE] + $this->acceptedTerms())->save();

        return $user;
    }

    // ==================================================================
    // Existing client
    // ==================================================================

    public function test_an_existing_client_gets_the_project_and_keeps_their_details(): void
    {
        $client = $this->existingClient();

        $this->post(route('super-admin.projects.create.store'), $this->payload([
            'client_mode' => 'existing',
            'client_id' => $client->client_id,
            // Whatever the form carried for the client is ignored.
            'firstname' => 'Someone',
            'surname' => 'Else',
            'client_email' => 'someone.else@example.test',
            'client_phone' => '09000000000',
        ]))->assertRedirect(route('super-admin.projects'))->assertSessionHasNoErrors();

        $project = Project::query()->latest('project_id')->first();

        $this->assertSame($client->client_id, $project->client_id);
        $this->assertSame($project->reference_no.' - Maria Santos', $project->name);
        $this->assertSame(1, Client::query()->count());
        $this->assertSame(2, $client->projects()->count());

        $client->refresh();
        $this->assertSame('Maria Santos', $client->fullname);
        $this->assertSame('maria.santos@example.test', $client->email_address);
        $this->assertSame('09998887777', $client->contact_number);
    }

    public function test_one_client_can_be_picked_for_several_projects(): void
    {
        $client = $this->existingClient();

        foreach (range(1, 2) as $attempt) {
            $this->post(route('super-admin.projects.create.store'), $this->payload([
                'client_mode' => 'existing',
                'client_id' => $client->client_id,
            ]))->assertSessionHasNoErrors();
        }

        $this->assertSame(3, $client->projects()->count());
        $this->assertSame(1, Client::query()->count());
    }

    public function test_existing_client_mode_needs_a_client_picked(): void
    {
        $this->post(route('super-admin.projects.create.store'), $this->payload([
            'client_mode' => 'existing',
        ]))->assertSessionHasErrors(['client_id' => 'Pick a client.']);

        $this->assertSame(0, Project::query()->count());
    }

    public function test_a_client_that_no_longer_exists_is_refused(): void
    {
        $this->post(route('super-admin.projects.create.store'), $this->payload([
            'client_mode' => 'existing',
            'client_id' => 999,
        ]))->assertSessionHasErrors('client_id');

        $this->assertSame(0, Project::query()->count());
    }

    /**
     * The contract rule reads the stored client's type, not the form's.
     */
    public function test_an_existing_commercial_client_still_owes_a_contract(): void
    {
        $client = $this->existingClient(['client_type' => 'Commercial', 'company_name' => 'Acme Corp']);

        $this->post(route('super-admin.projects.create.store'), $this->payload([
            'client_mode' => 'existing',
            'client_id' => $client->client_id,
            'client_type' => 'Residential',
        ]))->assertSessionHasErrors('contract');

        $this->post(route('super-admin.projects.create.store'), $this->payload([
            'client_mode' => 'existing',
            'client_id' => $client->client_id,
            'contract' => [UploadedFile::fake()->create('contract.pdf', 12, 'application/pdf')],
        ]))->assertSessionHasNoErrors();

        $project = Project::query()->latest('project_id')->first();

        $this->assertSame($client->client_id, $project->client_id);
        // Commercial work is named after the company.
        $this->assertSame($project->reference_no.' - Acme Corp', $project->name);
        $this->assertDatabaseHas('tbl_documents', ['document_type' => 'contract']);
    }

    public function test_a_refused_save_reopens_with_the_same_client_picked(): void
    {
        $client = $this->existingClient();

        $this->from(route('super-admin.projects.create'))
            ->post(route('super-admin.projects.create.store'), $this->payload([
                'client_mode' => 'existing',
                'client_id' => $client->client_id,
                // Notes are optional now, so the refusal comes from the
                // quotation instead.
                'quotation_amount' => '-5',
            ]))
            ->assertRedirect(route('super-admin.projects.create'))
            ->assertSessionHasErrors('quotation_amount');

        $this->get(route('super-admin.projects.create'))
            ->assertOk()
            ->assertSee('id="clientModeExisting"', false)
            ->assertSee('name="client_id" value="'.$client->client_id.'"', false)
            ->assertSee('Maria Santos — maria.santos@example.test');
    }

    // ==================================================================
    // New client
    // ==================================================================

    public function test_a_new_client_and_their_project_are_created(): void
    {
        $this->post(route('super-admin.projects.create.store'), $this->payload([
            'client_email' => ' Juan.Dela.Cruz@Example.TEST ',
        ]))->assertRedirect(route('super-admin.projects'))->assertSessionHasNoErrors();

        $client = Client::query()->sole();

        // Stored the way it is compared.
        $this->assertSame('juan.dela.cruz@example.test', $client->email_address);
        $this->assertSame('Juan S Dela Cruz', $client->fullname);
        $this->assertSame($client->client_id, Project::query()->sole()->client_id);
    }

    public function test_the_form_defaults_to_a_new_client(): void
    {
        $payload = $this->payload();
        unset($payload['client_mode']);

        $this->post(route('super-admin.projects.create.store'), $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, Client::query()->count());
    }

    public function test_a_new_client_with_an_address_already_on_record_is_refused(): void
    {
        $this->existingClient();

        $this->post(route('super-admin.projects.create.store'), $this->payload([
            'client_email' => ' MARIA.Santos@example.test',
        ]))->assertSessionHasErrors(['client_email' => 'Client already exists.']);

        $this->assertSame(1, Project::query()->count());
        $this->assertSame(1, Client::query()->count());
    }

    /**
     * Names collide between different people, so a matching name alone is
     * not a duplicate.
     */
    public function test_a_new_client_sharing_only_a_name_is_accepted(): void
    {
        $this->existingClient();

        $this->post(route('super-admin.projects.create.store'), $this->payload([
            'firstname' => 'Maria',
            'surname' => 'Santos',
            'middle_name' => '',
            'client_email' => 'another.maria@example.test',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(2, Client::query()->count());
    }

    public function test_a_new_client_is_linked_to_the_account_behind_the_address(): void
    {
        $account = $this->clientAccount('juan.dela.cruz@example.test');

        $this->post(route('super-admin.projects.create.store'), $this->payload())->assertSessionHasNoErrors();

        $this->assertSame($account->id, Client::query()->sole()->user_id);
    }

    // ==================================================================
    // The lookup behind Next, and the picker's search
    // ==================================================================

    public function test_the_lookup_finds_an_address_however_it_is_typed(): void
    {
        $client = $this->existingClient();

        $this->getJson(route('super-admin.projects.client-lookup', ['email' => '  Maria.SANTOS@example.test ']))
            ->assertOk()
            ->assertJson([
                'exists' => true,
                'message' => 'Client already exists.',
                'client' => [
                    'id' => $client->client_id,
                    'name' => 'Maria Santos',
                    'email' => 'maria.santos@example.test',
                    'contact_number' => '09998887777',
                ],
            ]);
    }

    public function test_the_lookup_says_so_when_nobody_has_the_address(): void
    {
        $this->existingClient();

        $this->getJson(route('super-admin.projects.client-lookup', ['email' => 'nobody@example.test']))
            ->assertOk()
            ->assertExactJson(['exists' => false]);
    }

    public function test_the_search_returns_one_page_of_matches(): void
    {
        foreach (range(1, 10) as $number) {
            Client::factory()->create(['fullname' => 'Santos Number '.$number, 'surname' => 'Santos']);
        }

        Client::factory()->create(['fullname' => 'Pedro Penduko', 'email_address' => 'pedro@example.test']);

        $results = $this->getJson(route('super-admin.projects.client-search', ['q' => 'santos']))
            ->assertOk()
            ->json('results');

        $this->assertCount(8, $results);

        $this->getJson(route('super-admin.projects.client-search', ['q' => 'pedro@example']))
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.name', 'Pedro Penduko');
    }

    public function test_the_search_finds_a_company_by_its_name(): void
    {
        Client::factory()->commercial('Acme Holdings')->create(['fullname' => 'Contact Person']);

        $this->getJson(route('super-admin.projects.client-search', ['q' => 'acme']))
            ->assertOk()
            ->assertJsonPath('results.0.name', 'Acme Holdings')
            ->assertJsonPath('results.0.client_type', 'Commercial');
    }

    /**
     * Configuration's Add New User offers only clients who could still be
     * given an account; Change client leaves the current client out.
     */
    public function test_the_search_can_leave_clients_out(): void
    {
        $linked = Client::factory()->create([
            'fullname' => 'Santos Linked',
            'user_id' => $this->clientAccount('linked@example.test')->id,
        ]);
        $free = Client::factory()->create(['fullname' => 'Santos Free']);
        Client::factory()->create(['fullname' => 'Santos No Email', 'email_address' => null]);

        $withoutAccount = $this->getJson(route('super-admin.projects.client-search', ['q' => 'santos', 'without_account' => 1]))
            ->assertOk()
            ->json('results');

        $this->assertSame([$free->client_id], array_column($withoutAccount, 'id'));

        $excluding = $this->getJson(route('super-admin.projects.client-search', ['q' => 'santos', 'exclude' => $linked->client_id]))
            ->assertOk()
            ->json('results');

        $this->assertNotContains($linked->client_id, array_column($excluding, 'id'));
        $this->assertContains($free->client_id, array_column($excluding, 'id'));
    }

    public function test_a_client_account_cannot_search_clients(): void
    {
        $this->actingAs($this->clientAccount('nosy@example.test'));

        $this->getJson(route('super-admin.projects.client-search', ['q' => 'a']))->assertForbidden();
        $this->getJson(route('super-admin.projects.client-lookup', ['email' => 'a@example.test']))->assertForbidden();
    }

    // ==================================================================
    // The page and the client's own view
    // ==================================================================

    public function test_the_step_offers_both_modes_and_the_review_names_the_choice(): void
    {
        $this->get(route('super-admin.projects.create'))
            ->assertOk()
            ->assertSee('Existing client')
            ->assertSee('New client')
            ->assertSee('id="clientModeNew"', false)
            ->assertSee('data-picker-url="'.route('super-admin.projects.client-search').'"', false)
            ->assertSee('data-summary-target="client_mode"', false);
    }

    public function test_a_client_with_two_projects_sees_both_on_my_projects(): void
    {
        $account = $this->clientAccount('owner@example.test');
        $client = Client::factory()->create(['user_id' => $account->id, 'email_address' => 'owner@example.test']);

        $projects = collect(['REF-TWO-1', 'REF-TWO-2'])->map(fn (string $reference): Project => Project::create([
            'client_id' => $client->client_id,
            'reference_no' => $reference,
            'name' => 'Job '.$reference,
            'status' => 'pending',
            'address' => 'Address',
            'description' => 'Description',
        ]));

        $this->actingAs($account)
            ->get(route('public.projects'))
            ->assertOk()
            ->assertSee('REF-TWO-1')
            ->assertSee('REF-TWO-2');

        foreach ($projects as $project) {
            $this->actingAs($account)->get(route('public.projects.show', $project->project_id))->assertOk();
        }
    }
}
