<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectCompletionPhoto;
use App\Models\ProjectTechnician;
use App\Models\ProjectType;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\TaskImage;
use App\Models\TechnicianReport;
use App\Models\TechnicianReportImage;
use App\Models\User;
use App\Services\ProjectStatusRules;
use App\Services\ProjectTeam;
use App\Support\UploadStore;
use Database\Seeders\DemoDataCatalog;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * The demonstration data: fifty accounts and fifty projects that have to look
 * like real work on every screen, on whatever day the seeder is run.
 */
class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Demo#Pass2026';

    /**
     * How many projects each status tab should hold, keyed by statusKey().
     *
     * @var array<string, int>
     */
    private const EXPECTED_STATUSES = [
        'unscheduled' => 4,
        'pending' => 8,
        'ongoing' => 5,
        'overdue' => 3,
        'on_hold' => 3,
        Project::STATUS_AWAITING_CLIENT_CONFIRMATION => 4,
        'completed' => 15,
        'cancelled' => 4,
        'archived' => 4,
    ];

    /**
     * @var array{0: ?string, 1: ?string, 2: string|false}
     */
    private array $previousPassword;

    protected function setUp(): void
    {
        parent::setUp();

        $variable = DemoDataSeeder::PASSWORD_VARIABLE;
        $this->previousPassword = [$_ENV[$variable] ?? null, $_SERVER[$variable] ?? null, getenv($variable)];
        $this->setPassword(self::PASSWORD);
    }

    protected function tearDown(): void
    {
        $variable = DemoDataSeeder::PASSWORD_VARIABLE;
        [$env, $server, $put] = $this->previousPassword;

        if ($env === null) {
            unset($_ENV[$variable]);
        } else {
            $_ENV[$variable] = $env;
        }

        if ($server === null) {
            unset($_SERVER[$variable]);
        } else {
            $_SERVER[$variable] = $server;
        }

        putenv($put === false ? $variable : $variable.'='.$put);

        parent::tearDown();
    }

    private function setPassword(string $value): void
    {
        $variable = DemoDataSeeder::PASSWORD_VARIABLE;

        $_ENV[$variable] = $_SERVER[$variable] = $value;
        putenv($variable.'='.$value);
    }

    private function runSeeder(bool $withFiles = false): void
    {
        $seeder = app(DemoDataSeeder::class);
        $seeder->withFiles = $withFiles;
        $seeder->run();
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        return Project::query()
            ->get()
            ->countBy(fn (Project $project): string => $project->statusKey())
            ->sortKeys()
            ->all();
    }

    public function test_it_refuses_a_weak_password_and_writes_nothing(): void
    {
        $this->setPassword('password');

        try {
            $this->runSeeder();
            $this->fail('The seeder accepted a weak password.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('DEMO_ACCOUNT_PASSWORD does not meet the password policy.', $exception->getMessage());
        }

        $this->assertSame(0, User::count());
        $this->assertSame(0, Project::count());
    }

    public function test_it_seeds_every_account_ready_to_sign_in(): void
    {
        $this->runSeeder();

        $seeded = User::query()->where('email', 'like', '%@'.DemoDataCatalog::EMAIL_DOMAIN)->get();

        $this->assertSame(
            ['admin' => 10, 'client' => 20, 'lead_technician' => 10, 'technician' => 10],
            $seeded->countBy('role')->sortKeys()->all()
        );

        foreach ($seeded as $user) {
            $this->assertTrue(Hash::check(self::PASSWORD, $user->password), $user->email.' does not use the shared password.');
            $this->assertTrue($user->canLogin());
            $this->assertFalse($user->must_change_password);
            $this->assertTrue($user->hasVerifiedEmail());
            $this->assertMatchesRegularExpression('/^09\d{9}$/', (string) $user->contact_number);

            if ($user->isClient()) {
                $this->assertFalse($user->requiresTermsAcceptance(), $user->email.' would be stopped by the Terms.');
                $this->assertStringStartsWith('CLI-', $user->user_code);
            } else {
                $this->assertStringStartsWith('EMP-', $user->user_code);
            }

            if ($user->needsTechnicianRecord()) {
                $this->assertNotEmpty($user->technician?->skills, $user->email.' has no specialty.');
            }
        }

        $this->assertSame(50, $seeded->pluck('email')->unique()->count());
        $this->assertCount(50, DemoDataSeeder::accountList());
    }

    public function test_it_covers_every_status_with_a_full_team(): void
    {
        $this->runSeeder();

        $this->assertSame(50, Project::count());
        $this->assertSame(collect(self::EXPECTED_STATUSES)->sortKeys()->all(), $this->statusCounts());

        $leadIds = User::query()->where('role', User::ROLE_LEAD_TECHNICIAN)->pluck('id');
        $statusRules = app(ProjectStatusRules::class);
        $projectTeam = app(ProjectTeam::class);
        $unskilled = [];

        foreach (Project::query()->with(['projectTypes', 'clients.account'])->get() as $project) {
            $team = ProjectTechnician::query()
                ->where('project_id', $project->project_id)
                ->with('technician.account', 'technician.skills')
                ->get();

            $leads = $team->where('team_role', User::ROLE_LEAD_TECHNICIAN);

            $this->assertCount(1, $leads, $project->name.' has no single lead.');
            $this->assertTrue($leadIds->contains($leads->first()->technician->account_id));
            $this->assertGreaterThanOrEqual(1, $team->where('team_role', User::ROLE_TECHNICIAN)->count(), $project->name.' has no technician.');

            $typeNames = $project->projectTypes->pluck('type_name')->map(fn (string $name): string => mb_strtolower($name));

            foreach ($team as $member) {
                $skills = $member->technician->skills->pluck('skill_name')->map(fn (string $name): string => mb_strtolower($name));

                if ($typeNames->intersect($skills)->isEmpty()) {
                    $unskilled[] = $member->technician->account->email.' on '.$project->name;
                }
            }

            $this->assertNotNull($project->clients->first()?->account, $project->name.' is not linked to a Registered User.');
            $this->assertNotNull($project->reference_no);

            // The stored status is what the dates say it is.
            $implied = $statusRules->statusFor($project);
            $this->assertTrue($implied === null || $implied === $project->status, $project->name.' is '.$project->status.' but its dates say '.$implied);

            $this->assertTrue($projectTeam->missingScheduleLinks($project)->isEmpty(), $project->name.' has crew missing from its dates.');
        }

        $this->assertSame([], $unskilled, 'Crew without the specialty for their project.');
    }

    public function test_nobody_is_booked_on_two_live_projects_on_the_same_day(): void
    {
        $this->runSeeder();

        $bookings = [];

        $schedules = Schedule::query()
            ->whereHas('project', fn ($project) => $project->whereIn('status', Project::ACTIVE_PROJECT_STATUSES))
            ->with('scheduleTechnicians.projectTechnician')
            ->get();

        foreach ($schedules as $schedule) {
            foreach ($schedule->scheduleTechnicians as $link) {
                $technicianId = $link->projectTechnician->technician_id;

                foreach ($bookings[$technicianId] ?? [] as [$projectId, $from, $to]) {
                    if ($projectId === $schedule->project_id) {
                        continue;
                    }

                    $this->assertFalse(
                        $schedule->startsOn()->toDateString() <= $to && $from <= $schedule->endsOn()->toDateString(),
                        "Technician {$technicianId} is double-booked."
                    );
                }

                $bookings[$technicianId][] = [$schedule->project_id, $schedule->startsOn()->toDateString(), $schedule->endsOn()->toDateString()];
            }
        }

        $this->assertNotEmpty($bookings);
    }

    public function test_no_calendar_day_shows_more_than_three_projects(): void
    {
        $this->runSeeder();

        // Drawn by the calendar's own rules: archived work is left off and a
        // cancelled job stops on the day it was called off.
        $projectsByDay = [];

        foreach (Project::query()->with('schedules')->get() as $project) {
            if (! $project->showsOnCalendar()) {
                continue;
            }

            $cutoff = $project->calendarCutoff();

            foreach ($project->schedules as $schedule) {
                if (! $schedule->startsOnOrBefore($cutoff)) {
                    continue;
                }

                $last = $cutoff !== null && $schedule->endsOn()->gt($cutoff) ? $cutoff : $schedule->endsOn();

                for ($day = $schedule->startsOn(); $day->lte($last); $day = $day->addDay()) {
                    $projectsByDay[$day->toDateString()][$project->project_id] = true;
                }
            }
        }

        $counts = collect($projectsByDay)->map(fn (array $projects): int => count($projects));

        $this->assertLessThanOrEqual(3, $counts->max(), 'A calendar day shows more than three projects.');

        // Three is the exception: a full day is rare, two is the busy norm.
        $this->assertGreaterThan(
            $counts->filter(fn (int $count): bool => $count === 3)->count() * 10,
            $counts->filter(fn (int $count): bool => $count === 2)->count()
        );
    }

    public function test_the_statuses_still_hold_fourteen_days_later(): void
    {
        $this->runSeeder();

        $this->travel(14)->days();

        $this->assertSame(collect(self::EXPECTED_STATUSES)->sortKeys()->all(), $this->statusCounts());

        $statusRules = app(ProjectStatusRules::class);

        foreach (Project::query()->get() as $project) {
            $implied = $statusRules->statusFor($project);
            $this->assertTrue($implied === null || $implied === $project->status, $project->name.' would change status.');
            $this->assertFalse($project->isPastTargetDate(), $project->name.' would be past its target date.');
        }
    }

    public function test_the_work_history_matches_each_status(): void
    {
        $this->runSeeder();

        foreach (Project::query()->where('status', 'completed')->get() as $project) {
            $this->assertNotNull($project->completion_method);
            $this->assertNotNull($project->client_confirmed_at);
            $this->assertSame(0, Task::query()->where('project_id', $project->project_id)->where('status', '!=', 'completed')->count());
        }

        $overdueWithOpenTasks = Project::query()->overdue()->get()
            ->filter(fn (Project $project): bool => Task::query()->where('project_id', $project->project_id)->where('status', 'pending')->exists());

        $this->assertCount(3, $overdueWithOpenTasks);

        $this->assertTrue(Project::query()->where('status', 'ongoing')->get()->every(
            fn (Project $project): bool => $project->phase_setup_status === Project::PHASE_SETUP_FINALIZED
        ));

        $this->assertSame(4, Project::query()->where('status', Project::STATUS_AWAITING_CLIENT_CONFIRMATION)->whereNotNull('completion_requested_at')->count());
        $this->assertSame(3, Project::query()->where('on_hold', true)->whereNotNull('held_on')->count());
        $this->assertSame(4, Project::query()->whereNotNull('cancelled_at')->where('is_archived', false)->count());
        $this->assertTrue(ActivityLog::query()->where('action', ActivityLog::PROJECT_CREATED)->count() === 50);
    }

    public function test_both_kinds_of_technician_report_have_data(): void
    {
        $this->runSeeder();

        $reports = TechnicianReport::query()->active()->get();

        $this->assertGreaterThanOrEqual(30, $reports->where('report_type', 'progress')->count());
        $this->assertSame(12, $reports->where('report_type', 'incident')->count());

        // Every project with work under way has something from its lead.
        foreach (Project::query()->whereIn('status', ['ongoing', Project::STATUS_AWAITING_CLIENT_CONFIRMATION])->orWhere('on_hold', true)->get() as $project) {
            $this->assertTrue(
                $reports->where('project_id', $project->project_id)->where('report_type', 'progress')->isNotEmpty(),
                $project->name.' has no progress report.'
            );
        }

        $today = Schedule::businessToday()->toDateString();

        foreach ($reports as $report) {
            $this->assertNotEmpty($report->report_title);
            $this->assertNotEmpty($report->report_description);
            $this->assertLessThan($today, $report->report_date->toDateString(), 'A report is dated today or later.');
            $this->assertSame(User::ROLE_LEAD_TECHNICIAN, User::find($report->submitted_by)?->role);
        }
    }

    public function test_every_portal_opens_on_the_seeded_data(): void
    {
        $this->runSeeder();

        $admin = User::query()->where('email', 'cristina.reyes@'.DemoDataCatalog::EMAIL_DOMAIN)->firstOrFail();
        $owner = User::factory()->create();
        $owner->forceFill(['role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE, 'is_archived' => false])->save();

        foreach ([
            'super-admin.dashboard', 'super-admin.projects', 'super-admin.schedules.index', 'super-admin.tasks.index',
            'super-admin.technicians.index', 'super-admin.reports.index', 'super-admin.reports.technician',
        ] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }

        foreach ([
            'super-admin.dashboard', 'super-admin.projects', 'super-admin.projects.archived', 'super-admin.reports.system',
            'super-admin.configuration.activity-logs', 'super-admin.configuration.users.employees',
            'super-admin.configuration.users.clients',
        ] as $route) {
            $this->actingAs($owner)->get(route($route))->assertOk();
        }

        foreach (Project::query()->pluck('project_id') as $projectId) {
            $status = $this->actingAs($admin)->get(route('super-admin.projects.show', $projectId))->status();
            $this->assertLessThan(500, $status, "Project {$projectId} fails to open for an administrator.");
        }

        foreach ([User::ROLE_LEAD_TECHNICIAN, User::ROLE_TECHNICIAN] as $role) {
            $crew = User::query()->where('role', $role)->where('email', 'like', '%@'.DemoDataCatalog::EMAIL_DOMAIN)->get();

            foreach ($crew as $member) {
                // Reports are filed by leads, so only a lead has the page.
                $routes = $member->isLeadTechnician()
                    ? ['technician.projects', 'technician.tasks', 'technician.schedule', 'technician.reports']
                    : ['technician.projects', 'technician.tasks', 'technician.schedule'];

                foreach ($routes as $route) {
                    $this->actingAs($member)->get(route($route))->assertOk();
                }

                foreach ($member->technician->projectHistory()->pluck('project_id') as $projectId) {
                    $status = $this->actingAs($member)->get(route('technician.projects.show', $projectId))->status();
                    $this->assertLessThan(500, $status, "Project {$projectId} fails to open for {$member->email}.");
                }
            }
        }

        foreach (User::query()->where('role', User::ROLE_CLIENT)->get() as $client) {
            $this->actingAs($client)->get(route('public.projects'))->assertOk();

            // Archived work is taken off the client's own pages on purpose.
            foreach ($client->assignedProjects()->where('is_archived', false)->pluck('tbl_projects.project_id') as $projectId) {
                $this->actingAs($client)->get(route('public.projects.show', $projectId))->assertOk();
            }
        }
    }

    public function test_running_it_twice_adds_nothing(): void
    {
        $this->runSeeder();

        $counts = [User::count(), Project::count(), Task::count(), ActivityLog::count(), ProjectType::count()];

        $this->runSeeder();

        $this->assertSame($counts, [User::count(), Project::count(), Task::count(), ActivityLog::count(), ProjectType::count()]);
    }

    public function test_it_reuses_a_project_type_the_site_already_has(): void
    {
        ProjectType::create(['type_name' => 'Heating Ventillation']);

        $this->runSeeder();

        $this->assertSame(6, ProjectType::count());
        $this->assertTrue(ProjectType::query()->where('type_name', 'Heating Ventillation')->exists());
    }

    public function test_every_project_carries_its_documents_and_photos_under_fifty_kilobytes(): void
    {
        $this->runSeeder(withFiles: true);

        try {
            $finished = Project::query()
                ->whereIn('status', ['completed', Project::STATUS_AWAITING_CLIENT_CONFIRMATION])
                ->orWhere('pre_archive_status', 'completed')
                ->pluck('project_id');

            $this->assertCount(22, $finished);

            foreach ($finished as $projectId) {
                $this->assertSame(2, ProjectCompletionPhoto::query()->where('project_id', $projectId)->count());
            }

            $this->assertSame(0, TechnicianReport::query()->doesntHave('images')->count(), 'A technician report has no photo.');
            $this->assertGreaterThan(50, TaskImage::count());

            $photos = collect()
                ->concat(ProjectCompletionPhoto::query()->pluck('photo_path'))
                ->concat(TechnicianReportImage::query()->pluck('image_path'))
                ->concat(TaskImage::query()->pluck('image_path'));

            $this->assertSame($photos->count(), $photos->unique()->count(), 'Two records share one photo file.');

            // And the office can actually open one of each.
            $admin = User::query()->where('email', 'cristina.reyes@'.DemoDataCatalog::EMAIL_DOMAIN)->firstOrFail();

            $this->actingAs($admin)->get(route('media.completion-photo', ProjectCompletionPhoto::query()->value('completion_photo_id')))->assertOk();
            $this->actingAs($admin)->get(route('media.report-image', TechnicianReportImage::query()->value('id')))->assertOk();
            $this->actingAs($admin)->get(route('media.task-image', TaskImage::query()->value('id')))->assertOk();
            $this->actingAs($admin)->get(route('media.document', Document::query()->value('document_id')))->assertOk();

            foreach ($photos as $path) {
                $contents = (string) UploadStore::disk()->get($path);

                $this->assertStringStartsWith("\xFF\xD8\xFF", $contents, $path.' is not a JPEG.');
                $this->assertLessThan(DemoDataSeeder::MAX_FILE_BYTES, strlen($contents), $path.' is over 50 KB.');
            }

            foreach (Project::query()->with('clients')->get() as $project) {
                $expected = $project->clients->first()->isCommercial()
                    ? ['assessment', 'contract', 'quotation']
                    : ['assessment', 'quotation'];

                $documents = Document::query()->where('project_id', $project->project_id)->get();

                $this->assertSame($expected, $documents->pluck('document_type')->sort()->values()->all(), $project->name);

                foreach ($documents as $document) {
                    $contents = (string) UploadStore::disk()->get($document->document_path);

                    $this->assertStringStartsWith('%PDF', $contents);
                    $this->assertLessThan(DemoDataSeeder::MAX_FILE_BYTES, strlen($contents), $document->document_name.' is over 50 KB.');
                }
            }
        } finally {
            collect()
                ->concat(Document::query()->pluck('document_path'))
                ->concat(ProjectCompletionPhoto::query()->pluck('photo_path'))
                ->concat(TechnicianReportImage::query()->pluck('image_path'))
                ->concat(TaskImage::query()->pluck('image_path'))
                ->each(fn (string $path) => UploadStore::remove($path));
        }
    }
}
