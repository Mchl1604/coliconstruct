<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Whose project this is, at the top of Project Details.
 *
 * On commercial work the job belongs to the company - ABC Construction
 * Corporation - and the person named on it is whoever answers the phone about
 * it. On residential work there is nobody it could be but the person. So the
 * heading follows the stored client type rather than always printing the
 * individual.
 *
 * Nothing is renamed, nothing is dropped and nothing is written: both values
 * stay on screen, and only which of them leads changes. One rule decides it -
 * Client::primaryName() - so the two portals cannot disagree about the same
 * project.
 */
class ProjectDetailsClientHeadingTest extends TestCase
{
    use RefreshDatabase;

    private Technician $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
        $this->lead = $this->technician('Rita Lead', User::ROLE_LEAD_TECHNICIAN);
    }

    // ------------------------------------------------------------------
    // The rule
    // ------------------------------------------------------------------

    public function test_a_commercial_project_leads_with_the_company(): void
    {
        $client = $this->client('Commercial', 'ABC Construction Corporation', 'John Smith');

        $this->assertSame('ABC Construction Corporation', $client->primaryName());
        $this->assertSame('John Smith', $client->secondaryName());
        $this->assertSame('Project Contact', $client->secondaryLabel());
    }

    public function test_a_residential_project_leads_with_the_person(): void
    {
        $client = $this->client('Residential', null, 'John Smith');

        $this->assertSame('John Smith', $client->primaryName());
        $this->assertNull($client->secondaryName(), 'There is no company to say anything about.');
        $this->assertNull($client->secondaryLabel());
    }

    public function test_a_residential_project_that_carries_a_company_keeps_it_underneath(): void
    {
        // Recorded against residential work anyway. It is still true, so it
        // stays on screen - just not as the heading.
        $client = $this->client('Residential', 'Smith Holdings', 'John Smith');

        $this->assertSame('John Smith', $client->primaryName());
        $this->assertSame('Smith Holdings', $client->secondaryName());
        $this->assertSame('Company', $client->secondaryLabel());
    }

    public function test_a_commercial_project_with_no_company_recorded_falls_back_to_the_person(): void
    {
        // A heading is no place to report a blank field, and the person is
        // still a true answer to "whose project is this?".
        $client = $this->client('Commercial', null, 'John Smith');

        $this->assertSame('John Smith', $client->primaryName());
        $this->assertNull($client->secondaryName(), 'The same name must not be printed twice.');
    }

    public function test_the_client_type_is_read_however_it_is_cased(): void
    {
        // The stored value is what decides this, and it is free text.
        foreach (['Commercial', 'commercial', ' COMMERCIAL '] as $stored) {
            $client = $this->client($stored, 'ABC Construction Corporation', 'John Smith');

            $this->assertSame(
                'ABC Construction Corporation',
                $client->primaryName(),
                sprintf('A client_type of "%s" must still read as commercial.', $stored)
            );
        }
    }

    // ------------------------------------------------------------------
    // Both portals, same answer
    // ------------------------------------------------------------------

    public function test_every_portal_leads_with_the_company_on_commercial_work(): void
    {
        $project = $this->project('Commercial', 'ABC Construction Corporation', 'John Smith');

        foreach ($this->portals() as $where => [$account, $url]) {
            $body = $this->actingAs($account)->get($url($project))->assertOk()->getContent();

            $heading = $this->headingOf($body);

            $this->assertSame(
                'ABC Construction Corporation',
                $heading,
                $where.' does not lead with the company.'
            );

            // The person is still there, one step down and labelled.
            $this->assertStringContainsString('John Smith', $body, $where.' dropped the contact person.');
            $this->assertStringContainsString('Project Contact', $body, $where.' did not label the contact.');
        }
    }

    public function test_every_portal_leads_with_the_person_on_residential_work(): void
    {
        $project = $this->project('Residential', null, 'John Smith');

        foreach ($this->portals() as $where => [$account, $url]) {
            $body = $this->actingAs($account)->get($url($project))->assertOk()->getContent();

            $this->assertSame('John Smith', $this->headingOf($body), $where.' does not lead with the person.');
        }
    }

    public function test_neither_name_is_ever_dropped(): void
    {
        // The one thing that must not happen: promoting one of the pair by
        // losing the other.
        $project = $this->project('Commercial', 'ABC Construction Corporation', 'John Smith');

        foreach ($this->portals() as $where => [$account, $url]) {
            $body = $this->actingAs($account)->get($url($project))->assertOk()->getContent();

            $this->assertStringContainsString('ABC Construction Corporation', $body, $where);
            $this->assertStringContainsString('John Smith', $body, $where);
        }
    }

    public function test_the_stored_client_record_is_untouched_by_the_page(): void
    {
        $project = $this->project('Commercial', 'ABC Construction Corporation', 'John Smith');
        $before = $project->clients->first()->only([
            'client_type', 'company_name', 'fullname', 'firstname', 'surname',
        ]);

        $this->get(route('super-admin.projects.show', $project->project_id))->assertOk();
        $this->actingAs($this->lead->account)
            ->get(route('technician.projects.show', $project->project_id))
            ->assertOk();

        $this->assertSame(
            $before,
            $project->fresh()->clients->first()->only([
                'client_type', 'company_name', 'fullname', 'firstname', 'surname',
            ]),
            'This is a display change; the record must be exactly as it was.'
        );
    }

    public function test_the_edit_form_still_offers_both_fields_under_their_own_names(): void
    {
        // "Do not rename the company or individual fields": the editor still
        // posts company_name and the individual's parts as it always did.
        $project = $this->project('Commercial', 'ABC Construction Corporation', 'John Smith');

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('name="company_name"', false)
            ->assertSee('name="first_name"', false)
            ->assertSee('name="last_name"', false)
            ->assertSee('Company Name');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * The <h2> at the top of the card - the one thing on the page that says
     * whose project this is.
     */
    private function headingOf(string $html): string
    {
        // Found by its own hook rather than by being the first fw-bold <h2> on
        // the page - that one is the page title.
        $this->assertSame(
            1,
            preg_match('/<h2[^>]*data-project-client-name[^>]*>\s*(.*?)\s*<\/h2>/s', $html, $matches),
            'The client heading is missing from this page.'
        );

        return trim(html_entity_decode($matches[1]));
    }

    /**
     * Every portal that draws Project Details, with an account that may open
     * it and the route it lives at.
     *
     * @return array<string, array{0: User, 1: callable}>
     */
    private function portals(): array
    {
        return [
            'Super Admin' => [
                $this->administrator('super_admin'),
                fn (Project $project): string => route('super-admin.projects.show', $project->project_id),
            ],
            'Admin' => [
                $this->administrator('admin'),
                fn (Project $project): string => route('super-admin.projects.show', $project->project_id),
            ],
            'Lead Technician' => [
                $this->lead->account,
                fn (Project $project): string => route('technician.projects.show', $project->project_id),
            ],
        ];
    }

    /** @var array<string, User> */
    private array $administrators = [];

    private function administrator(string $role): User
    {
        if (isset($this->administrators[$role])) {
            return $this->administrators[$role];
        }

        $user = User::factory()->create([
            'name' => ucfirst($role).' Account',
            'email' => $role.'.viewer@example.test',
        ]);

        $user->forceFill(['role' => $role, 'status' => User::STATUS_ACTIVE])->save();

        return $this->administrators[$role] = $user;
    }

    private function technician(string $name, string $role = 'technician'): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => $role, 'status' => User::STATUS_ACTIVE])->save();

        return Technician::create(['account_id' => $user->id, 'role' => $role]);
    }

    /**
     * A client record on its own, for the rule itself.
     */
    private function client(string $type, ?string $company, string $person): Client
    {
        return $this->project($type, $company, $person)->clients->first();
    }

    /**
     * A readable project with one client, and the lead on the team so the
     * technician portal will open it.
     */
    private function project(string $type, ?string $company, string $person): Project
    {
        static $sequence = 0;

        $project = Project::create([
            'name' => $person,
            'reference_no' => 'REF-'.(++$sequence).'-'.strtoupper(substr(md5(microtime()), 0, 6)),
            'status' => 'ongoing',
            'address' => '1 Test Street',
            'description' => 'Work',
            'quotation' => 1000,
        ]);

        [$first, $last] = array_pad(explode(' ', $person, 2), 2, '');

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => $type,
            'company_name' => $company,
            'firstname' => $first,
            'surname' => $last,
            'fullname' => $person,
            'email_address' => 'client'.$project->project_id.'@example.test',
            'contact_number' => '09171234567',
        ]);

        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $this->lead->technician_id,
            'joined_at' => Schedule::businessToday()->subDays(30),
        ]);

        return $project->refresh();
    }
}
