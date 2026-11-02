<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectAddressSplitter;
use App\Support\LuzonLocations;
use App\Support\ProjectAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A project's address, part by part and restricted to Luzon: the list, the two
 * forms that take it, and the command that splits the old free-text ones.
 */
class ProjectAddressTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // The list
    // ------------------------------------------------------------------

    public function test_the_list_holds_luzon_and_nothing_else(): void
    {
        $provinces = LuzonLocations::provinces();

        foreach (['Metro Manila', 'Laguna', 'Benguet', 'Palawan', 'Sorsogon'] as $province) {
            $this->assertContains($province, $provinces);
        }

        foreach (['Cebu', 'Iloilo', 'Leyte', 'Davao del Sur', 'Bukidnon'] as $province) {
            $this->assertNotContains($province, $provinces);
        }

        $this->assertContains('City of Las Piñas', LuzonLocations::municipalities('Metro Manila'));
        $this->assertContains('Canlalay', LuzonLocations::barangays('Laguna', 'City of Biñan'));
    }

    public function test_the_next_list_down_is_served(): void
    {
        $this->actingAsSuperAdmin();

        $url = route('super-admin.projects.address-options');

        $this->getJson($url.'?province=Laguna')
            ->assertOk()
            ->assertJsonFragment(['City of Biñan']);

        $this->getJson($url.'?'.http_build_query(['province' => 'Laguna', 'municipality' => 'City of Biñan']))
            ->assertOk()
            ->assertJsonFragment(['Canlalay']);

        $this->getJson($url.'?province=Cebu')->assertOk()->assertExactJson(['options' => []]);
    }

    public function test_someone_outside_the_office_gets_no_list(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client)
            ->get(route('super-admin.projects.address-options', ['province' => 'Laguna']))
            ->assertRedirect();
    }

    // ------------------------------------------------------------------
    // The rules
    // ------------------------------------------------------------------

    public function test_only_a_place_inside_the_choice_before_it_passes(): void
    {
        $this->assertSame([], $this->problems($this->luzonAddress()));
        $this->assertSame([], $this->problems(['address_house_no' => ''] + $this->luzonAddress()));

        $this->assertContains('address_province', $this->problems(['address_province' => 'Cebu'] + $this->luzonAddress()));
        $this->assertContains('address_municipality', $this->problems(['address_municipality' => 'City of Tagaytay'] + $this->luzonAddress()));
        $this->assertSame(['address_barangay'], $this->problems(['address_barangay' => 'Nowhere'] + $this->luzonAddress()));
        $this->assertSame(['address_street'], $this->problems(['address_street' => ''] + $this->luzonAddress()));
    }

    public function test_the_address_line_is_composed_from_the_parts(): void
    {
        $this->assertSame(
            '12 Rizal St., Brgy. Canlalay, City of Biñan, Laguna',
            ProjectAddress::columns($this->luzonAddress())['address']
        );

        $this->assertSame(
            'Taft Ave., Barangay 1, City of Manila, Metro Manila',
            ProjectAddress::compose([
                'address_province' => 'Metro Manila',
                'address_municipality' => 'City of Manila',
                'address_barangay' => 'Barangay 1',
                'address_street' => 'Taft Ave.',
            ])
        );
    }

    // ------------------------------------------------------------------
    // The forms
    // ------------------------------------------------------------------

    public function test_the_wizard_asks_for_the_parts(): void
    {
        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.projects.create'))
            ->assertOk()
            ->assertSee('name="address_province"', false)
            ->assertSee('name="address_municipality"', false)
            ->assertSee('name="address_barangay"', false)
            ->assertSee('name="address_street"', false)
            ->assertSee('name="address_house_no"', false)
            ->assertSee('<option value="Laguna"', false)
            ->assertDontSee('<option value="Cebu"', false)
            ->assertDontSee('name="project_address"', false)
            ->assertSee('addressFields.js', false);
    }

    public function test_the_edit_dialog_opens_on_the_saved_address(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project(ProjectAddress::columns($this->luzonAddress()));

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('<option value="Laguna" selected', false)
            ->assertSee('<option value="City of Biñan" selected', false)
            ->assertSee('<option value="Canlalay" selected', false)
            ->assertSee('value="Rizal St."', false)
            ->assertDontSee('Current: 12 Rizal St.')
            ->assertDontSee('name="address" rows', false);
    }

    public function test_the_edit_dialog_shows_an_address_it_could_not_read(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project(['address' => 'Somewhere in Cebu']);

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('Current: Somewhere in Cebu');
    }

    // ------------------------------------------------------------------
    // Old addresses
    // ------------------------------------------------------------------

    public function test_a_full_old_address_is_read_into_its_parts(): void
    {
        $parts = app(ProjectAddressSplitter::class)->split('Blk 12 Lot 8, Lancaster New City, Brgy. Navarro, General Trias, Cavite');

        $this->assertSame('Cavite', $parts['address_province']);
        $this->assertSame('City of General Trias', $parts['address_municipality']);
        $this->assertSame('Navarro', $parts['address_barangay']);
        $this->assertSame('Blk 12 Lot 8', $parts['address_house_no']);
        $this->assertSame('Lancaster New City', $parts['address_street']);
        $this->assertSame('Blk 12 Lot 8 Lancaster New City, Brgy. Navarro, City of General Trias, Cavite', $parts['address']);
    }

    public function test_a_bare_house_number_is_taken_from_the_street(): void
    {
        $parts = app(ProjectAddressSplitter::class)->split('12 Rizal St., Canlalay, Binan City, Laguna');

        $this->assertSame('City of Biñan', $parts['address_municipality']);
        $this->assertSame('Canlalay', $parts['address_barangay']);
        $this->assertSame('12', $parts['address_house_no']);
        $this->assertSame('Rizal St.', $parts['address_street']);
    }

    public function test_a_metro_manila_city_needs_no_province(): void
    {
        $parts = app(ProjectAddressSplitter::class)->split('Commonwealth Ave., Batasan Hills, Quezon City');

        $this->assertSame('Metro Manila', $parts['address_province']);
        $this->assertSame('Quezon City', $parts['address_municipality']);
        $this->assertSame('Batasan Hills', $parts['address_barangay']);
    }

    public function test_an_address_short_of_a_barangay_keeps_its_text(): void
    {
        $address = '88 Acacia Ave., Ayala Westgrove Heights, Silang, Cavite';
        $parts = app(ProjectAddressSplitter::class)->split($address);

        $this->assertSame('Cavite', $parts['address_province']);
        $this->assertSame('Silang', $parts['address_municipality']);
        $this->assertNull($parts['address_barangay']);
        $this->assertSame($address, $parts['address']);
    }

    public function test_an_address_outside_luzon_is_left_alone(): void
    {
        $parts = app(ProjectAddressSplitter::class)->split('1 Colon St., Cebu City, Cebu');

        $this->assertNull($parts['address_province']);
        $this->assertSame('1 Colon St., Cebu City, Cebu', $parts['address']);
    }

    public function test_the_command_splits_what_it_can_and_leaves_updated_at_alone(): void
    {
        $full = $this->project(['address' => 'Rizal St., Canlalay, Binan City, Laguna']);
        $unread = $this->project(['address' => 'Somewhere in Cebu']);

        DB::table('tbl_projects')->update(['updated_at' => '2026-01-01 08:00:00']);

        $this->artisan('projects:split-addresses', ['--dry-run' => true])
            ->expectsOutput('1 of 2 addresses can be fully split.')
            ->assertSuccessful();

        $this->assertNull($full->fresh()->address_barangay);

        $this->artisan('projects:split-addresses', ['--force' => true])->assertSuccessful();

        $this->assertSame('Canlalay', $full->fresh()->address_barangay);
        $this->assertSame('Rizal St., Brgy. Canlalay, City of Biñan, Laguna', $full->fresh()->address);
        $this->assertSame('2026-01-01 08:00:00', $full->fresh()->updated_at->format('Y-m-d H:i:s'));
        $this->assertSame('Somewhere in Cebu', $unread->fresh()->address);

        // Run again: the split one is not touched, the other is still reported.
        $this->artisan('projects:split-addresses', ['--dry-run' => true])
            ->expectsOutput('0 of 1 addresses can be fully split.')
            ->assertSuccessful();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * @param  array<string, string>  $input
     * @return array<int, string>
     */
    private function problems(array $input): array
    {
        return validator($input, ProjectAddress::rules($input))->errors()->keys();
    }

    /**
     * @param  array<string, string|null>  $attributes
     */
    private function project(array $attributes): Project
    {
        return Project::create($attributes + [
            'client_id' => Client::factory()->create()->client_id,
            'name' => 'Aircon Installation',
            'reference_no' => 'REF-'.fake()->unique()->numberBetween(1000, 9999),
            'status' => 'ongoing',
            'description' => 'Description',
            'quotation' => 100000,
        ]);
    }
}
