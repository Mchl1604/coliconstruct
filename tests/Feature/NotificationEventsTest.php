<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Skill;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The events that put something in somebody's bell: who is told, and where
 * clicking it takes them.
 */
class NotificationEventsTest extends TestCase
{
    use RefreshDatabase;

    private static int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::$sequence = 0;
    }

    private function employee(string $role, string $email): User
    {
        // Suffixed with a counter of its own: actingAsSuperAdmin() seeds
        // EMP-0001, so counting rows would collide with it.
        $sequence = ++self::$sequence;

        return User::create([
            'user_code' => 'EMP-9'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
            'name' => ucfirst(str_replace('_', ' ', $role)).' '.$sequence,
            'first_name' => ucfirst(str_replace('_', ' ', $role)),
            'last_name' => 'Person'.$sequence,
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);
    }

    private function technician(string $name, string $role = 'technician'): Technician
    {
        $user = $this->employee($role, strtolower(str_replace(' ', '.', $name)).'@example.test');
        $user->forceFill(['name' => $name])->save();

        return Technician::create([
            'account_id' => $user->id,
            'role' => $role,
        ]);
    }

    private function project(string $status = 'ongoing'): Project
    {
        return Project::create([
            'name' => 'Warehouse CCTV Installation',
            'reference_no' => 'PRJ-'.uniqid(),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
        ]);
    }

    private function assign(Project $project, Technician $technician): ProjectTechnician
    {
        return ProjectTechnician::firstOrCreate([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);
    }

    private function book(Project $project, Technician $technician, string $start, string $end): Schedule
    {
        $projectTechnician = $this->assign($project, $technician);

        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $start.' 00:00:00',
            'end_datetime' => $end.' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        ScheduleTechnician::create([
            'schedule_id' => $schedule->schedule_id,
            'project_technician_id' => $projectTechnician->project_technician_id,
        ]);

        return $schedule;
    }

    /**
     * @return Collection<int, Notification>
     */
    private function notificationsFor(User $user)
    {
        return Notification::forUser($user)->latestFirst()->get();
    }

    private function titlesFor(User $user): array
    {
        return $this->notificationsFor($user)->pluck('title')->all();
    }

    // ------------------------------------------------------------------
    // Projects
    // ------------------------------------------------------------------

    public function test_a_technician_added_to_a_project_is_told(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $lead = $this->technician('Lead Person', 'lead_technician');
        $joining = $this->technician('Bea Free');

        $this->assign($project, $lead);

        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [$joining->technician_id],
        ]);

        $entry = $this->notificationsFor($joining->account)->first();

        $this->assertNotNull($entry);
        $this->assertSame('New Project Assignment', $entry->title);
        $this->assertStringContainsString($project->reference_no, $entry->message);
        $this->assertSame(Notification::MODULE_PROJECTS, $entry->module);
        // Clicking it lands on the technician's own copy of the project page.
        // Stored as a path: the reminder run writes these from the console,
        // where an absolute URL would be built from APP_URL.
        $this->assertSame(route('technician.projects.show', $project->project_id, false), $entry->url);
    }

    public function test_the_lead_is_told_who_joined_their_project(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $lead = $this->technician('Lead Person', 'lead_technician');
        $joining = $this->technician('Bea Free');

        $this->assign($project, $lead);

        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [$joining->technician_id],
        ]);

        $this->assertContains('Technician Joined Your Project', $this->titlesFor($lead->account));
    }

    public function test_a_technician_dropped_from_a_project_is_told(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $lead = $this->technician('Lead Person', 'lead_technician');
        $leaving = $this->technician('Leaving Person');

        $this->assign($project, $lead);
        $this->assign($project, $leaving);

        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [],
        ]);

        $this->assertContains('Removed From Project', $this->titlesFor($leaving->account));
    }

    public function test_completing_a_project_reaches_the_team_and_the_client(): void
    {
        $admin = $this->employee('admin', 'admin@example.test');
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $technician = $this->technician('Bea Free');
        $this->assign($project, $technician);

        // The completion rules refuse a project with no work recorded on it,
        // and getting past them needs a stated override - which is not what
        // this test is about.
        Task::create([
            'project_id' => $project->project_id,
            'task_title' => 'Work that was carried out',
            'task_description' => 'Description',
            'status' => 'completed',
            'completed_at' => CarbonImmutable::now(),
        ]);

        $clientAccount = $this->employee('client', 'client@example.test');
        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Residential',
            'firstname' => 'A',
            'surname' => 'Client',
            'fullname' => 'A Client',
            'email_address' => $clientAccount->email,
        ]);

        $this->post(route('super-admin.projects.complete', $project->project_id), [
            'completion_date' => CarbonImmutable::today()->toDateString(),
            'completion_summary' => 'All done.',
        ]);

        // Completing no longer closes a project outright: it hands it to the
        // client, so that is what everybody is told about.
        $this->assertContains('Project Awaiting Client Confirmation', $this->titlesFor($technician->account));
        $this->assertContains('Project Awaiting Client Confirmation', $this->titlesFor($admin));
        $this->assertContains('Please Confirm Your Completed Project', $this->titlesFor($clientAccount));
    }

    /**
     * A client should never be shown an internal reference number.
     */
    public function test_the_client_message_uses_the_project_name(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $clientAccount = $this->employee('client', 'client@example.test');

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Residential',
            'firstname' => 'A',
            'surname' => 'Client',
            'fullname' => 'A Client',
            'email_address' => $clientAccount->email,
        ]);

        $this->post(route('super-admin.projects.cancel', $project->project_id), [
            'cancellation_date' => CarbonImmutable::today()->toDateString(),
            'cancellation_reason' => 'Client withdrew.',
        ]);

        $entry = $this->notificationsFor($clientAccount)->first();

        $this->assertNotNull($entry);
        $this->assertStringContainsString($project->name, $entry->message);
        $this->assertStringNotContainsString($project->reference_no, $entry->message);
    }

    /**
     * Oversight notifications are a record of what the business did, so an
     * administrator keeps their own actions. A Super Admin who runs most of
     * the work would otherwise have an empty bell while everyone else read
     * the news of it.
     */
    public function test_an_administrator_is_notified_about_their_own_action(): void
    {
        $actor = $this->actingAsSuperAdmin();

        $project = $this->project();

        $this->post(route('super-admin.projects.cancel', $project->project_id), [
            'cancellation_date' => CarbonImmutable::today()->toDateString(),
            'cancellation_reason' => 'Client withdrew.',
        ]);

        $this->assertContains('Project Cancelled', $this->titlesFor($actor));
    }

    /**
     * The exclusion survives where the message is addressed to a person about
     * themselves: "you have been assigned" is nonsense sent to the person who
     * did the assigning.
     */
    public function test_a_personal_message_still_skips_the_person_who_caused_it(): void
    {
        $admin = $this->employee('admin', 'admin@example.test');

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();

        $project = $this->project();
        $worker = $this->technician('Bea Free');
        $this->book($project, $worker, $day10, $day10);

        $task = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $worker->technician_id,
            'task_title' => 'Install CCTV Cameras',
            'task_description' => 'Mount and wire the cameras.',
            'start_date' => $day10,
            'due_date' => $day10,
            'status' => 'pending',
        ]);

        // The technician closes their own task.
        $this->actingAs($worker->account)
            ->post(route('technician.tasks.complete', $task->task_id), [
                'completion_notes' => 'Cameras mounted and tested.',
            ]);

        // They did it, so they are not told about it...
        $this->assertNotContains('Task Completed', $this->titlesFor($worker->account));
        // ...while the administrators keep the record.
        $this->assertContains('Task Completed', $this->titlesFor($admin));
    }

    // ------------------------------------------------------------------
    // Schedule
    // ------------------------------------------------------------------

    public function test_moving_the_dates_tells_the_team(): void
    {
        $this->actingAsSuperAdmin();

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();
        $day12 = CarbonImmutable::today()->addDays(12)->toDateString();

        $project = $this->project();
        $technician = $this->technician('Bea Free');
        $schedule = $this->book($project, $technician, $day10, $day10);

        $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [
                ['schedule_id' => $schedule->schedule_id, 'start_date' => $day10, 'end_date' => $day12],
            ],
        ]);

        $entry = $this->notificationsFor($technician->account)
            ->firstWhere('title', 'Project Schedule Changed');

        $this->assertNotNull($entry);
        $this->assertSame(Notification::MODULE_SCHEDULE, $entry->module);
        $this->assertSame(route('technician.schedule', [], false), $entry->url);
    }

    /**
     * Saving the same dates back is not news.
     */
    public function test_saving_unchanged_dates_tells_nobody(): void
    {
        $this->actingAsSuperAdmin();

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();

        $project = $this->project();
        $technician = $this->technician('Bea Free');
        $schedule = $this->book($project, $technician, $day10, $day10);

        $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [
                ['schedule_id' => $schedule->schedule_id, 'start_date' => $day10, 'end_date' => $day10],
            ],
        ]);

        $this->assertNotContains('Project Schedule Changed', $this->titlesFor($technician->account));
    }

    // ------------------------------------------------------------------
    // Tasks
    // ------------------------------------------------------------------

    public function test_a_new_task_tells_the_technician_and_the_lead(): void
    {
        $this->actingAsSuperAdmin();

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();

        $project = $this->project();
        $lead = $this->technician('Lead Person', 'lead_technician');
        $worker = $this->technician('Bea Free');

        $this->book($project, $lead, $day10, $day10);
        $this->assign($project, $worker);

        $this->post(route('super-admin.task.store', $project->project_id), [
            'task_title' => 'Install CCTV Cameras',
            'task_description' => 'Mount and wire the cameras.',
            'technician_id' => $worker->technician_id,
            'start_date' => $day10,
            'due_date' => $day10,
        ]);

        $assigned = $this->notificationsFor($worker->account)->first();

        $this->assertNotNull($assigned);
        $this->assertSame('New Task Assignment', $assigned->title);
        $this->assertStringContainsString('Install CCTV Cameras', $assigned->message);
        $this->assertSame(Notification::MODULE_TASKS, $assigned->module);

        $this->assertContains('New Task On Your Project', $this->titlesFor($lead->account));
    }

    /**
     * A task has no page of its own - it opens in a modal - so the link
     * carries the task id for the page to open.
     */
    public function test_a_task_notification_links_to_the_task_itself(): void
    {
        $this->actingAsSuperAdmin();

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();

        $project = $this->project();
        $worker = $this->technician('Bea Free');
        $this->book($project, $worker, $day10, $day10);

        $this->post(route('super-admin.task.store', $project->project_id), [
            'task_title' => 'Install CCTV Cameras',
            'task_description' => 'Mount and wire the cameras.',
            'technician_id' => $worker->technician_id,
            'start_date' => $day10,
            'due_date' => $day10,
        ]);

        $task = Task::first();
        $entry = $this->notificationsFor($worker->account)->first();

        $this->assertSame(
            route('technician.tasks', ['task' => $task->task_id], false),
            $entry->url
        );
    }

    public function test_reassigning_tells_both_technicians(): void
    {
        $this->actingAsSuperAdmin();

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();

        $project = $this->project();
        $from = $this->technician('From Person');
        $to = $this->technician('To Person');

        $this->book($project, $from, $day10, $day10);
        $this->assign($project, $to);

        $task = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $from->technician_id,
            'task_title' => 'Install CCTV Cameras',
            'task_description' => 'Mount and wire the cameras.',
            'start_date' => $day10,
            'due_date' => $day10,
            'status' => 'pending',
        ]);

        $this->put(route('super-admin.tasks.update', $task->task_id), [
            'task_title' => 'Install CCTV Cameras',
            'task_description' => 'Mount and wire the cameras.',
            'technician_id' => $to->technician_id,
            'start_date' => $day10,
            'due_date' => $day10,
        ]);

        $this->assertContains('Task Assigned To You', $this->titlesFor($to->account));
        $this->assertContains('Task Reassigned', $this->titlesFor($from->account));
    }

    /**
     * Somebody else closing your task is exactly what you want to know about.
     */
    public function test_completing_someone_elses_task_tells_its_owner(): void
    {
        $this->actingAsSuperAdmin();

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();

        $project = $this->project();
        $owner = $this->technician('Owner Person');
        $this->book($project, $owner, $day10, $day10);

        $task = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $owner->technician_id,
            'task_title' => 'Install CCTV Cameras',
            'task_description' => 'Mount and wire the cameras.',
            'start_date' => $day10,
            'due_date' => $day10,
            'status' => 'pending',
        ]);

        $this->patch(route('super-admin.tasks.complete', $task->task_id), [
            'completion_notes' => 'Closed on the owner\'s behalf.',
        ]);

        $this->assertContains('Task Completed', $this->titlesFor($owner->account));
    }

    // ------------------------------------------------------------------
    // Reminders
    // ------------------------------------------------------------------

    public function test_the_reminder_run_covers_tomorrow_today_and_overdue(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $worker = $this->technician('Bea Free');
        $this->assign($project, $worker);

        $makeTask = function (string $title, string $dueDate) use ($project, $worker): void {
            Task::create([
                'project_id' => $project->project_id,
                'technician_id' => $worker->technician_id,
                'task_title' => $title,
                'task_description' => 'Work.',
                'start_date' => CarbonImmutable::today()->subDays(5)->toDateString(),
                'due_date' => $dueDate,
                'status' => 'pending',
            ]);
        };

        $makeTask('Due Tomorrow Task', CarbonImmutable::tomorrow()->toDateString());
        $makeTask('Due Today Task', CarbonImmutable::today()->toDateString());
        $makeTask('Overdue Task', CarbonImmutable::today()->subDay()->toDateString());

        $this->artisan('tasks:remind')->assertSuccessful();

        $titles = $this->titlesFor($worker->account);

        $this->assertContains('Task Due Tomorrow', $titles);
        $this->assertContains('Task Due Today', $titles);
        $this->assertContains('Task Overdue', $titles);
    }

    /**
     * The run is scheduled daily but safe to repeat by hand.
     */
    public function test_running_the_reminders_twice_does_not_double_up(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $worker = $this->technician('Bea Free');
        $this->assign($project, $worker);

        Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $worker->technician_id,
            'task_title' => 'Install CCTV Cameras',
            'task_description' => 'Work.',
            'start_date' => CarbonImmutable::today()->toDateString(),
            'due_date' => CarbonImmutable::tomorrow()->toDateString(),
            'status' => 'pending',
        ]);

        $this->artisan('tasks:remind');
        $this->artisan('tasks:remind');

        $this->assertSame(
            1,
            Notification::forUser($worker->account)->where('title', 'Task Due Tomorrow')->count()
        );
    }

    public function test_a_completed_task_gets_no_reminder(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $worker = $this->technician('Bea Free');
        $this->assign($project, $worker);

        Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $worker->technician_id,
            'task_title' => 'Install CCTV Cameras',
            'task_description' => 'Work.',
            'start_date' => CarbonImmutable::today()->toDateString(),
            'due_date' => CarbonImmutable::tomorrow()->toDateString(),
            'status' => 'completed',
        ]);

        $this->artisan('tasks:remind');

        $this->assertSame(0, Notification::forUser($worker->account)->count());
    }

    // ------------------------------------------------------------------
    // User management and security
    // ------------------------------------------------------------------

    public function test_creating_an_employee_tells_the_administrators(): void
    {
        $this->actingAsSuperAdmin();
        $admin = $this->employee('admin', 'admin@example.test');

        $this->post(route('super-admin.configuration.users.employees.store'), [
            'first_name' => 'New',
            'last_name' => 'Technician',
            'email' => 'new.technician@example.test',
            'contact_number' => '09175551234',
            'birthdate' => '1990-05-04',
            'role' => 'technician',
            // A technician account is rejected without at least one specialty.
            'skill_ids' => [Skill::create(['skill_name' => 'Aircon Repair'])->skill_id],
        ])->assertCreated();

        $this->assertContains('New Employee Account', $this->titlesFor($admin));
    }

    /**
     * An admin account is a super admin's business, not another admin's.
     */
    public function test_an_admin_account_is_only_reported_to_super_admins(): void
    {
        $superAdmin = $this->actingAsSuperAdmin();
        $otherSuperAdmin = $this->employee('super_admin', 'other.super@example.test');
        $admin = $this->employee('admin', 'admin@example.test');

        $this->post(route('super-admin.configuration.users.employees.store'), [
            'first_name' => 'New',
            'last_name' => 'Admin',
            'email' => 'new.admin@example.test',
            'contact_number' => '09175551234',
            'birthdate' => '1990-05-04',
            'role' => 'admin',
        ]);

        $this->assertContains('New Employee Account', $this->titlesFor($otherSuperAdmin));
        $this->assertSame(0, Notification::forUser($admin)->count());
        // The Super Admin who did it keeps the record too.
        $this->assertContains('New Employee Account', $this->titlesFor($superAdmin));
    }

    public function test_a_run_of_failed_sign_ins_reaches_the_super_admins(): void
    {
        $superAdmin = $this->employee('super_admin', 'owner@example.test');
        $account = $this->employee('admin', 'target@example.test');

        foreach (range(1, 5) as $ignored) {
            $this->post(route('auth.login.attempt'), [
                'email' => $account->email,
                'password' => 'the-wrong-password',
            ]);
        }

        $entry = $this->notificationsFor($superAdmin)->firstWhere('title', 'Repeated Failed Sign-Ins');

        $this->assertNotNull($entry);
        $this->assertStringContainsString($account->email, $entry->message);
        $this->assertSame(Notification::MODULE_SECURITY, $entry->module);
    }
}
