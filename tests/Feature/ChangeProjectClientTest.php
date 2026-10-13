<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\User;
use App\Services\ClientProjects;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Project Details' Change client: moving one project to another client.
 *
 * Editing the client details on a project changes that client everywhere;
 * this is the other operation - the project is someone else's from now on.
 * The previous client keeps their record and their other projects.
 */
class ChangeProjectClientTest extends TestCase
{
    use RefreshDatabase;

    private static int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
    }

    private function projectFor(Client $client, array $attributes = []): Project
    {
        $sequence = ++self::$sequence;

        return Project::create($attributes + [
            'client_id' => $client->client_id,
            'name' => $client->primaryName(),
            'reference_no' => 'REF-CHANGE-'.$sequence,
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newClient(array $overrides = []): array
    {
        return array_merge([
            'client_mode' => 'new',
            'client_type' => 'Residential',
            'firstname' => 'Pedro',
            'middle_name' => 'P',
            'surname' => 'Penduko',
            'client_email' => 'pedro@example.test',
            'client_phone' => '09123456789',
        ], $overrides);
    }

    public function test_a_project_moves_to_an_existing_client(): void
    {
        $previous = Client::factory()->create(['fullname' => 'Old Owner']);
        $kept = $this->projectFor($previous);
        $project = $this->projectFor($previous);
        $next = Client::factory()->create(['fullname' => 'New Owner']);

        $this->put(route('super-admin.projects.client.change', $project->project_id), [
            'client_mode' => 'existing',
            'client_id' => $next->client_id,
        ])->assertRedirect(route('super-admin.projects.show', $project->project_id))
            ->assertSessionHas('success', 'Client changed to New Owner.');

        $project->refresh();

        $this->assertSame($next->client_id, $project->client_id);
        // Named after its client, as the wizard names a project.
        $this->assertSame('New Owner', $project->name);
        // The previous client keeps everything else.
        $this->assertSame($previous->client_id, $kept->fresh()->client_id);
        $this->assertSame('Old Owner', $previous->fresh()->fullname);

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::PROJECT_CLIENT_CHANGED,
            'record_id' => $project->project_id,
        ]);
    }

    public function test_a_project_named_by_hand_keeps_its_name(): void
    {
        $project = $this->projectFor(Client::factory()->create(), ['name' => 'Mall Expansion']);
        $next = Client::factory()->create();

        $this->put(route('super-admin.projects.client.change', $project->project_id), [
            'client_mode' => 'existing',
            'client_id' => $next->client_id,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Mall Expansion', $project->fresh()->name);
    }

    public function test_a_project_moves_to_a_new_client(): void
    {
        $project = $this->projectFor(Client::factory()->create());

        $this->put(route('super-admin.projects.client.change', $project->project_id), $this->newClient())
            ->assertSessionHasNoErrors();

        $client = $project->fresh()->client;

        $this->assertSame('Pedro P Penduko', $client->fullname);
        $this->assertSame('pedro@example.test', $client->email_address);
        $this->assertSame(2, Client::query()->count());
    }

    public function test_a_new_client_with_an_address_on_record_is_refused(): void
    {
        $project = $this->projectFor(Client::factory()->create());
        Client::factory()->create(['email_address' => 'pedro@example.test']);

        $this->put(route('super-admin.projects.client.change', $project->project_id), $this->newClient([
            'client_email' => 'PEDRO@example.test',
        ]))->assertSessionHasErrors(['client_email' => 'Client already exists.']);
    }

    public function test_the_current_client_is_not_a_change(): void
    {
        $client = Client::factory()->create();
        $project = $this->projectFor($client);

        $this->put(route('super-admin.projects.client.change', $project->project_id), [
            'client_mode' => 'existing',
            'client_id' => $client->client_id,
        ])->assertSessionHasErrors(['client_id' => 'Already this project\'s client.']);
    }

    public function test_a_client_must_be_picked(): void
    {
        $project = $this->projectFor(Client::factory()->create());

        $this->put(route('super-admin.projects.client.change', $project->project_id), [
            'client_mode' => 'existing',
        ])->assertSessionHasErrors(['client_id' => 'Pick a client.']);
    }

    public function test_finished_work_cannot_change_hands(): void
    {
        $previous = Client::factory()->create();
        $project = $this->projectFor($previous, ['status' => 'completed']);

        $this->put(route('super-admin.projects.client.change', $project->project_id), [
            'client_mode' => 'existing',
            'client_id' => Client::factory()->create()->client_id,
        ])->assertSessionHasErrors(['client' => 'This project can no longer be edited.']);

        $this->assertSame($previous->client_id, $project->fresh()->client_id);
    }

    public function test_partial_day_bookings_cannot_move_to_a_commercial_client(): void
    {
        $project = $this->projectFor(Client::factory()->create());

        Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => CarbonImmutable::today()->addDays(3)->setTime(9, 0),
            'end_datetime' => CarbonImmutable::today()->addDays(3)->setTime(11, 0),
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'status' => 'scheduled',
        ]);

        $this->put(route('super-admin.projects.client.change', $project->project_id), [
            'client_mode' => 'existing',
            'client_id' => Client::factory()->commercial()->create()->client_id,
        ])->assertSessionHasErrors(['client_id' => 'Commercial clients can\'t keep partial-day bookings.']);
    }

    public function test_the_new_client_sees_the_project_and_the_old_one_does_not(): void
    {
        $oldAccount = User::factory()->create(['email' => 'old@example.test']);
        $oldAccount->forceFill(['role' => User::ROLE_CLIENT, 'status' => User::STATUS_ACTIVE] + $this->acceptedTerms())->save();
        $newAccount = User::factory()->create(['email' => 'new@example.test']);
        $newAccount->forceFill(['role' => User::ROLE_CLIENT, 'status' => User::STATUS_ACTIVE] + $this->acceptedTerms())->save();

        $project = $this->projectFor(Client::factory()->create(['email_address' => 'old@example.test', 'user_id' => $oldAccount->id]));
        $next = Client::factory()->create(['email_address' => 'new@example.test', 'user_id' => $newAccount->id]);

        $this->put(route('super-admin.projects.client.change', $project->project_id), [
            'client_mode' => 'existing',
            'client_id' => $next->client_id,
        ])->assertSessionHasNoErrors();

        $this->actingAs($newAccount)->get(route('public.projects.show', $project->project_id))->assertOk();
        $this->assertNull(app(ClientProjects::class)->findForUser($oldAccount, $project->project_id));
    }

    public function test_only_administrators_may_change_the_client(): void
    {
        $project = $this->projectFor(Client::factory()->create());
        $technician = User::factory()->create();
        $technician->forceFill(['role' => User::ROLE_TECHNICIAN, 'status' => User::STATUS_ACTIVE])->save();

        $previous = $project->client_id;

        // Turned away at the administrative portal's door.
        $this->actingAs($technician)
            ->put(route('super-admin.projects.client.change', $project->project_id), [
                'client_mode' => 'existing',
                'client_id' => Client::factory()->create()->client_id,
            ])->assertRedirect();

        $this->assertSame($previous, $project->fresh()->client_id);
    }

    public function test_the_project_page_offers_change_client_while_the_work_is_live(): void
    {
        $live = $this->projectFor(Client::factory()->create());
        $finished = $this->projectFor(Client::factory()->create(), ['status' => 'completed']);

        $this->get(route('super-admin.projects.show', $live->project_id))
            ->assertOk()
            ->assertSee('id="changeClientModal"', false)
            ->assertSee(route('super-admin.projects.client-search', ['exclude' => $live->client_id]), false);

        $this->get(route('super-admin.projects.show', $finished->project_id))
            ->assertOk()
            ->assertDontSee('id="changeClientModal"', false);
    }
}
