<?php

namespace Tests\Feature;

use App\Http\Controllers\ConfigurationController;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
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

    /**
     * An export request.
     *
     * Both dates are required, so unless a test is about the window it gets
     * one wide enough to be beside the point - a test about the user filter
     * should not have to think about dates. A test that IS about the window
     * passes its own, and one about them being missing passes an empty
     * string, which is what an untouched date field submits.
     */
    private function export(array $query = []): TestResponse
    {
        $query += [
            'from' => CarbonImmutable::today()->subYears(10)->toDateString(),
            'to' => CarbonImmutable::today()->toDateString(),
        ];

        return $this->get(
            route('super-admin.configuration.activity-logs.export').'?'.http_build_query($query)
        );
    }

    /**
     * What the exported document is actually printed from.
     *
     * Read off the view rather than out of the PDF: the bytes are a rendering
     * of this, and asserting against them would be testing dompdf. The
     * endpoint is still exercised end to end - it is the request below that
     * fills this in - so nothing between the filters and the page is skipped.
     *
     * @return array<string, mixed>
     */
    private function exported(array $query = []): array
    {
        $captured = [];

        View::composer('super-admin.activity-logs-pdf', function ($view) use (&$captured): void {
            $captured = $view->getData();
        });

        $this->export($query)->assertOk();

        return $captured;
    }

    /**
     * The Name column of every printed row, which is what most of these
     * tests are really asking about.
     *
     * @return array<int, string>
     */
    private function exportedNames(array $query = []): array
    {
        return collect($this->exported($query)['rows'] ?? [])
            ->map(fn (array $row): string => $row[2])
            ->all();
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
            ->assertRedirect(route('landing.home'));
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

    // ------------------------------------------------------------------
    // Export
    // ------------------------------------------------------------------

    public function test_the_export_is_a_pdf(): void
    {
        $this->actingAsSuperAdmin();

        $this->log(['actor_name' => 'Ana Mendoza', 'description' => 'Rewired the riser.']);

        $response = $this->export();

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_the_export_carries_the_headings_and_one_row_per_entry(): void
    {
        $this->actingAsSuperAdmin();

        $this->log(['actor_name' => 'Ana Mendoza', 'description' => 'Rewired the riser.']);
        $this->log(['actor_name' => 'Jose Garcia', 'action' => ActivityLog::PROJECT_CREATED]);

        $document = $this->exported();

        // Exactly the columns the Activity Logs table shows. The address and
        // device an entry was made from stay on the row and off the document.
        $this->assertSame(
            ['Log ID', 'Date & Time', 'Name', 'Role', 'Module', 'Action', 'Details'],
            array_keys($document['columns'])
        );

        // The two entries above. The entry recording the export itself is
        // written after the rows are read, so a document never contains a
        // record of its own creation - and the count it prints matches the
        // rows beneath it.
        $this->assertCount(2, $document['rows']);
        $this->assertSame(2, $document['matched']);

        $names = $document['rows']->map(fn (array $row): string => $row[2])->all();

        $this->assertContains('Ana Mendoza', $names);
        $this->assertContains('Jose Garcia', $names);
    }

    /**
     * The dates a person reads in the file are the ones they read on the page.
     */
    public function test_the_export_writes_dates_in_the_one_display_format(): void
    {
        $this->actingAsSuperAdmin();

        $entry = $this->log(['created_at' => CarbonImmutable::parse('2026-01-01 15:04:00')]);

        $line = collect($this->exported()['rows'])
            ->first(fn (array $row): bool => $row[0] === (string) $entry->activity_log_id);

        $this->assertNotNull($line);
        $this->assertStringStartsWith('Jan 1, 2026', $line[1]);
    }

    public function test_the_export_narrows_to_the_chosen_date_range(): void
    {
        $this->actingAsSuperAdmin();

        $today = CarbonImmutable::today();

        $this->log(['actor_name' => 'Recent', 'created_at' => $today->subDays(2)]);
        $this->log(['actor_name' => 'Older', 'created_at' => $today->subDays(40)]);

        $names = $this->exportedNames([
            'from' => $today->subDays(7)->toDateString(),
            'to' => $today->toDateString(),
        ]);

        $this->assertContains('Recent', $names);
        $this->assertNotContains('Older', $names);
    }

    /**
     * Both ends are required. An export with no period on it is the whole
     * trail, which is neither a useful document nor something a PDF holds -
     * so an untouched date field is refused rather than quietly meaning
     * "everything".
     */
    public function test_the_export_requires_both_ends_of_the_date_range(): void
    {
        $this->actingAsSuperAdmin();

        $today = CarbonImmutable::today();

        $missingFrom = $this->export(['from' => '', 'to' => $today->toDateString()]);
        $missingFrom->assertRedirect(route('super-admin.configuration.index'));
        $missingFrom->assertSessionHas('error', 'Choose the date to export from.');

        $missingTo = $this->export(['from' => $today->subDays(7)->toDateString(), 'to' => '']);
        $missingTo->assertSessionHas('error', 'Choose the date to export to.');

        $neither = $this->export(['from' => '', 'to' => '']);
        $neither->assertSessionHas('error', 'Choose the date to export from.');

        // Nothing was produced, so nothing was recorded as having been.
        $this->assertSame(0, ActivityLog::where('action', ActivityLog::ACTIVITY_LOGS_EXPORTED)->count());
    }

    public function test_a_range_given_back_to_front_is_refused(): void
    {
        $this->actingAsSuperAdmin();

        $today = CarbonImmutable::today();

        $this->export([
            'from' => $today->toDateString(),
            'to' => $today->subDays(7)->toDateString(),
        ])->assertSessionHas('error', 'The To date cannot be before the From date.');
    }

    /**
     * The chosen window is what the export uses, whichever named range the
     * table happened to be showing behind it.
     */
    public function test_the_chosen_window_beats_the_range_the_table_was_showing(): void
    {
        $this->actingAsSuperAdmin();

        $today = CarbonImmutable::today();

        $this->log(['actor_name' => 'Recent', 'created_at' => $today->subDays(2)]);
        $this->log(['actor_name' => 'Older', 'created_at' => $today->subDays(40)]);

        $names = $this->exportedNames([
            'range' => 'today',
            'from' => $today->subDays(60)->toDateString(),
            'to' => $today->toDateString(),
        ]);

        $this->assertContains('Recent', $names);
        $this->assertContains('Older', $names);
    }

    /**
     * The table's own range filter stays optional and open at either end -
     * "All Time" is a thing somebody reads on screen, and a half-filled
     * custom window must narrow rather than quietly widen to everything.
     */
    public function test_the_table_honours_a_one_sided_date_range(): void
    {
        $this->actingAsSuperAdmin();

        $today = CarbonImmutable::today();

        $this->log(['actor_name' => 'Recent', 'created_at' => $today->subDays(2)]);
        $this->log(['actor_name' => 'Older', 'created_at' => $today->subDays(40)]);

        $from = $this->fetch([
            'range' => 'custom',
            'from' => $today->subDays(7)->toDateString(),
        ]);

        $this->assertSame(['Recent'], collect($from->json('rows'))->pluck('actor_name')->all());

        $to = $this->fetch([
            'range' => 'custom',
            'to' => $today->subDays(20)->toDateString(),
        ]);

        $this->assertSame(['Older'], collect($to->json('rows'))->pluck('actor_name')->all());
    }

    public function test_the_export_narrows_to_the_chosen_user(): void
    {
        $this->actingAsSuperAdmin();

        $ana = $this->account('technician', 'ana@example.test');
        $jose = $this->account('technician', 'jose@example.test');

        $this->log(['actor_id' => $ana->id, 'actor_name' => 'Ana Mendoza']);
        $this->log(['actor_id' => $jose->id, 'actor_name' => 'Jose Garcia']);

        $names = $this->exportedNames(['actor_id' => $ana->id]);

        $this->assertContains('Ana Mendoza', $names);
        $this->assertNotContains('Jose Garcia', $names);
    }

    public function test_the_export_respects_the_search_role_and_module_filters(): void
    {
        $this->actingAsSuperAdmin();

        $this->log(['actor_name' => 'Ana Mendoza', 'actor_role' => 'technician', 'module' => ActivityLog::MODULE_TASKS]);
        $this->log(['actor_name' => 'Jose Garcia', 'actor_role' => 'client', 'module' => ActivityLog::MODULE_TASKS]);
        $this->log(['actor_name' => 'Ana Mendoza', 'actor_role' => 'technician', 'module' => ActivityLog::MODULE_PROJECTS]);

        $names = $this->exportedNames([
            'role' => 'technician',
            'module' => ActivityLog::MODULE_TASKS,
            'search' => 'Mendoza',
        ]);

        $this->assertSame(['Ana Mendoza'], $names);
    }

    /**
     * The file may never carry a row the page would have hidden. An Admin
     * cannot read a Super Admin's or a peer's trail, and exporting is not a
     * way round that.
     */
    public function test_an_admin_exports_only_what_they_may_read(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');
        $peer = $this->account('admin', 'peer@example.test');
        $admin = $this->account('admin', 'admin@example.test');

        $this->log(['actor_id' => $owner->id, 'actor_name' => 'The owner', 'actor_role' => 'super_admin']);
        $this->log(['actor_id' => $peer->id, 'actor_name' => 'The other admin', 'actor_role' => 'admin']);
        $this->log(['actor_id' => $admin->id, 'actor_name' => 'This admin', 'actor_role' => 'admin']);
        $this->log(['actor_name' => 'A technician', 'actor_role' => 'technician']);

        $this->actingAs($admin);

        $names = $this->exportedNames();

        $this->assertContains('This admin', $names);
        $this->assertContains('A technician', $names);
        $this->assertNotContains('The owner', $names);
        $this->assertNotContains('The other admin', $names);
    }

    /**
     * Naming another account is not a way past the scope either: the actor
     * filter narrows the visible rows, it does not widen them.
     */
    public function test_naming_a_hidden_actor_does_not_widen_the_export(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');
        $admin = $this->account('admin', 'admin@example.test');

        $this->log(['actor_id' => $owner->id, 'actor_name' => 'The owner', 'actor_role' => 'super_admin']);

        $this->actingAs($admin);

        $this->assertSame([], $this->exportedNames(['actor_id' => $owner->id]));
    }

    public function test_a_technician_cannot_export_the_trail(): void
    {
        $this->actingAs($this->account('technician', 'tech@example.test'));

        $this->export()->assertRedirect(route('technician.schedule'));
    }

    public function test_a_client_cannot_export_the_trail(): void
    {
        $this->actingAs($this->account('client', 'client@example.test'));

        $this->export()->assertRedirect(route('landing.home'));
    }

    public function test_a_signed_out_visitor_cannot_export_the_trail(): void
    {
        $this->export()->assertRedirect(route('auth.login'));
    }

    public function test_the_export_is_itself_recorded_with_what_it_was_narrowed_to(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $ana = $this->account('technician', 'ana@example.test');
        $today = CarbonImmutable::today();

        $this->export([
            'from' => $today->subDays(7)->toDateString(),
            'to' => $today->toDateString(),
            'actor_id' => $ana->id,
        ])->assertOk();

        $entry = ActivityLog::where('action', ActivityLog::ACTIVITY_LOGS_EXPORTED)->first();

        $this->assertNotNull($entry);
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame(ActivityLog::MODULE_CONFIGURATION, $entry->module);
        $this->assertStringContainsString('as PDF', (string) $entry->description);
        $this->assertStringContainsString($today->format('M j, Y'), (string) $entry->description);
        $this->assertStringContainsString($ana->fullName(), (string) $entry->description);
    }

    /**
     * The user filter is a search box rather than a list, and what it can
     * find is narrowed server-side: an Admin cannot search their way to a
     * Super Admin and be handed a blank document.
     */
    public function test_the_user_search_can_only_find_actors_the_reader_may_audit(): void
    {
        $owner = $this->account('super_admin', 'owner@example.test');
        $tech = $this->account('technician', 'tech@example.test');
        $admin = $this->account('admin', 'admin@example.test');

        $this->log(['actor_id' => $owner->id, 'actor_name' => 'The owner', 'actor_role' => 'super_admin']);
        $this->log(['actor_id' => $tech->id, 'actor_name' => 'The technician', 'actor_role' => 'technician']);

        $response = $this->actingAs($admin)->get(route('super-admin.configuration.index'));

        $response->assertOk();
        $response->assertSee('Export Logs');
        $response->assertSee('data-log-export-user-search', false);

        $searchable = collect($response->viewData('logActors'))->pluck('id')->all();

        $this->assertContains($tech->id, $searchable);
        $this->assertNotContains($owner->id, $searchable);
    }

    /**
     * A PDF has a ceiling the trail does not. Past it the newest entries are
     * printed and the document is handed the figures it needs to say so on
     * its face - a truncated export must never look complete.
     */
    public function test_a_document_past_the_row_limit_says_how_much_it_left_out(): void
    {
        $this->actingAsSuperAdmin();

        $limit = (new \ReflectionClass(ConfigurationController::class))
            ->getConstant('EXPORT_ROW_LIMIT');

        // Written straight to the table: the point is the count, and one
        // insert per row through the model would make this test crawl.
        $now = CarbonImmutable::now();
        $rows = [];

        for ($i = 0; $i <= $limit; $i++) {
            $rows[] = [
                'actor_id' => null,
                'actor_name' => 'Someone',
                'actor_role' => 'technician',
                'action' => ActivityLog::LOGIN,
                'module' => ActivityLog::MODULE_AUTHENTICATION,
                'description' => 'Something happened.',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('tbl_activity_logs')->insert($chunk);
        }

        $document = $this->exported();

        $this->assertCount($limit, $document['rows']);
        $this->assertSame($limit + 1, $document['matched']);
        $this->assertSame($limit, $document['limit']);

        $entry = ActivityLog::where('action', ActivityLog::ACTIVITY_LOGS_EXPORTED)->first();

        $this->assertStringContainsString(
            sprintf('Exported %d of %d', $limit, $limit + 1),
            (string) $entry->description
        );
    }

    public function test_an_unknown_user_is_refused_rather_than_ignored(): void
    {
        $this->actingAsSuperAdmin();

        $this->export(['actor_id' => 999999])
            ->assertRedirect(route('super-admin.configuration.index'))
            ->assertSessionHas('error');
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
