<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Activity Logs tab: what is recorded, who may read it, and that the
 * narrowing all happens in SQL rather than in the browser.
 */
class ActivityLogsTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role, string $email): User
    {
        $sequence = User::count() + 1;

        return User::create([
            'user_code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => ucfirst($role).' Person',
            'first_name' => ucfirst(str_replace('_', ' ', $role)),
            'last_name' => 'Person',
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function log(array $overrides = []): ActivityLog
    {
        // created_at is not fillable, so a back-dated entry has to be forced
        // on after the fact rather than passed to create().
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);

        $entry = ActivityLog::create(array_merge([
            'actor_id' => null,
            'actor_name' => 'Someone',
            'actor_role' => 'technician',
            'action' => ActivityLog::LOGIN,
            'module' => ActivityLog::MODULE_AUTHENTICATION,
            'description' => 'Something happened.',
        ], $overrides));

        if ($createdAt) {
            $entry->forceFill(['created_at' => $createdAt])->save();
        }

        return $entry;
    }

    private function fetch(array $query = []): TestResponse
    {
        return $this->getJson(
            route('super-admin.configuration.activity-logs').'?'.http_build_query($query)
        );
    }

    // ------------------------------------------------------------------
    // What gets recorded
    // ------------------------------------------------------------------

    public function test_every_action_declares_the_module_it_belongs_to(): void
    {
        foreach (ActivityLog::MODULE_FOR as $action => $module) {
            $this->assertContains(
                $module,
                ActivityLog::MODULES,
                $action.' is filed under a module the filter does not offer.'
            );
        }
    }

    public function test_the_logger_derives_the_module_role_and_agent(): void
    {
        $admin = $this->account('admin', 'admin@example.test');
        $this->actingAs($admin);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36')
            ->get(route('super-admin.configuration.index'));

        app(ActivityLogger::class)->record(ActivityLog::PROJECT_CREATED, null, 'Created a project.');

        $entry = ActivityLog::latestFirst()->first();

        $this->assertSame(ActivityLog::MODULE_PROJECTS, $entry->module);
        $this->assertSame('admin', $entry->actor_role);
        $this->assertSame($admin->id, (int) $entry->actor_id);
        $this->assertSame($admin->fullName(), $entry->actor_name);
    }

    /**
     * A rolled-back action must leave nothing behind claiming it happened.
     */
    public function test_nothing_is_recorded_when_the_transaction_rolls_back(): void
    {
        $this->actingAsSuperAdmin();

        try {
            DB::transaction(function (): void {
                app(ActivityLogger::class)->record(ActivityLog::PROJECT_CREATED, null, 'Created a project.');

                throw new \RuntimeException('The action failed after logging.');
            });
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertSame(0, ActivityLog::where('action', ActivityLog::PROJECT_CREATED)->count());
    }

    public function test_the_entry_is_written_once_the_transaction_commits(): void
    {
        $this->actingAsSuperAdmin();

        DB::transaction(function (): void {
            app(ActivityLogger::class)->record(ActivityLog::PROJECT_CREATED, null, 'Created a project.');
        });

        $this->assertSame(1, ActivityLog::where('action', ActivityLog::PROJECT_CREATED)->count());
    }

    /**
     * The audit entry is written after the fact; it must never be able to fail
     * the action it describes.
     */
    public function test_a_logging_failure_does_not_escape(): void
    {
        $this->actingAsSuperAdmin();

        // Far longer than the column allows, so the insert fails.
        app(ActivityLogger::class)->record(
            ActivityLog::PROJECT_CREATED,
            null,
            str_repeat('x', 70000)
        );

        // No exception reached the caller, which is the whole point.
        $this->assertTrue(true);
    }

    public function test_signing_in_and_out_is_recorded(): void
    {
        $admin = $this->account('admin', 'admin@example.test');

        $this->post(route('auth.login.attempt'), [
            'email' => $admin->email,
            'password' => 'correct-password',
        ]);

        $this->post(route('auth.logout'));

        $actions = ActivityLog::pluck('action')->all();

        $this->assertContains(ActivityLog::LOGIN, $actions);
        $this->assertContains(ActivityLog::LOGOUT, $actions);
    }

    public function test_a_failed_sign_in_is_recorded_against_the_address(): void
    {
        $admin = $this->account('admin', 'admin@example.test');

        $this->post(route('auth.login.attempt'), [
            'email' => $admin->email,
            'password' => 'the-wrong-password',
        ]);

        $entry = ActivityLog::where('action', ActivityLog::LOGIN_FAILED)->first();

        $this->assertNotNull($entry);
        $this->assertStringContainsString($admin->email, $entry->description);
        $this->assertSame(ActivityLog::MODULE_AUTHENTICATION, $entry->module);
        // Nobody is signed in during a failed attempt.
        $this->assertNull($entry->actor_id);
    }

    // ------------------------------------------------------------------
    // Who may read what
    // ------------------------------------------------------------------

    public function test_a_super_admin_sees_every_role(): void
    {
        $this->actingAsSuperAdmin();

        foreach (['super_admin', 'admin', 'lead_technician', 'technician', 'client'] as $role) {
            $this->log(['actor_role' => $role, 'actor_name' => $role.' entry']);
        }

        $response = $this->fetch();

        $response->assertOk();
        $this->assertSame(5, $response->json('meta.total'));
    }

    /**
     * An audit trail one peer can quietly read is not much of a check on the
     * other, so an Admin sees neither a Super Admin's entries nor another
     * Admin's - only their own and everyone below them.
     */
    public function test_an_admin_cannot_read_super_admin_or_peer_entries(): void
    {
        $admin = $this->account('admin', 'admin@example.test');
        $other = $this->account('admin', 'other-admin@example.test');

        $this->log(['actor_role' => 'super_admin', 'actor_name' => 'The owner']);
        $this->log(['actor_id' => $other->id, 'actor_role' => 'admin', 'actor_name' => 'The other admin']);
        $this->log(['actor_id' => $admin->id, 'actor_role' => 'admin', 'actor_name' => 'Their own entry']);
        $this->log(['actor_role' => 'lead_technician', 'actor_name' => 'A lead']);
        $this->log(['actor_role' => 'technician', 'actor_name' => 'A technician']);
        $this->log(['actor_role' => 'client', 'actor_name' => 'A client']);

        $this->actingAs($admin);
        $response = $this->fetch();

        $response->assertOk();

        $names = collect($response->json('rows'))->pluck('actor_name')->all();

        $this->assertContains('Their own entry', $names);
        $this->assertContains('A lead', $names);
        $this->assertContains('A technician', $names);
        $this->assertContains('A client', $names);

        $this->assertNotContains('The owner', $names);
        $this->assertNotContains('The other admin', $names);
    }

    public function test_a_technician_cannot_reach_the_page_at_all(): void
    {
        $this->actingAs($this->account('technician', 'tech@example.test'));

        $this->get(route('super-admin.configuration.activity-logs'))
            ->assertRedirect(route('technician.schedule'));
    }

    public function test_a_client_cannot_reach_the_page_at_all(): void
    {
        $this->actingAs($this->account('client', 'client@example.test'));

        $this->get(route('super-admin.configuration.activity-logs'))
            ->assertRedirect(route('client.dashboard'));
    }

    // ------------------------------------------------------------------
    // Searching, filtering, sorting, paging
    // ------------------------------------------------------------------

    public function test_the_search_covers_name_action_and_description(): void
    {
        $this->actingAsSuperAdmin();

        $this->log(['actor_name' => 'Ana Mendoza', 'description' => 'Something ordinary.']);
        $this->log(['actor_name' => 'Jose Garcia', 'action' => ActivityLog::PROJECT_CREATED]);
        $this->log(['actor_name' => 'Someone Else', 'description' => 'Rewired the riser.']);

        $this->assertSame(1, $this->fetch(['search' => 'Mendoza'])->json('meta.total'));
        $this->assertSame(1, $this->fetch(['search' => 'Project Created'])->json('meta.total'));
        $this->assertSame(1, $this->fetch(['search' => 'riser'])->json('meta.total'));
        $this->assertSame(0, $this->fetch(['search' => 'nothing matches this'])->json('meta.total'));
    }

    public function test_the_role_and_module_filters_narrow_in_sql(): void
    {
        $this->actingAsSuperAdmin();

        $this->log(['actor_role' => 'technician', 'module' => ActivityLog::MODULE_TASKS]);
        $this->log(['actor_role' => 'technician', 'module' => ActivityLog::MODULE_PROJECTS]);
        $this->log(['actor_role' => 'client', 'module' => ActivityLog::MODULE_TASKS]);

        $this->assertSame(2, $this->fetch(['role' => 'technician'])->json('meta.total'));
        $this->assertSame(2, $this->fetch(['module' => ActivityLog::MODULE_TASKS])->json('meta.total'));
        $this->assertSame(
            1,
            $this->fetch(['role' => 'technician', 'module' => ActivityLog::MODULE_TASKS])->json('meta.total')
        );
    }

    public function test_the_date_ranges_narrow_correctly(): void
    {
        $this->actingAsSuperAdmin();

        $today = CarbonImmutable::today();

        $this->log(['created_at' => $today->addHours(9)]);
        $this->log(['created_at' => $today->subDays(3)]);
        $this->log(['created_at' => $today->subDays(20)]);
        $this->log(['created_at' => $today->subDays(90)]);

        $this->assertSame(1, $this->fetch(['range' => 'today'])->json('meta.total'));
        $this->assertSame(2, $this->fetch(['range' => 'week'])->json('meta.total'));
        $this->assertSame(3, $this->fetch(['range' => 'month'])->json('meta.total'));
        $this->assertSame(4, $this->fetch(['range' => 'all'])->json('meta.total'));

        $custom = $this->fetch([
            'range' => 'custom',
            'from' => $today->subDays(25)->toDateString(),
            'to' => $today->subDays(2)->toDateString(),
        ]);

        $this->assertSame(2, $custom->json('meta.total'));
    }

    public function test_the_newest_entry_comes_first_by_default(): void
    {
        $this->actingAsSuperAdmin();

        $this->log(['actor_name' => 'Older', 'created_at' => CarbonImmutable::now()->subDay()]);
        $this->log(['actor_name' => 'Newer', 'created_at' => CarbonImmutable::now()]);

        $rows = $this->fetch()->json('rows');

        $this->assertSame('Newer', $rows[0]['actor_name']);
        $this->assertSame('Older', $rows[1]['actor_name']);
    }

    public function test_it_can_be_sorted_by_each_offered_column(): void
    {
        $this->actingAsSuperAdmin();

        $this->log(['actor_name' => 'Zoe', 'module' => ActivityLog::MODULE_TASKS]);
        $this->log(['actor_name' => 'Ana', 'module' => ActivityLog::MODULE_PROJECTS]);

        foreach (['date', 'name', 'role', 'module', 'action'] as $sort) {
            $this->fetch(['sort' => $sort, 'direction' => 'asc'])->assertOk();
        }

        $byName = $this->fetch(['sort' => 'name', 'direction' => 'asc'])->json('rows');
        $this->assertSame('Ana', $byName[0]['actor_name']);
    }

    /**
     * Ordering is an allow-list, so no request can order by a column of its
     * own choosing.
     */
    public function test_an_unknown_sort_column_is_refused(): void
    {
        $this->actingAsSuperAdmin();

        $this->fetch(['sort' => 'password'])->assertStatus(422);
    }

    public function test_it_pages_on_the_server_rather_than_sending_everything(): void
    {
        $this->actingAsSuperAdmin();

        foreach (range(1, 25) as $index) {
            $this->log(['actor_name' => 'Person '.$index]);
        }

        $first = $this->fetch();

        $first->assertOk();
        $first->assertJsonCount(10, 'rows');
        $this->assertSame(25, $first->json('meta.total'));
        $this->assertSame(3, $first->json('meta.last_page'));

        $this->assertCount(5, $this->fetch(['page' => 3])->json('rows'));
    }

    /**
     * The name and role shown are the snapshot taken at the time, not whatever
     * the account says now.
     */
    public function test_a_row_keeps_naming_who_it_was_at_the_time(): void
    {
        $this->actingAsSuperAdmin();

        $technician = $this->account('technician', 'tech@example.test');

        $this->log([
            'actor_id' => $technician->id,
            'actor_name' => $technician->fullName(),
            'actor_role' => 'technician',
        ]);

        $technician->forceFill(['first_name' => 'Renamed', 'role' => 'lead_technician'])->save();

        $row = $this->fetch()->json('rows.0');

        $this->assertSame('Technician Person', $row['actor_name']);
        $this->assertSame('Technician', $row['role_label']);
    }

    public function test_each_role_gets_its_own_badge_colour(): void
    {
        $this->actingAsSuperAdmin();

        $expected = [
            'super_admin' => 'bg-danger',
            'admin' => 'bg-primary',
            'lead_technician' => 'badge-role-lead',
            'technician' => 'bg-success',
            'client' => 'bg-secondary',
        ];

        foreach ($expected as $role => $class) {
            $this->assertSame($class, $this->log(['actor_role' => $role])->actorRoleBadgeClass());
        }
    }
}
