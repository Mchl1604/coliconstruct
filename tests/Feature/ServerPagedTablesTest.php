<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\User;
use App\Support\ServerTable;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Every list in both portals pages on the server: a page carries none of its
 * rows, and each request for a page answers with those rows and nothing more
 * - the rows themselves, and only the dialogs those rows open.
 *
 * What is checked here is the paging itself: the counts, the slice, the
 * search, the sort and the filters each table offers. What each row SAYS is
 * covered by the tests about that row, which read it through
 * TestCase::getTablePage().
 */
class ServerPagedTablesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->actingAsSuperAdmin();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function account(string $role, string $email, string $name = 'Test Person'): User
    {
        $sequence = User::count() + 1;

        return User::create([
            'user_code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => $name,
            'first_name' => explode(' ', $name)[0],
            'last_name' => explode(' ', $name)[1] ?? 'Person',
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);
    }

    private function technician(User $account): Technician
    {
        return Technician::create(['account_id' => $account->id, 'role' => $account->role]);
    }

    private function project(string $reference, string $client = 'Ana Santos', array $attributes = []): Project
    {
        $project = Project::create($attributes + [
            'name' => 'Project '.$reference,
            'reference_no' => $reference,
            'status' => 'ongoing',
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Residential',
            'firstname' => explode(' ', $client)[0],
            'surname' => explode(' ', $client)[1] ?? 'Client',
            'fullname' => $client,
            'email_address' => strtolower(str_replace(' ', '.', $client)).'@example.test',
            'contact_number' => '09123456789',
        ]);

        return $project;
    }

    private function book(Project $project, int $fromDays, int $toDays): void
    {
        Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => CarbonImmutable::today()->addDays($fromDays)->setTime(8, 0),
            'end_datetime' => CarbonImmutable::today()->addDays($toDays)->setTime(17, 0),
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);
    }

    /**
     * How many dialogs of one kind a page of markup carries - matched by the
     * whole id, since a dialog's title carries an id that starts the same.
     */
    private function dialogCount(string $kind, string $markup): int
    {
        return preg_match_all('/id="'.$kind.'\d+"/', $markup);
    }

    /**
     * The table endpoint's JSON for one request.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function page(string $route, array $query = [], array $parameters = []): array
    {
        return $this->getJson(route($route, $parameters).'?'.http_build_query($query + ['draw' => 1]))
            ->assertOk()
            ->json();
    }

    // ------------------------------------------------------------------
    // ServerTable itself
    // ------------------------------------------------------------------

    public function test_a_table_request_is_read_forgivingly_and_capped(): void
    {
        $table = ServerTable::fromRequest(Request::create('/', 'GET', [
            'draw' => '7',
            'start' => '-5',
            'length' => '100000',
            'search' => '  wall  paint ',
            'order' => [['column' => '2', 'dir' => 'DESC'], ['column' => 'x'], 'junk'],
        ]));

        $this->assertSame(7, $table->draw);
        $this->assertSame(0, $table->start);
        $this->assertSame(ServerTable::MAX_LENGTH, $table->length);
        $this->assertSame(['wall', 'paint'], $table->searchWords());
        $this->assertSame([['column' => 2, 'dir' => 'desc']], $table->order);

        $this->assertSame(
            ServerTable::DEFAULT_LENGTH,
            ServerTable::fromRequest(Request::create('/', 'GET', ['length' => '0']))->length
        );
    }

    public function test_a_collection_is_searched_word_by_word_sorted_and_sliced(): void
    {
        $items = collect([
            ['name' => 'Wall painting', 'size' => 3],
            ['name' => 'Roof painting', 'size' => 1],
            ['name' => 'Wall repair', 'size' => 2],
            ['name' => 'Floor tiling', 'size' => 5],
        ]);

        $table = ServerTable::fromRequest(Request::create('/', 'GET', [
            'search' => 'paint',
            'order' => [['column' => 1, 'dir' => 'desc']],
            'start' => 0,
            'length' => 1,
        ]));

        $page = $table->paginateCollection(
            $items,
            sortable: [0 => fn (array $item): string => $item['name'], 1 => fn (array $item): int => $item['size']],
            search: fn (array $item, string $word): bool => ServerTable::matchesAny($word, $item['name']),
        );

        $this->assertSame(4, $page->total);
        $this->assertSame(2, $page->filtered);
        $this->assertSame(['Wall painting'], $page->items->pluck('name')->all());

        // Every word has to match somewhere, as DataTables' own search does.
        $both = ServerTable::fromRequest(Request::create('/', 'GET', ['search' => 'wall paint']))
            ->paginateCollection($items, [], fn (array $item, string $word): bool => ServerTable::matchesAny($word, $item['name']));

        $this->assertSame(['Wall painting'], $both->items->pluck('name')->all());
    }

    // ------------------------------------------------------------------
    // Projects
    // ------------------------------------------------------------------

    public function test_the_projects_page_carries_no_rows_and_the_table_pages_ten_at_a_time(): void
    {
        foreach (range(1, 13) as $number) {
            $this->book($this->project('REF-PAGE-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT)), -1, 3);
        }

        $this->get(route('super-admin.projects'))
            ->assertOk()
            // Handed to the table's script as JSON.
            ->assertSee(str_replace('/', '\\/', route('super-admin.projects.table')), false)
            ->assertDontSee('REF-PAGE-01');

        $first = $this->page('super-admin.projects.table', ['start' => 0, 'length' => 10]);

        $this->assertSame(13, $first['recordsTotal']);
        $this->assertSame(13, $first['recordsFiltered']);
        $this->assertCount(10, $first['rows']);

        // The dialogs behind the rows on the page, and no others.
        $this->assertSame(10, $this->dialogCount('onHoldModal', $first['extras']));

        $second = $this->page('super-admin.projects.table', ['start' => 10, 'length' => 10]);

        $this->assertCount(3, $second['rows']);
        $this->assertSame(3, $this->dialogCount('onHoldModal', $second['extras']));
    }

    public function test_the_projects_table_searches_sorts_and_filters_by_tab(): void
    {
        $this->book($this->project('REF-A', 'Ana Santos'), -1, 3);
        $this->book($this->project('REF-B', 'Ben Cruz'), -1, 3);
        $this->book($this->project('REF-C', 'Cara Santos'), 3, 6);

        $santos = $this->page('super-admin.projects.table', ['search' => 'santos']);

        $this->assertSame(3, $santos['recordsTotal']);
        $this->assertSame(2, $santos['recordsFiltered']);

        // Ordered by Reference No., both ways.
        $ascending = $this->page('super-admin.projects.table', ['order' => [['column' => 1, 'dir' => 'asc']]]);
        $descending = $this->page('super-admin.projects.table', ['order' => [['column' => 1, 'dir' => 'desc']]]);

        $references = fn (array $page): array => collect($page['rows'])
            ->map(fn (string $row): string => preg_match('/REF-[A-C]/', $row, $match) ? $match[0] : '')
            ->all();

        $this->assertSame(['REF-A', 'REF-B', 'REF-C'], $references($ascending));
        $this->assertSame(['REF-C', 'REF-B', 'REF-A'], $references($descending));

        // The tab is a filter on the server, by Project::tabKey().
        $pending = $this->page('super-admin.projects.table', ['tab' => 'pending']);

        $this->assertSame(1, $pending['recordsTotal']);
        $this->assertSame(['REF-C'], $references($pending));
    }

    public function test_the_archive_pages_in_sql(): void
    {
        foreach (['REF-OLD-1', 'REF-OLD-2', 'REF-OLD-3'] as $reference) {
            $this->project($reference, 'Old Client', [
                'status' => 'archived',
                'is_archived' => true,
                'archived_at' => now(),
                'archived_by' => $this->admin->id,
            ]);
        }

        $this->project('REF-LIVE');

        $page = $this->page('super-admin.projects.archived.table', ['length' => 2]);

        $this->assertSame(3, $page['recordsTotal']);
        $this->assertCount(2, $page['rows']);
        $this->assertSame(2, $this->dialogCount('restoreProjectModal', $page['extras']));

        $this->assertSame(1, $this->page('super-admin.projects.archived.table', ['search' => 'REF-OLD-2'])['recordsFiltered']);
    }

    // ------------------------------------------------------------------
    // Schedules and technicians
    // ------------------------------------------------------------------

    public function test_the_schedules_table_lists_only_projects_holding_dates(): void
    {
        $this->book($this->project('REF-BOOKED'), 1, 3);
        $this->project('REF-NO-DATES', 'Ben Cruz', ['status' => 'unscheduled']);

        $page = $this->page('super-admin.schedules.table');

        $this->assertSame(1, $page['recordsTotal']);
        $this->assertStringContainsString('REF-BOOKED', $page['rows'][0]);
    }

    public function test_the_technicians_table_searches_and_narrows_to_pending_requests(): void
    {
        $this->technician($this->account('technician', 'maria@example.test', 'Maria Lopez'));
        $this->technician($this->account('lead_technician', 'jose@example.test', 'Jose Reyes'));

        $page = $this->page('super-admin.technicians.table');

        $this->assertSame(2, $page['recordsTotal']);
        // Leads first.
        $this->assertStringContainsString('Jose Reyes', $page['rows'][0]);

        $this->assertSame(1, $this->page('super-admin.technicians.table', ['search' => 'lopez'])['recordsFiltered']);

        // Nobody is waiting on a specialty decision.
        $this->assertSame(0, $this->page('super-admin.technicians.table', ['specialty' => 'pending'])['recordsTotal']);
    }

    // ------------------------------------------------------------------
    // Reports
    // ------------------------------------------------------------------

    public function test_the_report_archive_sends_its_reports_in_full_for_the_viewer(): void
    {
        $account = $this->account('lead_technician', 'lead@example.test');
        $lead = $this->technician($account);
        $project = $this->project('REF-REPORTS');

        $report = TechnicianReport::create([
            'project_id' => $project->project_id,
            'technician_id' => $lead->technician_id,
            'submitted_by' => $account->id,
            'report_type' => 'incident',
            'report_title' => 'Ladder slipped',
            'report_description' => 'Nobody hurt.',
            'report_date' => CarbonImmutable::today()->toDateString(),
            'is_archived' => true,
            'archived_at' => now(),
            'archived_by' => $this->admin->id,
        ]);

        $page = $this->page('super-admin.reports.archived.table');

        $this->assertSame(1, $page['recordsTotal']);
        $this->assertSame('Nobody hurt.', $page['meta']['payloads'][$report->id]['description']);

        // Searched by the type as it reads.
        $this->assertSame(1, $this->page('super-admin.reports.archived.table', ['search' => 'incident'])['recordsFiltered']);
        $this->assertSame(0, $this->page('super-admin.reports.archived.table', ['search' => 'progress'])['recordsFiltered']);
    }

    public function test_a_leads_report_log_filters_on_the_server(): void
    {
        $account = $this->account('lead_technician', 'lead@example.test');
        $lead = $this->technician($account);
        $first = $this->project('REF-ONE');
        $second = $this->project('REF-TWO');

        foreach ([[$first, 'progress', 0], [$first, 'incident', -40], [$second, 'progress', 0]] as [$project, $type, $days]) {
            TechnicianReport::create([
                'project_id' => $project->project_id,
                'technician_id' => $lead->technician_id,
                'submitted_by' => $account->id,
                'report_type' => $type,
                'report_title' => ucfirst($type).' on '.$project->reference_no,
                'report_description' => 'Description',
                'report_date' => CarbonImmutable::today()->addDays($days)->toDateString(),
            ]);
        }

        $this->actingAs($account);

        $this->assertSame(3, $this->page('technician.reports.table')['recordsTotal']);
        $this->assertSame(2, $this->page('technician.reports.table', ['project_id' => $first->project_id])['recordsTotal']);
        $this->assertSame(1, $this->page('technician.reports.table', ['report_type' => 'incident'])['recordsTotal']);
        $this->assertSame(2, $this->page('technician.reports.table', ['date_filter' => 'today'])['recordsTotal']);
        $this->assertSame(1, $this->page('technician.reports.table', [
            'date_filter' => 'custom',
            'end_date' => CarbonImmutable::today()->subDays(30)->toDateString(),
        ])['recordsTotal']);
    }

    // ------------------------------------------------------------------
    // Task boards
    // ------------------------------------------------------------------

    public function test_the_task_board_carries_cards_and_each_card_pages_its_own_tasks(): void
    {
        $project = $this->project('REF-BOARD');
        $this->book($project, -1, 10);

        foreach (range(1, 12) as $number) {
            Task::create([
                'project_id' => $project->project_id,
                'task_title' => 'Board task '.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
                'task_description' => 'Description',
                'status' => 'unassigned',
            ]);
        }

        $this->get(route('super-admin.tasks.index'))
            ->assertOk()
            ->assertSee('data-project-card', false)
            ->assertSee(route('super-admin.tasks.board-table', $project->project_id), false)
            ->assertSee('12 tasks')
            ->assertDontSee('Board task 01');

        $page = $this->page('super-admin.tasks.board-table', ['length' => 10], ['projectId' => $project->project_id]);

        $this->assertSame(12, $page['recordsTotal']);
        $this->assertCount(10, $page['rows']);
        $this->assertSame(10, $this->dialogCount('taskModal', $page['extras']));

        // A chip narrows the card on the server: none of these has dates or
        // anybody holding it, so every one is "both".
        $both = $this->page('super-admin.tasks.board-table', ['gap' => Task::GAP_BOTH], ['projectId' => $project->project_id]);
        $date = $this->page('super-admin.tasks.board-table', ['gap' => Task::GAP_DATE], ['projectId' => $project->project_id]);

        $this->assertSame(12, $both['recordsTotal']);
        $this->assertSame(0, $date['recordsTotal']);
    }

    public function test_a_linked_task_opens_before_its_card_loads_and_is_not_drawn_twice(): void
    {
        $project = $this->project('REF-LINK');
        $this->book($project, -1, 10);

        $task = Task::create([
            'project_id' => $project->project_id,
            'task_title' => 'Linked task',
            'task_description' => 'Description',
            'status' => 'unassigned',
        ]);

        $this->get(route('super-admin.tasks.index', ['task' => $task->task_id]))
            ->assertOk()
            ->assertSee('id="taskModal'.$task->task_id.'"', false);

        $page = $this->page('super-admin.tasks.board-table', ['linked_task' => $task->task_id], ['projectId' => $project->project_id]);

        $this->assertCount(1, $page['rows']);
        $this->assertStringNotContainsString('id="taskModal'.$task->task_id.'"', $page['extras']);
    }

    public function test_a_technician_board_card_is_only_their_own_project_and_their_own_tasks(): void
    {
        $account = $this->account('technician', 'tech@example.test');
        $technician = $this->technician($account);
        $mate = $this->technician($this->account('technician', 'mate@example.test'));

        $mine = $this->project('REF-MINE');
        $this->book($mine, -1, 10);
        ProjectTechnician::create(['project_id' => $mine->project_id, 'technician_id' => $technician->technician_id]);
        ProjectTechnician::create(['project_id' => $mine->project_id, 'technician_id' => $mate->technician_id]);

        $theirs = $this->project('REF-THEIRS');
        $this->book($theirs, -1, 10);

        foreach ([[$technician, 'My task'], [$mate, 'Mate task']] as [$holder, $title]) {
            Task::create([
                'project_id' => $mine->project_id,
                'technician_id' => $holder->technician_id,
                'task_title' => $title,
                'task_description' => 'Description',
                'status' => 'pending',
                'start_date' => CarbonImmutable::today()->toDateString(),
                'due_date' => CarbonImmutable::today()->addDays(2)->toDateString(),
            ]);
        }

        $this->actingAs($account);

        $page = $this->page('technician.tasks.board-table', [], ['project' => $mine->project_id]);

        $this->assertSame(1, $page['recordsTotal']);
        $this->assertStringContainsString('My task', $page['rows'][0]);

        $this->getJson(route('technician.tasks.board-table', $theirs->project_id))->assertNotFound();
    }
}
