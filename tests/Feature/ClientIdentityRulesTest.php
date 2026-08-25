<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Skill;
use App\Models\Technician;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Two rules about who a person on a record is, held to wherever a name or an
 * address is collected.
 *
 * A MIDDLE INITIAL is one letter. Four forms collect it - the profile page,
 * the account editor, the project wizard and a project's client details - and
 * three of them used to call it a middle name and accept a hundred characters
 * into a box the fourth had already narrowed to one. One rule now, stated in
 * PersonName, and one label.
 *
 * A PROJECT'S CLIENT is not a member of staff. The client email is the key the
 * whole client side is keyed on: it decides which account a project shows up
 * for, it is what a new client account is linked to the project by, and it is
 * where the project's own mail goes. Pointing it at an employee sends all
 * three to the wrong person, and leaves the real client unable to register the
 * address at all, because users.email is unique.
 */
class ClientIdentityRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /** @var array<string, Technician> */
    private array $crew = [];

    private function employee(string $role, string $email): User
    {
        $user = User::factory()->create(['name' => 'Staff Member', 'email' => $email]);
        $user->forceFill(['role' => $role, 'status' => User::STATUS_ACTIVE])->save();

        return $user->fresh();
    }

    private function technicianFor(User $user): Technician
    {
        return Technician::create(['account_id' => $user->id, 'role' => $user->role]);
    }

    /**
     * The project wizard's payload, complete enough to pass everything except
     * whatever one field a test is aiming at.
     *
     * @return array<string, mixed>
     */
    private function wizardPayload(array $overrides = []): array
    {
        ProjectType::firstOrCreate(['type_name' => 'Aircon Installation']);

        $skill = Skill::firstOrCreate(['skill_name' => 'Aircon Installation']);

        // Built once per test however many times the payload is asked for: a
        // test that submits the wizard twice is asking about the second field,
        // not for a second crew.
        $lead = $this->crew['lead'] ??= $this->technicianFor(
            $this->employee('lead_technician', 'lead@example.test')
        );
        $technician = $this->crew['technician'] ??= $this->technicianFor(
            $this->employee('technician', 'tech@example.test')
        );

        DB::table('tbl_skill_map')->insertOrIgnore([
            'technician_id' => $technician->technician_id,
            'skill_id' => $skill->skill_id,
        ]);

        return array_merge([
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
            'approved_quotation' => [UploadedFile::fake()->create('quotation.jpg', 12, 'image/jpeg')],
            'project_description' => 'Install two split-type units.',
            'lead_tech' => $lead->technician_id,
            'technicians' => [$technician->technician_id],
            'start_date' => CarbonImmutable::today()->addDays(10)->toDateString(),
            'end_date' => CarbonImmutable::today()->addDays(12)->toDateString(),
        ], $overrides);
    }

    private function createProject(array $overrides = [])
    {
        return $this->post(route('super-admin.projects.create.store'), $this->wizardPayload($overrides));
    }

    /**
     * A saved project with a client on it, for the edit form's tests.
     */
    private function projectWithClient(string $email = 'client@example.test'): Project
    {
        $project = Project::create([
            'name' => 'Client Project',
            'reference_no' => 'PRJ-CLIENT-1',
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 1000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Residential',
            'firstname' => 'Juan',
            'middlename' => 'S',
            'surname' => 'Dela Cruz',
            'fullname' => 'Juan S Dela Cruz',
            'email_address' => $email,
            'contact_number' => '09123456789',
        ]);

        return $project->fresh();
    }

    /**
     * The edit form on Project Details, which posts the client's details back
     * whole.
     *
     * @return array<string, mixed>
     */
    private function editPayload(Project $project, array $overrides = []): array
    {
        $type = ProjectType::firstOrCreate(['type_name' => 'Aircon Installation']);

        return array_merge([
            'first_name' => 'Juan',
            'middle_initial' => 'S',
            'last_name' => 'Dela Cruz',
            'address' => 'Address',
            'contact_number' => '09123456789',
            'email_address' => 'client@example.test',
            'quotation' => '1000',
            'project_description' => 'Description',
            'project_types' => [$type->type_id],
        ], $overrides);
    }

    // ==================================================================
    // The middle initial is one letter
    // ==================================================================

    public function test_the_wizard_refuses_a_middle_name_where_an_initial_belongs(): void
    {
        $this->createProject(['middle_name' => 'Santos'])
            ->assertSessionHasErrors('middle_name');

        $this->assertDatabaseCount('tbl_projects', 0);
    }

    public function test_the_wizard_takes_a_single_letter(): void
    {
        $this->createProject(['middle_name' => 'S'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tbl_clients', ['middlename' => 'S']);
    }

    /**
     * Optional, as it always was: not everybody has one.
     */
    public function test_the_middle_initial_may_be_left_empty(): void
    {
        $this->createProject(['middle_name' => ''])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('tbl_projects', 1);
    }

    /**
     * One character, and a letter. A digit or a full stop is the shape of the
     * value but not the substance of it.
     */
    public function test_a_single_character_that_is_not_a_letter_is_refused(): void
    {
        $this->createProject(['middle_name' => '1'])->assertSessionHasErrors('middle_name');
        $this->createProject(['middle_name' => '.'])->assertSessionHasErrors('middle_name');
    }

    public function test_the_project_edit_form_holds_the_client_to_one_letter(): void
    {
        $project = $this->projectWithClient();

        $this->put(
            route('super-admin.projects.update', $project->project_id),
            $this->editPayload($project, ['middle_initial' => 'Santos'])
        )->assertSessionHasErrors('middle_initial');

        $this->put(
            route('super-admin.projects.update', $project->project_id),
            $this->editPayload($project, ['middle_initial' => 'R'])
        )->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tbl_clients', [
            'project_id' => $project->project_id,
            'middlename' => 'R',
        ]);
    }

    public function test_an_employee_account_is_held_to_one_letter(): void
    {
        $this->postJson(route('super-admin.configuration.users.employees.store'), [
            'first_name' => 'Maria',
            'middle_name' => 'Santos',
            'last_name' => 'Reyes',
            'contact_number' => '09171234567',
            'birthdate' => CarbonImmutable::today()->subYears(30)->toDateString(),
            'email' => 'maria.reyes@example.test',
            'role' => 'admin',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'maria.reyes@example.test']);
    }

    public function test_a_person_editing_their_own_profile_is_held_to_one_letter(): void
    {
        $technician = $this->employee('technician', 'own.profile@example.test');
        $this->technicianFor($technician);

        $this->actingAs($technician)
            ->put(route('profile.information'), [
                'first_name' => 'Maria',
                'middle_name' => 'Santos',
                'last_name' => 'Reyes',
                'contact_number' => '09171234567',
                'email' => 'own.profile@example.test',
            ])
            ->assertSessionHasErrors(['middle_name'], null, 'information');
    }

    /**
     * And the forms say what they now mean. A box that takes one letter is a
     * middle initial, whatever it used to be called.
     */
    public function test_every_form_labels_the_field_middle_initial(): void
    {
        $project = $this->projectWithClient();

        $pages = [
            route('super-admin.projects.create'),
            route('super-admin.configuration.index'),
            route('profile.edit'),
            route('super-admin.projects.show', $project->project_id),
        ];

        foreach ($pages as $page) {
            $this->get($page)
                ->assertOk()
                ->assertSee('Middle Initial', false)
                // Not one of them still calls it a name.
                ->assertDontSee('Middle Name', false);
        }
    }

    // ==================================================================
    // A project's client is not a member of staff
    // ==================================================================

    /**
     * @return array<string, array<int, string>>
     */
    public static function employeeRoles(): array
    {
        return [
            'super admin' => ['super_admin'],
            'admin' => ['admin'],
            'lead technician' => ['lead_technician'],
            'technician' => ['technician'],
        ];
    }

    #[DataProvider('employeeRoles')]
    public function test_the_wizard_refuses_an_employees_address_as_a_clients(string $role): void
    {
        $this->employee($role, 'staff.member@example.test');

        $this->createProject(['client_email' => 'staff.member@example.test'])
            ->assertSessionHasErrors('client_email');

        $this->assertDatabaseCount('tbl_projects', 0);
    }

    /**
     * Case and stray whitespace are not a way past it: every other lookup of
     * an address in this system compares them normalised, and so does this.
     */
    public function test_the_check_is_not_fooled_by_casing_or_spacing(): void
    {
        $this->employee('technician', 'staff.member@example.test');

        $this->createProject(['client_email' => '  STAFF.Member@Example.TEST  '])
            ->assertSessionHasErrors('client_email');
    }

    /**
     * An archived employee still holds the address - archiving takes an
     * account off the active lists, it does not release its email - so a
     * client given it would hit the same wall on registering.
     */
    public function test_an_archived_employees_address_is_still_refused(): void
    {
        $staff = $this->employee('technician', 'former.staff@example.test');
        $staff->forceFill(['is_archived' => true, 'archived_at' => now()])->save();

        $this->createProject(['client_email' => 'former.staff@example.test'])
            ->assertSessionHasErrors('client_email');
    }

    /**
     * A client account's address is exactly what belongs here: it is how the
     * project reaches their portal.
     */
    public function test_a_client_accounts_address_is_accepted(): void
    {
        $client = User::factory()->create(['email' => 'real.client@example.test']);
        $client->forceFill(['role' => User::ROLE_CLIENT])->save();

        $this->createProject(['client_email' => 'real.client@example.test'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tbl_clients', ['email_address' => 'real.client@example.test']);
    }

    public function test_an_address_nobody_holds_is_accepted(): void
    {
        $this->createProject(['client_email' => 'brand.new@example.test'])
            ->assertSessionHasNoErrors();
    }

    public function test_the_project_edit_form_refuses_an_employees_address(): void
    {
        $this->employee('admin', 'the.admin@example.test');

        $project = $this->projectWithClient();

        $this->put(
            route('super-admin.projects.update', $project->project_id),
            $this->editPayload($project, ['email_address' => 'the.admin@example.test'])
        )->assertSessionHasErrors('email_address');

        $this->assertDatabaseHas('tbl_clients', [
            'project_id' => $project->project_id,
            'email_address' => 'client@example.test',
        ]);
    }

    public function test_the_project_edit_form_accepts_an_ordinary_address(): void
    {
        $project = $this->projectWithClient();

        $this->put(
            route('super-admin.projects.update', $project->project_id),
            $this->editPayload($project, ['email_address' => 'new.client@example.test'])
        )->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tbl_clients', [
            'project_id' => $project->project_id,
            'email_address' => 'new.client@example.test',
        ]);
    }
}
