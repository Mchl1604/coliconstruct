<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Skill;
use App\Models\Technician;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Configuration > User Management: the two account tables, the creation
 * workflows, and every action a row offers.
 */
class ConfigurationUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every administrative route is behind `auth` and a role check.
        $this->actingAsSuperAdmin();
    }

    private function skill(string $name): Skill
    {
        return Skill::create(['skill_name' => $name]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function employeePayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ana',
            'middle_name' => 'B',
            'last_name' => 'Mendoza',
            'contact_number' => '0917 555 1234',
            'birthdate' => '1990-05-04',
            'email' => 'ana.mendoza@example.test',
            'role' => 'admin',
        ], $overrides);
    }

    /**
     * A new client account is opened with only these; the company details
     * are added afterwards, from the edit form.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function clientPayload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Jose Garcia',
            'contact_number' => '09175551234',
            'birthdate' => '1988-11-20',
            'email' => 'jose.garcia@example.test',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEmployee(string $role = 'admin', array $overrides = []): User
    {
        $sequence = User::count() + 1;

        return User::create(array_merge([
            'user_code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => 'Existing Employee',
            'first_name' => 'Existing',
            'last_name' => 'Employee',
            'email' => 'employee'.$sequence.'@example.test',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'secret-password',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeClient(array $overrides = []): User
    {
        return $this->makeEmployee('client', array_merge([
            'company_name' => 'Some Company',
            'company_address' => 'Some Address',
        ], $overrides));
    }

    // ------------------------------------------------------------------
    // Page
    // ------------------------------------------------------------------

    public function test_the_page_renders_all_three_tabs(): void
    {
        $response = $this->get(route('super-admin.configuration.index'));

        $response->assertOk();
        $response->assertSee('User Management');
        $response->assertSee('Add New User');
        $response->assertSee('Activity Logs');
        $response->assertSee('Audit Trail');
        $response->assertSee('System Settings');
        // System Contents is a section of System Settings rather than a tab
        // of its own.
        $response->assertSee('System Contents');
    }

    // ------------------------------------------------------------------
    // Tables
    // ------------------------------------------------------------------

    public function test_the_two_tables_only_show_their_own_category(): void
    {
        $this->makeEmployee('admin');
        $this->makeClient();

        // Filtered to Admin so the signed-in super admin, which is itself an
        // employee account, does not join the count.
        $employees = $this->getJson(route('super-admin.configuration.users.employees', ['role' => 'admin']));
        $employees->assertOk();
        $employees->assertJsonCount(1, 'rows');
        $employees->assertJsonPath('rows.0.role_label', 'Admin');

        $clients = $this->getJson(route('super-admin.configuration.users.clients'));
        $clients->assertOk();
        $clients->assertJsonCount(1, 'rows');
        // The fixture's default name - what matters is that the one row here
        // is the client and not the employee above it.
        $clients->assertJsonPath('rows.0.full_name', 'Existing Employee');

        // The company name is not a column on this table; the picture is.
        $clients->assertJsonMissingPath('rows.0.company_name');
        $this->assertArrayHasKey('avatar_url', $clients->json('rows.0'));
    }

    public function test_employees_can_be_searched_and_filtered(): void
    {
        $this->makeEmployee('admin', [
            'name' => 'Ana Mendoza',
            'first_name' => 'Ana',
            'last_name' => 'Mendoza',
            'email' => 'ana@example.test',
            'user_code' => 'EMP-0101',
        ]);

        $this->makeEmployee('technician', [
            'name' => 'Jose Garcia',
            'first_name' => 'Jose',
            'last_name' => 'Garcia',
            'email' => 'jose@example.test',
            'user_code' => 'EMP-0102',
            'status' => User::STATUS_DEACTIVATED,
        ]);

        // Search reaches the user code, the name and the email alike.
        foreach (['EMP-0101' => 1, 'Mendoza' => 1, 'jose@example' => 1, 'example.test' => 2] as $term => $expected) {
            $response = $this->getJson(route('super-admin.configuration.users.employees', ['search' => $term]));
            $response->assertOk();
            $response->assertJsonCount($expected, 'rows');
        }

        $byRole = $this->getJson(route('super-admin.configuration.users.employees', ['role' => 'technician']));
        $byRole->assertOk();
        $byRole->assertJsonCount(1, 'rows');
        $byRole->assertJsonPath('rows.0.full_name', 'Jose Garcia');

        // Searched as well as filtered, so the signed-in super admin - whose
        // address is on another domain - stays out of the comparison.
        $byStatus = $this->getJson(route('super-admin.configuration.users.employees', [
            'status' => 'active',
            'search' => 'example.test',
        ]));
        $byStatus->assertOk();
        $byStatus->assertJsonCount(1, 'rows');
        $byStatus->assertJsonPath('rows.0.full_name', 'Ana Mendoza');
    }

    public function test_clients_can_be_searched_by_company_name(): void
    {
        $this->makeClient([
            'first_name' => 'Jose',
            'last_name' => 'Garcia',
            'company_name' => 'Garcia Holdings',
        ]);
        $this->makeClient([
            'first_name' => 'Ana',
            'last_name' => 'Mendoza',
            'company_name' => 'Mendoza Builders',
        ]);

        $response = $this->getJson(route('super-admin.configuration.users.clients', ['search' => 'Holdings']));

        $response->assertOk();
        $response->assertJsonCount(1, 'rows');
        // The company name is no longer a column, but it is still searchable -
        // somebody who knows only the company can still find the person.
        $response->assertJsonPath('rows.0.full_name', 'Jose Garcia');
    }

    /**
     * The tables page in SQL, so the response never grows with the account
     * count however many there are.
     */
    public function test_the_employee_table_is_paginated(): void
    {
        for ($index = 0; $index < 13; $index++) {
            $this->makeEmployee('technician');
        }

        $first = $this->getJson(route('super-admin.configuration.users.employees'));
        $first->assertOk();
        $first->assertJsonCount(10, 'rows');
        // 13 created, plus the signed-in super admin.
        $first->assertJsonPath('meta.total', 14);
        $first->assertJsonPath('meta.last_page', 2);

        $second = $this->getJson(route('super-admin.configuration.users.employees', ['page' => 2]));
        $second->assertOk();
        $second->assertJsonCount(4, 'rows');
    }

    // ------------------------------------------------------------------
    // Creating an employee
    // ------------------------------------------------------------------

    public function test_creating_an_employee_generates_a_code_and_a_temporary_password(): void
    {
        $response = $this->postJson(
            route('super-admin.configuration.users.employees.store'),
            $this->employeePayload()
        );

        $response->assertCreated();

        $user = User::where('email', 'ana.mendoza@example.test')->firstOrFail();

        $this->assertSame('Ana B Mendoza', $user->name);
        $this->assertStringStartsWith('EMP-', (string) $user->user_code);
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertFalse($user->is_archived);
        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->canLogin());

        // The password is returned once, and it is the one that actually works.
        $password = $response->json('password');
        $this->assertNotEmpty($password);
        $this->assertTrue(Hash::check($password, $user->password));

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::EMPLOYEE_CREATED,
            'subject_id' => $user->id,
        ]);
    }

    public function test_user_codes_increment_without_repeating(): void
    {
        $this->postJson(route('super-admin.configuration.users.employees.store'), $this->employeePayload())
            ->assertCreated();

        $this->postJson(route('super-admin.configuration.users.employees.store'), $this->employeePayload([
            'email' => 'second@example.test',
        ]))->assertCreated();

        $codes = User::employees()->orderBy('id')->pluck('user_code');

        $this->assertSame($codes->unique()->count(), $codes->count());
        // EMP-0001 is the signed-in super admin, so these are 2 and 3.
        $this->assertSame('EMP-0003', $codes->last());
    }

    public function test_a_technician_must_be_given_at_least_one_specialty(): void
    {
        $response = $this->postJson(
            route('super-admin.configuration.users.employees.store'),
            $this->employeePayload(['role' => 'technician'])
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('at least one specialty', $response->json('error'));
        $this->assertSame(0, User::where('email', 'ana.mendoza@example.test')->count());
    }

    public function test_a_technician_gets_a_technician_record_and_its_specialties(): void
    {
        $wiring = $this->skill('Electrical Wiring');
        $aircon = $this->skill('Aircon Repair');

        $response = $this->postJson(
            route('super-admin.configuration.users.employees.store'),
            $this->employeePayload([
                'role' => 'lead_technician',
                // The same specialty twice must not become two assignments.
                'skill_ids' => [$wiring->skill_id, $aircon->skill_id, $wiring->skill_id],
            ])
        );

        $response->assertCreated();

        $user = User::where('email', 'ana.mendoza@example.test')->firstOrFail();
        $technician = Technician::where('account_id', $user->id)->firstOrFail();

        $this->assertSame('lead_technician', $technician->role);
        $this->assertSame(2, $technician->skills()->count());
        $this->assertEqualsCanonicalizing(
            ['Aircon Repair', 'Electrical Wiring'],
            $technician->skills->pluck('skill_name')->all()
        );
    }

    public function test_an_employee_email_must_be_unique(): void
    {
        $this->makeEmployee('admin', ['email' => 'taken@example.test']);

        $response = $this->postJson(
            route('super-admin.configuration.users.employees.store'),
            $this->employeePayload(['email' => 'taken@example.test'])
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('already uses that email', $response->json('error'));
    }

    /**
     * The minimum age applies to an account an administrator opens exactly as
     * it does to one somebody registers for themselves - and to both kinds of
     * account, employee and client.
     */
    public function test_an_account_cannot_be_opened_for_anybody_under_eighteen(): void
    {
        $underage = CarbonImmutable::today()->subYears(17)->toDateString();

        $employee = $this->postJson(
            route('super-admin.configuration.users.employees.store'),
            $this->employeePayload(['birthdate' => $underage])
        );

        $employee->assertStatus(422);
        $this->assertStringContainsString('at least 18 years old', $employee->json('error'));

        $client = $this->postJson(
            route('super-admin.configuration.users.clients.store'),
            $this->clientPayload(['birthdate' => $underage])
        );

        $client->assertStatus(422);
        $this->assertStringContainsString('at least 18 years old', $client->json('error'));

        $this->assertSame(0, User::where('email', 'ana.mendoza@example.test')->count());
        $this->assertSame(0, User::where('email', 'jose.garcia@example.test')->count());
    }

    /**
     * A missing birthdate is refused too, so the check cannot be skipped by
     * leaving the field out of the request.
     */
    public function test_an_account_cannot_be_opened_without_a_birthdate(): void
    {
        foreach ([
            'super-admin.configuration.users.employees.store' => $this->employeePayload(['birthdate' => '']),
            'super-admin.configuration.users.clients.store' => $this->clientPayload(['birthdate' => '']),
        ] as $route => $payload) {
            $this->postJson(route($route), $payload)->assertStatus(422);
        }

        $this->assertSame(0, User::where('email', 'ana.mendoza@example.test')->count());
        $this->assertSame(0, User::where('email', 'jose.garcia@example.test')->count());
    }

    /**
     * The date is stored and comes back on the record behind the edit form,
     * in the format a date input expects.
     */
    public function test_a_birthdate_is_stored_and_returned_for_editing(): void
    {
        $this->postJson(
            route('super-admin.configuration.users.employees.store'),
            $this->employeePayload(['birthdate' => '1990-05-04'])
        )->assertCreated();

        $user = User::where('email', 'ana.mendoza@example.test')->firstOrFail();

        $this->assertSame('1990-05-04', $user->birthdate->toDateString());

        $this->getJson(route('super-admin.configuration.users.show', $user->id))
            ->assertOk()
            ->assertJsonPath('account.birthdate', '1990-05-04');
    }

    public function test_an_invalid_contact_number_is_rejected(): void
    {
        $response = $this->postJson(
            route('super-admin.configuration.users.employees.store'),
            $this->employeePayload(['contact_number' => 'not-a-number'])
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('valid contact number', $response->json('error'));
    }

    public function test_required_employee_fields_are_enforced(): void
    {
        $response = $this->postJson(route('super-admin.configuration.users.employees.store'), []);

        $response->assertStatus(422);
        // Only the signed-in super admin, so nothing was created.
        $this->assertSame(1, User::employees()->count());
    }

    // ------------------------------------------------------------------
    // Creating a client
    // ------------------------------------------------------------------

    public function test_creating_a_client_makes_an_active_account_with_no_projects(): void
    {
        $response = $this->postJson(
            route('super-admin.configuration.users.clients.store'),
            $this->clientPayload()
        );

        $response->assertCreated();

        $user = User::where('email', 'jose.garcia@example.test')->firstOrFail();

        $this->assertSame(User::ROLE_CLIENT, $user->role);
        // The company details are not asked for at this point.
        $this->assertNull($user->company_name);
        $this->assertNull($user->company_address);
        $this->assertStringStartsWith('CLI-', (string) $user->user_code);
        $this->assertTrue($user->canLogin());
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check($response->json('password'), $user->password));

        // It appears in the clients table straight away, with no project work.
        $this->getJson(route('super-admin.configuration.users.clients'))
            ->assertOk()
            ->assertJsonPath('rows.0.user_code', $user->user_code);

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::CLIENT_CREATED,
            'subject_id' => $user->id,
        ]);
    }

    public function test_a_client_still_requires_a_name_number_and_email(): void
    {
        foreach (['full_name', 'contact_number', 'email'] as $field) {
            $response = $this->postJson(
                route('super-admin.configuration.users.clients.store'),
                $this->clientPayload([$field => ''])
            );

            $response->assertStatus(422);
        }

        $this->assertSame(0, User::clients()->count());
    }

    /**
     * The company details are not on any form, so an edit leaves whatever is
     * already stored against the account alone.
     */
    public function test_a_client_edit_leaves_the_stored_company_details_alone(): void
    {
        $client = $this->makeClient(['company_name' => 'Garcia Holdings']);

        $this->post(
            route('super-admin.configuration.users.clients.update', $client),
            $this->clientPayload(['company_name' => 'Renamed Holdings'])
        )->assertOk();

        $this->assertSame('Garcia Holdings', $client->refresh()->company_name);
    }

    // ------------------------------------------------------------------
    // Editing
    // ------------------------------------------------------------------

    public function test_editing_an_employee_changes_their_role_and_keeps_the_code(): void
    {
        $skill = $this->skill('Electrical Wiring');
        $employee = $this->makeEmployee('admin');
        $originalCode = $employee->user_code;

        $response = $this->post(
            route('super-admin.configuration.users.employees.update', $employee),
            $this->employeePayload([
                'email' => $employee->email,
                'role' => 'technician',
                'skill_ids' => [$skill->skill_id],
            ])
        );

        $response->assertOk();

        $employee->refresh();

        $this->assertSame('technician', $employee->role);
        $this->assertSame('Ana B Mendoza', $employee->name);
        // The identifier is immutable however the account is edited.
        $this->assertSame($originalCode, $employee->user_code);
        $this->assertSame(1, Technician::where('account_id', $employee->id)->count());

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::EMPLOYEE_UPDATED,
            'subject_id' => $employee->id,
        ]);
    }

    /**
     * Losing a technician role must not take the technician record with it -
     * project assignments and reports still point at it.
     */
    public function test_moving_off_a_technician_role_preserves_the_assignment_history(): void
    {
        $skill = $this->skill('Electrical Wiring');
        $employee = $this->makeEmployee('technician');

        $technician = Technician::create(['account_id' => $employee->id, 'role' => 'technician']);
        $technician->skills()->attach($skill->skill_id);

        $project = Project::create([
            'name' => 'Some Project',
            'reference_no' => 'REF-0001',
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 100000,
        ]);

        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);

        $this->post(
            route('super-admin.configuration.users.employees.update', $employee),
            $this->employeePayload(['email' => $employee->email, 'role' => 'admin'])
        )->assertOk();

        $this->assertDatabaseHas('tbl_technicians', ['technician_id' => $technician->technician_id]);
        $this->assertDatabaseHas('tbl_project_technicians', [
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);
    }

    public function test_a_client_edit_cannot_move_the_login_email(): void
    {
        $client = $this->makeClient(['email' => 'original@example.test']);

        $response = $this->post(
            route('super-admin.configuration.users.clients.update', $client),
            $this->clientPayload(['email' => 'sneaky@example.test'])
        );

        $response->assertOk();

        // The email field is simply not part of this form.
        $this->assertSame('original@example.test', $client->refresh()->email);
        $this->assertSame('Jose Garcia', $client->name);
    }

    public function test_an_archived_account_cannot_be_edited(): void
    {
        $employee = $this->makeEmployee('admin');

        $this->deleteJson(route('super-admin.configuration.users.archive', $employee))->assertOk();

        $response = $this->post(
            route('super-admin.configuration.users.employees.update', $employee),
            $this->employeePayload(['email' => $employee->email])
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('Archived accounts cannot be edited', $response->json('error'));
    }

    // ------------------------------------------------------------------
    // Super Admin
    // ------------------------------------------------------------------

    /**
     * Super Admin is granted in the database, never handed out by the form.
     */
    public function test_super_admin_cannot_be_assigned_from_the_form(): void
    {
        $response = $this->postJson(
            route('super-admin.configuration.users.employees.store'),
            $this->employeePayload(['role' => 'super_admin'])
        );

        $response->assertStatus(422);
        // Only the signed-in one, which the form did not create.
        $this->assertSame(1, User::where('role', 'super_admin')->count());
    }

    /**
     * Editing the system owner must not quietly demote them, whatever the
     * form sends for a role.
     */
    public function test_editing_a_super_admin_keeps_their_role(): void
    {
        $owner = $this->makeEmployee('super_admin');

        $this->post(
            route('super-admin.configuration.users.employees.update', $owner),
            $this->employeePayload(['email' => $owner->email, 'role' => 'technician'])
        )->assertOk();

        $owner->refresh();

        $this->assertSame('super_admin', $owner->role);
        // The rest of the edit still applied.
        $this->assertSame('Ana B Mendoza', $owner->name);
    }

    public function test_a_super_admin_is_still_listed_and_filterable(): void
    {
        // The signed-in super admin is the one being looked for here.
        $this->getJson(route('super-admin.configuration.users.employees', ['role' => 'super_admin']))
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.role_label', 'Super Admin');
    }

    // ------------------------------------------------------------------
    // Password reset
    // ------------------------------------------------------------------

    public function test_resetting_a_password_issues_a_new_one_and_retires_the_old(): void
    {
        $client = $this->makeClient(['password' => 'the-old-password']);

        $response = $this->putJson(route('super-admin.configuration.users.password.reset', $client));

        $response->assertOk();

        $password = $response->json('password');
        $client->refresh();

        $this->assertNotEmpty($password);
        $this->assertTrue(Hash::check($password, $client->password));
        $this->assertFalse(Hash::check('the-old-password', $client->password));
        $this->assertTrue($client->must_change_password);

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::CLIENT_PASSWORD_RESET,
            'subject_id' => $client->id,
        ]);
    }

    /**
     * The same action serves the employee table, and is logged as an employee
     * reset rather than borrowing the client wording.
     */
    public function test_an_employee_password_can_be_reset(): void
    {
        $employee = $this->makeEmployee('lead_technician', ['password' => 'the-old-password']);

        $response = $this->putJson(route('super-admin.configuration.users.password.reset', $employee));

        $response->assertOk();

        $password = $response->json('password');
        $employee->refresh();

        $this->assertNotEmpty($password);
        $this->assertTrue(Hash::check($password, $employee->password));
        $this->assertFalse(Hash::check('the-old-password', $employee->password));
        $this->assertTrue($employee->must_change_password);

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::EMPLOYEE_PASSWORD_RESET,
            'subject_id' => $employee->id,
        ]);
    }

    /**
     * An Admin shares this page with the Super Admin, so the reset has to be
     * available to them too.
     */
    public function test_an_admin_may_reset_another_employees_password(): void
    {
        $admin = $this->makeEmployee('admin');
        $technician = $this->makeEmployee('technician', ['password' => 'the-old-password']);

        $this->actingAs($admin);

        $this->putJson(route('super-admin.configuration.users.password.reset', $technician))
            ->assertOk();

        $this->assertFalse(Hash::check('the-old-password', $technician->refresh()->password));
    }

    /**
     * A reset hands the new password straight back to whoever asked for it, so
     * letting an Admin reset the owner's account would be a takeover.
     */
    public function test_an_admin_cannot_reset_a_super_admins_password(): void
    {
        $admin = $this->makeEmployee('admin');
        $owner = $this->makeEmployee('super_admin', ['password' => 'the-old-password']);

        $this->actingAs($admin);

        $response = $this->putJson(route('super-admin.configuration.users.password.reset', $owner));

        $response->assertStatus(422);
        $response->assertJsonPath('error', "A Super Admin's password can only be reset by another Super Admin.");

        $this->assertTrue(Hash::check('the-old-password', $owner->refresh()->password));
    }

    public function test_a_super_admin_may_reset_another_super_admins_password(): void
    {
        $owner = $this->makeEmployee('super_admin', ['password' => 'the-old-password']);

        $this->putJson(route('super-admin.configuration.users.password.reset', $owner))
            ->assertOk();

        $this->assertFalse(Hash::check('the-old-password', $owner->refresh()->password));
    }

    /**
     * Resetting your own password from here would flag your own account as
     * needing a change mid-session. The password change page is the way.
     */
    public function test_an_administrator_cannot_reset_their_own_password(): void
    {
        $admin = $this->makeEmployee('admin', ['password' => 'the-old-password']);

        $this->actingAs($admin);

        $response = $this->putJson(route('super-admin.configuration.users.password.reset', $admin));

        $response->assertStatus(422);
        $this->assertTrue(Hash::check('the-old-password', $admin->refresh()->password));
    }

    public function test_an_archived_employee_cannot_have_their_password_reset(): void
    {
        $employee = $this->makeEmployee('technician', [
            'password' => 'the-old-password',
            'is_archived' => true,
        ]);

        $response = $this->putJson(route('super-admin.configuration.users.password.reset', $employee));

        $response->assertStatus(422);
        $this->assertTrue(Hash::check('the-old-password', $employee->refresh()->password));
    }

    public function test_generated_passwords_are_alphanumeric_strong_and_never_repeat(): void
    {
        $passwords = collect(range(1, 12))->map(function (): string {
            $response = $this->getJson(route('super-admin.configuration.users.password'));
            $response->assertOk();

            return $response->json('password');
        });

        $this->assertSame(12, $passwords->unique()->count());

        foreach ($passwords as $password) {
            $this->assertGreaterThanOrEqual(12, strlen($password));
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
            // Letters and digits only - these are typed in by hand.
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', $password);
        }
    }

    // ------------------------------------------------------------------
    // Manually chosen passwords
    // ------------------------------------------------------------------

    public function test_an_administrator_can_type_the_password_instead(): void
    {
        $this->postJson(
            route('super-admin.configuration.users.employees.store'),
            $this->employeePayload(['password' => 'chosen-by-hand-123'])
        )->assertCreated();

        $user = User::where('email', 'ana.mendoza@example.test')->firstOrFail();

        $this->assertTrue(Hash::check('chosen-by-hand-123', $user->password));
        // A typed password is still temporary; it has to be replaced at first
        // sign-in like any other.
        $this->assertTrue($user->must_change_password);
    }

    public function test_a_typed_client_password_is_used_and_a_short_one_is_refused(): void
    {
        $this->postJson(
            route('super-admin.configuration.users.clients.store'),
            $this->clientPayload(['password' => 'short'])
        )->assertStatus(422);

        $this->postJson(
            route('super-admin.configuration.users.clients.store'),
            $this->clientPayload(['password' => 'client-chosen-1'])
        )->assertCreated();

        $client = User::where('email', 'jose.garcia@example.test')->firstOrFail();

        $this->assertTrue(Hash::check('client-chosen-1', $client->password));
    }

    public function test_an_omitted_password_falls_back_to_a_generated_one(): void
    {
        $response = $this->postJson(
            route('super-admin.configuration.users.employees.store'),
            $this->employeePayload()
        );

        $response->assertCreated();

        $user = User::where('email', 'ana.mendoza@example.test')->firstOrFail();

        $this->assertTrue(Hash::check($response->json('password'), $user->password));
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{14}$/', $response->json('password'));
    }

    // ------------------------------------------------------------------
    // Status and archiving
    // ------------------------------------------------------------------

    public function test_deactivating_stops_sign_in_without_touching_anything_else(): void
    {
        $employee = $this->makeEmployee('technician');

        $this->putJson(route('super-admin.configuration.users.status', $employee), [
            'status' => 'deactivated',
        ])->assertOk();

        $employee->refresh();

        $this->assertFalse($employee->canLogin());
        $this->assertSame('Deactivated', $employee->statusLabel());
        // The account itself is untouched apart from its status.
        $this->assertFalse($employee->is_archived);
        $this->assertSame('technician', $employee->role);

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::EMPLOYEE_DEACTIVATED,
            'subject_id' => $employee->id,
        ]);

        $this->putJson(route('super-admin.configuration.users.status', $employee), [
            'status' => 'active',
        ])->assertOk();

        $this->assertTrue($employee->refresh()->canLogin());
    }

    public function test_a_deactivated_account_is_excluded_by_the_status_filter_but_still_listed(): void
    {
        $employee = $this->makeEmployee('admin');

        $this->putJson(route('super-admin.configuration.users.status', $employee), [
            'status' => 'deactivated',
        ])->assertOk();

        // Two rows: this one and the signed-in super admin.
        $this->getJson(route('super-admin.configuration.users.employees'))
            ->assertOk()
            ->assertJsonCount(2, 'rows');

        // Filtering to Active leaves only the super admin.
        $this->getJson(route('super-admin.configuration.users.employees', ['status' => 'active']))
            ->assertOk()
            ->assertJsonCount(1, 'rows');
    }

    public function test_archiving_hides_the_account_without_deleting_it(): void
    {
        $employee = $this->makeEmployee('admin');

        $this->deleteJson(route('super-admin.configuration.users.archive', $employee))->assertOk();

        // The row is still there, with everything it referenced intact.
        $this->assertDatabaseHas('users', ['id' => $employee->id]);

        $employee->refresh();

        $this->assertTrue($employee->is_archived);
        $this->assertNotNull($employee->archived_at);
        $this->assertFalse($employee->canLogin());

        // And it is gone from the active list, leaving the super admin alone.
        $this->getJson(route('super-admin.configuration.users.employees'))
            ->assertOk()
            ->assertJsonCount(1, 'rows');

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::EMPLOYEE_ARCHIVED,
            'subject_id' => $employee->id,
        ]);
    }

    public function test_an_archived_account_cannot_be_archived_twice(): void
    {
        $client = $this->makeClient();

        $this->deleteJson(route('super-admin.configuration.users.archive', $client))->assertOk();

        $this->deleteJson(route('super-admin.configuration.users.archive', $client))
            ->assertStatus(422);
    }

    public function test_an_archived_account_cannot_have_its_status_changed(): void
    {
        $client = $this->makeClient();

        $this->deleteJson(route('super-admin.configuration.users.archive', $client))->assertOk();

        $this->putJson(route('super-admin.configuration.users.status', $client), [
            'status' => 'active',
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Audit trail
    // ------------------------------------------------------------------

    public function test_every_log_entry_records_who_what_and_from_where(): void
    {
        $this->postJson(
            route('super-admin.configuration.users.employees.store'),
            $this->employeePayload()
        )->assertCreated();

        $log = ActivityLog::latestFirst()->firstOrFail();

        $this->assertSame(ActivityLog::EMPLOYEE_CREATED, $log->action);
        $this->assertSame('Ana B Mendoza', $log->subject_name);
        $this->assertNotEmpty($log->actor_name);
        $this->assertNotEmpty($log->ip_address);
        $this->assertNotNull($log->created_at);
        $this->assertStringContainsString('EMP-', $log->description);
    }

    // ------------------------------------------------------------------
    // Existing functionality
    // ------------------------------------------------------------------

    /**
     * The Technicians page reads the same records this module writes, so a
     * technician created here has to show up there.
     */
    public function test_a_technician_created_here_appears_on_the_technicians_page(): void
    {
        $skill = $this->skill('Electrical Wiring');

        $this->postJson(route('super-admin.configuration.users.employees.store'), $this->employeePayload([
            'role' => 'technician',
            'skill_ids' => [$skill->skill_id],
        ]))->assertCreated();

        $response = $this->get(route('super-admin.technicians.index'));

        $response->assertOk();
        $response->assertSee('Ana B Mendoza');
        $response->assertSee('Electrical Wiring');
    }
}
