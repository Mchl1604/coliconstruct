<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\PortalHome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The four office roles and the two parts of the office portal.
 *
 * Super Admin reaches everything, HR Admin (stored as `admin`) the scheduling
 * side, Purchasing Admin the inventory, and Admin Officer both. See
 * App\Support\RoleAccess.
 */
class OfficeRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Each role, whether it reaches scheduling, and whether it reaches the
     * inventory.
     *
     * @return array<string, array{string, bool, bool}>
     */
    public static function roleReach(): array
    {
        return [
            'super admin' => [User::ROLE_SUPER_ADMIN, true, true],
            'hr admin' => [User::ROLE_ADMIN, true, false],
            'admin officer' => [User::ROLE_ADMIN_OFFICER, true, true],
            'purchasing admin' => [User::ROLE_PURCHASING_ADMIN, false, true],
            'lead technician' => [User::ROLE_LEAD_TECHNICIAN, false, false],
            'technician' => [User::ROLE_TECHNICIAN, false, false],
        ];
    }

    #[DataProvider('roleReach')]
    public function test_each_role_reaches_only_its_own_parts_of_the_portal(string $role, bool $scheduling, bool $inventory): void
    {
        $account = $this->account($role);
        $home = PortalHome::url($account);

        $this->actingAs($account);

        foreach (['super-admin.dashboard', 'super-admin.projects'] as $page) {
            $scheduling
                ? $this->get(route($page))->assertOk()
                : $this->get(route($page))->assertRedirect($home);
        }

        // Configuration is both sides': accounts for scheduling, Inventory
        // Settings for inventory.
        $scheduling || $inventory
            ? $this->get(route('super-admin.configuration.index'))->assertOk()
            : $this->get(route('super-admin.configuration.index'))->assertRedirect($home);

        $inventory
            ? $this->get(route('inventory.index'))->assertOk()
            : $this->get(route('inventory.index'))->assertRedirect($home);

        $inventory
            ? $this->getJson(route('inventory.materials.index'))->assertOk()
            : $this->getJson(route('inventory.materials.index'))->assertForbidden();
    }

    public function test_a_purchasing_admin_signs_in_to_the_inventory(): void
    {
        $this->account(User::ROLE_PURCHASING_ADMIN, ['email' => 'buyer@example.test']);

        $this->post(route('auth.login.attempt'), [
            'email' => 'buyer@example.test',
            'password' => 'correct-password',
        ])->assertRedirect(route('inventory.index'));
    }

    public function test_an_admin_officer_signs_in_to_the_dashboard(): void
    {
        $this->account(User::ROLE_ADMIN_OFFICER, ['email' => 'officer@example.test']);

        $this->post(route('auth.login.attempt'), [
            'email' => 'officer@example.test',
            'password' => 'correct-password',
        ])->assertRedirect(route('super-admin.dashboard'));
    }

    public function test_the_sidebar_draws_only_the_parts_a_role_reaches(): void
    {
        $this->actingAs($this->account(User::ROLE_PURCHASING_ADMIN));

        $this->get(route('inventory.index'))
            ->assertOk()
            ->assertSee('href="'.route('inventory.index').'"', false)
            ->assertSee('href="'.route('super-admin.configuration.index').'"', false)
            ->assertDontSee('href="'.route('super-admin.dashboard').'"', false)
            ->assertDontSee('href="'.route('super-admin.projects').'"', false);

        $this->actingAs($this->account(User::ROLE_ADMIN));

        $this->get(route('super-admin.dashboard'))
            ->assertOk()
            ->assertSee('href="'.route('super-admin.projects').'"', false)
            ->assertDontSee('href="'.route('inventory.index').'"', false);

        $this->actingAs($this->account(User::ROLE_ADMIN_OFFICER));

        $this->get(route('super-admin.dashboard'))
            ->assertOk()
            ->assertSee('href="'.route('super-admin.projects').'"', false)
            ->assertSee('href="'.route('inventory.index').'"', false);
    }

    public function test_configuration_shows_each_role_only_its_own_tabs(): void
    {
        $this->actingAs($this->account(User::ROLE_PURCHASING_ADMIN));

        $this->get(route('super-admin.configuration.index'))
            ->assertOk()
            ->assertSee('id="inventorySettingsPane"', false)
            ->assertDontSee('id="userManagementPane"', false)
            ->assertDontSee('id="activityLogsPane"', false)
            ->assertDontSee('id="inquiriesPane"', false)
            ->assertDontSee('configuration.js', false)
            ->assertSee('data-app-confirm', false);

        $this->actingAs($this->account(User::ROLE_ADMIN));

        $this->get(route('super-admin.configuration.index'))
            ->assertOk()
            ->assertSee('id="userManagementPane"', false)
            ->assertDontSee('id="inventorySettingsPane"', false);

        $this->actingAs($this->account(User::ROLE_ADMIN_OFFICER));

        $this->get(route('super-admin.configuration.index'))
            ->assertOk()
            ->assertSee('id="userManagementPane"', false)
            ->assertSee('id="inventorySettingsPane"', false)
            ->assertDontSee('id="systemSettingsPane"', false);
    }

    public function test_a_purchasing_admin_reaches_no_other_configuration_endpoint(): void
    {
        $this->actingAs($this->account(User::ROLE_PURCHASING_ADMIN));

        $this->getJson(route('super-admin.configuration.users.employees'))->assertForbidden();
        $this->getJson(route('super-admin.configuration.activity-logs'))->assertForbidden();
        $this->getJson(route('inventory.materials.types.index'))->assertOk();
    }

    public function test_admin_shows_as_hr_admin_and_keeps_its_stored_value(): void
    {
        $admin = $this->account(User::ROLE_ADMIN);

        $this->assertSame('admin', $admin->refresh()->role);
        $this->assertSame('HR Admin', $admin->roleLabel());
        $this->assertSame('Admin Officer', $this->account(User::ROLE_ADMIN_OFFICER)->roleLabel());
        $this->assertSame('Purchasing Admin', $this->account(User::ROLE_PURCHASING_ADMIN)->roleLabel());
    }

    public function test_only_a_super_admin_opens_the_new_office_accounts(): void
    {
        $this->actingAsSuperAdmin();

        foreach ([User::ROLE_ADMIN_OFFICER, User::ROLE_PURCHASING_ADMIN] as $index => $role) {
            $this->postJson(route('super-admin.configuration.users.employees.store'), $this->employeePayload([
                'email' => 'new'.$index.'@example.test',
                'role' => $role,
            ]))->assertCreated();

            $this->assertSame($role, User::query()->where('email', 'new'.$index.'@example.test')->value('role'));
        }

        foreach ([User::ROLE_ADMIN, User::ROLE_ADMIN_OFFICER] as $index => $actor) {
            $this->actingAs($this->account($actor));

            $this->postJson(route('super-admin.configuration.users.employees.store'), $this->employeePayload([
                'email' => 'refused'.$index.'@example.test',
                'role' => User::ROLE_PURCHASING_ADMIN,
            ]))->assertStatus(422);
        }

        $this->assertFalse(User::query()->where('email', 'like', 'refused%')->exists());
    }

    public function test_an_hr_admin_or_admin_officer_cannot_manage_another_office_account(): void
    {
        $buyer = $this->account(User::ROLE_PURCHASING_ADMIN, ['email' => 'buyer@example.test']);
        $officer = $this->account(User::ROLE_ADMIN_OFFICER, ['email' => 'officer@example.test']);

        foreach ([[User::ROLE_ADMIN, $buyer], [User::ROLE_ADMIN, $officer], [User::ROLE_ADMIN_OFFICER, $buyer]] as [$actor, $target]) {
            $this->actingAs($this->account($actor));

            $this->putJson(route('super-admin.configuration.users.status', $target), ['status' => 'deactivated'])
                ->assertStatus(422);

            $this->putJson(route('super-admin.configuration.users.password.reset', $target))
                ->assertStatus(422)
                ->assertJsonPath('error', 'Only a Super Admin can reset this password.');

            $this->assertTrue($target->refresh()->isActive());
        }

        $this->actingAs($this->account(User::ROLE_ADMIN_OFFICER));

        $technician = $this->account(User::ROLE_TECHNICIAN);

        $this->putJson(route('super-admin.configuration.users.status', $technician), ['status' => 'deactivated'])
            ->assertOk();
    }

    public function test_an_admin_officer_hears_what_administrators_hear_and_a_purchasing_admin_does_not(): void
    {
        $this->account(User::ROLE_ADMIN);
        $this->account(User::ROLE_ADMIN_OFFICER);
        $this->account(User::ROLE_PURCHASING_ADMIN);

        $roles = app(NotificationService::class)->administrators()->pluck('role')->sort()->values()->all();

        $this->assertSame([User::ROLE_ADMIN, User::ROLE_ADMIN_OFFICER], $roles);
    }

    public function test_an_admin_officer_reads_the_activity_logs_an_hr_admin_does(): void
    {
        $officer = $this->account(User::ROLE_ADMIN_OFFICER);
        $buyer = $this->account(User::ROLE_PURCHASING_ADMIN);

        $this->log(['actor_id' => $officer->id, 'actor_role' => User::ROLE_ADMIN_OFFICER, 'actor_name' => 'Their own entry']);
        $this->log(['actor_id' => $buyer->id, 'actor_role' => User::ROLE_PURCHASING_ADMIN, 'actor_name' => 'The buyer']);
        $this->log(['actor_role' => User::ROLE_ADMIN, 'actor_name' => 'An HR admin']);
        $this->log(['actor_role' => User::ROLE_TECHNICIAN, 'actor_name' => 'A technician']);

        $this->actingAs($officer);

        $names = collect($this->getJson(route('super-admin.configuration.activity-logs'))->assertOk()->json('rows'))
            ->pluck('actor_name')
            ->all();

        $this->assertContains('Their own entry', $names);
        $this->assertContains('A technician', $names);
        $this->assertNotContains('The buyer', $names);
        $this->assertNotContains('An HR admin', $names);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function account(string $role, array $overrides = []): User
    {
        $sequence = User::count() + 1;

        return User::create(array_merge([
            'user_code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => 'Office Person',
            'first_name' => 'Office',
            'last_name' => 'Person',
            'email' => 'person'.$sequence.'@example.test',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'password' => 'correct-password',
        ], $overrides));
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
            'contact_number' => '09175551234',
            'birthdate' => '1990-05-04',
            'email' => 'ana.mendoza@example.test',
            'role' => User::ROLE_TECHNICIAN,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function log(array $overrides): ActivityLog
    {
        return ActivityLog::create(array_merge([
            'actor_id' => null,
            'actor_name' => 'Someone',
            'actor_role' => User::ROLE_TECHNICIAN,
            'action' => ActivityLog::LOGIN,
            'module' => ActivityLog::MODULE_AUTHENTICATION,
            'description' => 'Something happened.',
        ], $overrides));
    }
}
