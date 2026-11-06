<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\IncidentReport;
use App\Models\Material;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Models\ProjectMaterialEntry;
use App\Models\Schedule;
use App\Models\StockMovement;
use App\Models\Task;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\User;
use App\Support\PortalHome;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Throwable;

/**
 * Every route against every role, on a full set of demo projects.
 *
 * What each part of the portal is written out here by hand rather than read
 * off the routes, so a door left open or shut by mistake disagrees with it.
 */
class PortalAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Demo#Pass2026';

    private const OFFICE = [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_ADMIN_OFFICER];

    private const INVENTORY = [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN_OFFICER, User::ROLE_PURCHASING_ADMIN];

    private const CREW = [User::ROLE_TECHNICIAN, User::ROLE_LEAD_TECHNICIAN];

    /**
     * Routes narrower than the part of the portal they sit in.
     *
     * @var array<string, array<int, string>>
     */
    private const NARROWER = [
        'super-admin.projects.create' => [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN_OFFICER],
        'super-admin.projects.complete' => [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN_OFFICER],
        'super-admin.projects.details.update' => [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN_OFFICER],
        'super-admin.projects.quotation.update' => [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN_OFFICER],
        'super-admin.projects.completion-photos.store' => [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN_OFFICER],
        'super-admin.projects.completion-photos.destroy' => [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN_OFFICER],
        'super-admin.projects.create.store' => [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN_OFFICER],
        'super-admin.task.store' => [User::ROLE_SUPER_ADMIN],
        'super-admin.projects.checklist.update' => [User::ROLE_SUPER_ADMIN],
        'super-admin.tasks.update' => [User::ROLE_SUPER_ADMIN],
        'super-admin.tasks.complete' => [User::ROLE_SUPER_ADMIN],
        'super-admin.tasks.destroy' => [User::ROLE_SUPER_ADMIN],
        'super-admin.projects.task-form-data' => [User::ROLE_SUPER_ADMIN],
        'super-admin.technician.reports.store' => [User::ROLE_SUPER_ADMIN],
        'super-admin.progress-reports.create' => [User::ROLE_SUPER_ADMIN],
        'super-admin.progress-reports.store' => [User::ROLE_SUPER_ADMIN],
        'super-admin.incident-reports.create' => [User::ROLE_SUPER_ADMIN],
        'super-admin.incident-reports.store' => [User::ROLE_SUPER_ADMIN],
        // Configuration's page opens to both sides.
        'super-admin.configuration.index' => [...self::OFFICE, User::ROLE_PURCHASING_ADMIN],
    ];

    /**
     * What a plain technician's portal stops short of.
     *
     * @var array<int, string>
     */
    private const LEAD_ONLY_PREFIXES = [
        'technician.progress-reports.',
        'technician.incident-reports.',
        'technician.reports',
        'technician.tasks.store',
        'technician.tasks.update',
        'technician.tasks.destroy',
        'technician.projects.complete',
        'technician.projects.task-form-data',
        'technician.projects.checklist.',
        'technician.projects.details.update',
        'technician.projects.completion-photos.',
        'technician.projects.phases.',
        'technician.projects.materials.suggest',
        'technician.projects.materials.requests.',
        'technician.projects.materials.record',
        'technician.projects.materials.void',
    ];

    /**
     * @var array<string, User>
     */
    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV[DemoDataSeeder::PASSWORD_VARIABLE] = $_SERVER[DemoDataSeeder::PASSWORD_VARIABLE] = self::PASSWORD;
        putenv(DemoDataSeeder::PASSWORD_VARIABLE.'='.self::PASSWORD);

        $this->accounts[User::ROLE_SUPER_ADMIN] = $this->account(User::ROLE_SUPER_ADMIN);
        $this->accounts[User::ROLE_ADMIN_OFFICER] = $this->account(User::ROLE_ADMIN_OFFICER);
        $this->accounts[User::ROLE_PURCHASING_ADMIN] = $this->account(User::ROLE_PURCHASING_ADMIN);

        $seeder = app(DemoDataSeeder::class);
        $seeder->withFiles = false;
        $seeder->run();

        foreach ([User::ROLE_ADMIN, User::ROLE_LEAD_TECHNICIAN, User::ROLE_TECHNICIAN] as $role) {
            $this->accounts[$role] = User::query()->where('role', $role)->orderBy('id')->firstOrFail();
        }

        $this->accounts[User::ROLE_CLIENT] = User::query()->where('role', User::ROLE_CLIENT)->orderBy('id')->firstOrFail();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function roles(): array
    {
        return [
            'super admin' => [User::ROLE_SUPER_ADMIN],
            'admin officer' => [User::ROLE_ADMIN_OFFICER],
            'hr admin' => [User::ROLE_ADMIN],
            'purchasing admin' => [User::ROLE_PURCHASING_ADMIN],
            'lead technician' => [User::ROLE_LEAD_TECHNICIAN],
            'technician' => [User::ROLE_TECHNICIAN],
            'client' => [User::ROLE_CLIENT],
        ];
    }

    #[DataProvider('roles')]
    public function test_every_route_this_role_should_not_reach_refuses_it(string $role): void
    {
        $this->actingAs($this->accounts[$role]);

        $before = $this->counts();
        $opened = [];

        foreach ($this->portalRoutes() as $route) {
            if (in_array($role, $this->allowedRoles($route), true)) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $response = $this->call($method, $this->urlFor($route));
                $status = $response->getStatusCode();

                if ($status < 300 || ! in_array($status, [302, 403, 404, 405], true)) {
                    $opened[] = sprintf('%s %s answered %d', $method, $route->getName(), $status);
                }

                if (session()->has('success')) {
                    $opened[] = sprintf('%s %s reported success', $method, $route->getName());
                    session()->forget('success');
                }
            }
        }

        $this->assertSame([], $opened);
        $this->assertSame($before, $this->counts(), 'A refused request changed something.');
    }

    #[DataProvider('roles')]
    public function test_every_page_this_role_may_open_works(string $role): void
    {
        $this->actingAs($this->accounts[$role]);
        $this->withoutExceptionHandling();

        $broken = [];

        foreach ($this->portalRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || ! in_array($role, $this->allowedRoles($route), true)) {
                continue;
            }

            try {
                $status = $this->get($this->urlFor($route))->getStatusCode();

                if ($status >= 500) {
                    $broken[] = $route->getName().' answered '.$status;
                }
            } catch (HttpExceptionInterface $exception) {
                // A route's own refusal of these sample values is not a door.
                if ($exception->getStatusCode() >= 500 || $exception->getStatusCode() === 403 && ! $this->mayBeNarrowedPerRecord($route)) {
                    $broken[] = $route->getName().' answered '.$exception->getStatusCode();
                }
            } catch (Throwable $exception) {
                if (! str_contains($exception::class, 'ModelNotFound') && ! str_contains($exception::class, 'ValidationException')) {
                    $broken[] = $route->getName().': '.$exception::class.' '.mb_substr($exception->getMessage(), 0, 160);
                }
            }
        }

        $this->assertSame([], $broken);
    }

    #[DataProvider('roles')]
    public function test_every_sidebar_link_opens(string $role): void
    {
        $account = $this->accounts[$role];

        if (! $account->isOfficeStaff() && ! $account->needsTechnicianRecord()) {
            $this->assertTrue(true);

            return;
        }

        $this->actingAs($account);

        $home = $this->get(PortalHome::url($account))->assertOk();

        preg_match_all('/class="[^"]*sidebar-link[^"]*"|href="([^"]+)"\s+class="[^"]*sidebar-link/', $home->getContent(), $links);

        foreach (array_filter($links[1]) as $link) {
            $this->get($link)->assertOk();
        }

        $this->assertNotEmpty(array_filter($links[1]), 'No sidebar links were found.');
    }

    public function test_the_sidebar_of_each_office_role_is_its_own(): void
    {
        $expected = [
            User::ROLE_SUPER_ADMIN => ['Dashboard', 'Projects', 'Schedules', 'Task', 'Technicians', 'Reports', 'Material Requests', 'Materials', 'Inventory', 'Stock Movements', 'Configuration'],
            User::ROLE_ADMIN_OFFICER => ['Dashboard', 'Projects', 'Schedules', 'Task', 'Technicians', 'Reports', 'Material Requests', 'Materials', 'Inventory', 'Stock Movements', 'Configuration'],
            User::ROLE_ADMIN => ['Dashboard', 'Projects', 'Schedules', 'Task', 'Technicians', 'Reports', 'Configuration'],
            User::ROLE_PURCHASING_ADMIN => ['Projects', 'Material Requests', 'Materials', 'Inventory', 'Stock Movements', 'Configuration'],
        ];

        foreach ($expected as $role => $labels) {
            $account = $this->accounts[$role];
            $this->actingAs($account);

            $content = $this->get(PortalHome::url($account))->assertOk()->getContent();

            preg_match_all('/admin-sidebar-link.*?<span>(.*?)<\/span>/s', $content, $found);

            $this->assertSame($labels, $found[1], $role);
        }
    }

    public function test_project_details_open_for_the_office_in_every_status(): void
    {
        $bystatus = Project::query()->get()->groupBy(fn (Project $project): string => $project->statusKey())->map->first();

        $this->assertCount(9, $bystatus);

        foreach (self::OFFICE as $role) {
            $this->actingAs($this->accounts[$role]);

            foreach ($bystatus as $status => $project) {
                $response = $this->get(route('super-admin.projects.show', $project->project_id));
                $this->assertSame(200, $response->getStatusCode(), $role.' on '.$status);
            }
        }

        $this->actingAs($this->accounts[User::ROLE_PURCHASING_ADMIN]);

        foreach ($bystatus as $status => $project) {
            $response = $this->get(route('inventory.projects.show', $project->project_id));
            $this->assertSame(200, $response->getStatusCode(), 'purchasing on '.$status);
        }
    }

    public function test_the_crew_sees_only_projects_they_are_on(): void
    {
        foreach (self::CREW as $role) {
            $account = $this->accounts[$role];
            $technicianId = $account->technicianRecord()->technician_id;

            $own = Project::query()->whereHas('projectTechnicians', fn ($team) => $team->where('technician_id', $technicianId))->first();
            $other = Project::query()->where('is_archived', false)->whereDoesntHave('projectTechnicians', fn ($team) => $team->where('technician_id', $technicianId))->first();

            $this->assertNotNull($own, $role);

            $this->actingAs($account);

            $this->get(route('technician.projects.show', $own->project_id))->assertOk();
            $this->assertNotSame(200, $this->get(route('technician.projects.show', $other->project_id))->getStatusCode(), $role);
        }
    }

    public function test_a_client_reads_only_their_own_projects(): void
    {
        $client = $this->accounts[User::ROLE_CLIENT];
        $own = Project::query()->whereHas('client', fn ($record) => $record->where('user_id', $client->id))->firstOrFail();
        $other = Project::query()->whereDoesntHave('client', fn ($record) => $record->where('user_id', $client->id))->firstOrFail();

        $this->actingAs($client);

        $this->assertSame(1, Client::query()->where('user_id', $client->id)->count());
        $this->assertNotSame(200, $this->post(route('public.projects.confirm', $other->project_id))->getStatusCode());
        $this->assertSame($other->status, $other->fresh()->status);
        $this->assertNotNull($own);
    }

    public function test_a_report_document_is_only_read_by_those_it_belongs_to(): void
    {
        $report = TechnicianReport::query()->whereNotNull('report_data')->firstOrFail();
        $team = $report->project->projectTechnicians()->pluck('technician_id');

        foreach (self::OFFICE as $role) {
            $this->actingAs($this->accounts[$role]);
            $this->get(route('reports.document', $report->id))->assertOk();
        }

        $outsider = User::query()->where('role', User::ROLE_TECHNICIAN)
            ->whereDoesntHave('technician', fn ($technician) => $technician->whereIn('technician_id', $team))
            ->firstOrFail();

        foreach ([$this->accounts[User::ROLE_PURCHASING_ADMIN], $this->accounts[User::ROLE_CLIENT], $outsider] as $account) {
            $this->actingAs($account);
            $this->assertNotSame(200, $this->get(route('reports.document', $report->id))->getStatusCode(), $account->role);
        }
    }

    public function test_an_incident_is_reviewed_by_the_office_only(): void
    {
        $incident = IncidentReport::query()->firstOrFail();
        $status = $incident->status;
        $next = $status === IncidentReport::STATUS_CLOSED ? IncidentReport::STATUS_OPEN : IncidentReport::STATUS_CLOSED;

        foreach ([User::ROLE_PURCHASING_ADMIN, User::ROLE_LEAD_TECHNICIAN, User::ROLE_TECHNICIAN, User::ROLE_CLIENT] as $role) {
            $this->actingAs($this->accounts[$role]);
            $this->put(route('super-admin.incident-reports.status', $incident->incident_report_id), ['status' => $next]);
            $this->assertSame($status, $incident->fresh()->status, $role);
        }
    }

    /**
     * @return array<int, LaravelRoute>
     */
    private function portalRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (LaravelRoute $route): bool => str_starts_with((string) $route->getName(), 'super-admin.')
                || str_starts_with((string) $route->getName(), 'inventory.')
                || str_starts_with((string) $route->getName(), 'technician.'))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function allowedRoles(LaravelRoute $route): array
    {
        $name = (string) $route->getName();

        // What only the owner may do is named on the route itself, and these
        // requests confirm that the door really does turn the rest away.
        if (in_array('role:super_admin', $route->gatherMiddleware(), true)) {
            return [User::ROLE_SUPER_ADMIN];
        }

        // Owner-only too, asked inside these controllers rather than on the
        // route, and hidden from everyone else's Configuration page.
        foreach (['project-types.', 'detail-forms.', 'phase-templates.'] as $section) {
            if (str_starts_with($name, 'super-admin.configuration.'.$section)) {
                return [User::ROLE_SUPER_ADMIN];
            }
        }

        if (isset(self::NARROWER[$name])) {
            return self::NARROWER[$name];
        }

        if (str_starts_with($name, 'super-admin.')) {
            return self::OFFICE;
        }

        if (str_starts_with($name, 'inventory.')) {
            return self::INVENTORY;
        }

        foreach (self::LEAD_ONLY_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return [User::ROLE_LEAD_TECHNICIAN];
            }
        }

        return self::CREW;
    }

    /**
     * The crew's routes answer per project and per task, on top of the door.
     */
    private function mayBeNarrowedPerRecord(LaravelRoute $route): bool
    {
        return str_starts_with((string) $route->getName(), 'technician.')
            || str_contains($route->uri(), '{');
    }

    /**
     * The route's address, filled with real records where there are any.
     */
    private function urlFor(LaravelRoute $route): string
    {
        $parameters = [];

        foreach ($route->parameterNames() as $parameter) {
            $parameters[$parameter] = match ($parameter) {
                'id', 'project', 'projectId' => $this->sampleProjectId(),
                'task' => Task::query()->where('project_id', $this->sampleProjectId())->value('task_id') ?? 1,
                'schedule' => Schedule::query()->value('schedule_id') ?? 1,
                'technician' => Technician::query()->value('technician_id') ?? 1,
                'report' => TechnicianReport::query()->value('id') ?? 1,
                'material' => Material::query()->value('material_id') ?? 1,
                'materialRequest' => MaterialRequest::query()->value('material_request_id') ?? 1,
                'movement' => StockMovement::query()->value('stock_movement_id') ?? 1,
                'entry' => ProjectMaterialEntry::query()->value('material_entry_id') ?? 1,
                'incidentReport' => IncidentReport::query()->value('incident_report_id') ?? 1,
                'date' => now()->toDateString(),
                'section' => 'team',
                default => 1,
            };
        }

        return route($route->getName(), $parameters);
    }

    /**
     * A live project, and for the crew one they are actually on: being turned
     * away from somebody else's is a different question from the door's.
     */
    private function sampleProjectId(): int
    {
        $user = auth()->user();

        return (int) Project::query()
            ->when($user?->needsTechnicianRecord(), fn ($query) => $query
                ->whereHas('projectTechnicians', fn ($team) => $team->where('technician_id', $user->technicianRecord()->technician_id)))
            ->where('is_archived', false)
            ->whereNotIn('status', Project::READ_ONLY_STATUSES)
            ->where('on_hold', false)
            ->whereHas('schedules')
            ->orderByDesc('project_id')
            ->value('project_id');
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'projects' => Project::count(),
            'tasks' => Task::count(),
            'open tasks' => Task::query()->where('status', '!=', 'completed')->count(),
            'schedules' => Schedule::count(),
            'reports' => TechnicianReport::count(),
            'movements' => StockMovement::count(),
            'entries' => ProjectMaterialEntry::count(),
            'requests' => MaterialRequest::count(),
            'materials' => Material::count(),
            'users' => User::count(),
        ];
    }

    private function account(string $role): User
    {
        $sequence = User::count() + 1;

        return User::create([
            'user_code' => sprintf('EMP-%04d', $sequence),
            'name' => 'Person '.$sequence,
            'first_name' => 'Person',
            'last_name' => (string) $sequence,
            'email' => 'person'.$sequence.'@example.test',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);
    }
}
