<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\PasswordResetController;
use App\Models\Client;
use App\Models\OtpVerification;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\ProjectType;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use App\Services\ProjectStatusRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The medium-severity findings from the system audit.
 */
class MediumSeverityAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private function technician(string $name, string $role = 'technician'): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => mb_strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => $role, 'status' => User::STATUS_ACTIVE])->save();

        return Technician::create(['account_id' => $user->id, 'role' => $role]);
    }

    private function project(array $technicians = [], string $status = 'ongoing'): Project
    {
        $project = Project::create([
            'name' => 'Medium Project '.uniqid(),
            'reference_no' => 'REF-'.strtoupper(uniqid()),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 1000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Commercial',
            'company_name' => 'Medium Holdings',
            'firstname' => 'Medium',
            'surname' => 'Client',
            'fullname' => 'Medium Client',
            'email_address' => 'medium.client@example.test',
            'contact_number' => '09123456789',
        ]);

        foreach ($technicians as $technician) {
            ProjectTechnician::create([
                'project_id' => $project->project_id,
                'technician_id' => $technician->technician_id,
            ]);
        }

        return $project->fresh();
    }

    private function book(Project $project, int $from, int $to): Schedule
    {
        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => Schedule::businessToday()->addDays($from)->startOfDay(),
            'end_datetime' => Schedule::businessToday()->addDays($to)->endOfDay(),
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'status' => 'scheduled',
        ]);

        ProjectTechnician::where('project_id', $project->project_id)
            ->pluck('project_technician_id')
            ->each(fn ($id) => ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $id,
            ]));

        return $schedule;
    }

    /**
     * A session row of the kind the database driver writes.
     */
    private function openSession(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    // ==================================================================
    // BUG-011 - a new password ends the sessions the old one opened
    // ==================================================================

    public function test_resetting_a_forgotten_password_ends_every_open_session(): void
    {
        Config::set('session.driver', 'database');

        $user = User::factory()->create(['email' => 'forgetful@example.test']);
        $user->forceFill(['role' => User::ROLE_CLIENT, 'status' => User::STATUS_ACTIVE] + $this->acceptedTerms())->save();

        $this->openSession($user, 'session-one');
        $this->openSession($user, 'session-two');

        // Step three of the reset, reached with a verified code.
        $this->withSession([
            PasswordResetController::SESSION_EMAIL => $user->email,
            PasswordResetController::SESSION_VERIFIED => now()->toIso8601String(),
        ]);

        OtpVerification::create([
            'email' => $user->email,
            'purpose' => OtpVerification::PURPOSE_FORGOT_PASSWORD,
            'otp_code' => bcrypt('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'verified_at' => now(),
            'user_id' => $user->id,
        ]);

        $this->post(route('auth.password.store'), [
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect(route('auth.login'));

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_an_administrator_reset_ends_the_accounts_sessions(): void
    {
        Config::set('session.driver', 'database');

        $this->actingAsSuperAdmin();

        $target = User::factory()->create(['email' => 'target@example.test']);
        $target->forceFill(['role' => User::ROLE_CLIENT, 'status' => User::STATUS_ACTIVE] + $this->acceptedTerms())->save();

        $this->openSession($target, 'their-session');

        $this->putJson(route('super-admin.configuration.users.password.reset', $target))
            ->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
    }

    /**
     * Changing your own password keeps you signed in where you are - it should
     * not turn "choose a new password" into "choose a new password and sign in
     * again" - while ending it everywhere else.
     */
    public function test_changing_your_own_password_keeps_the_session_you_are_in(): void
    {
        Config::set('session.driver', 'database');

        $user = User::factory()->create(['email' => 'self@example.test']);
        $user->forceFill([
            'role' => User::ROLE_CLIENT,
            'status' => User::STATUS_ACTIVE,
            'password' => 'the-old-password',
        ] + $this->acceptedTerms())->save();

        $this->openSession($user, 'somewhere-else');

        $this->actingAs($user)
            ->put(route('profile.password'), [
                'current_password' => 'the-old-password',
                'password' => 'the-new-password',
                'password_confirmation' => 'the-new-password',
            ]);

        $this->assertDatabaseMissing('sessions', ['id' => 'somewhere-else']);
        $this->assertAuthenticatedAs($user);
    }

    // ==================================================================
    // BUG-012 - a failure says what went wrong, not how the database is built
    // ==================================================================

    public function test_a_database_fault_does_not_reach_the_toast(): void
    {
        $this->actingAsSuperAdmin();

        $type = ProjectType::create(['type_name' => 'Aircon Installation']);
        $project = $this->project();

        // The client row disappears underneath the edit, which is what used to
        // print "No query results for model [App\Models\Client]" on screen.
        Client::where('project_id', $project->project_id)->delete();

        Config::set('app.debug', false);

        $this->put(route('super-admin.projects.update', $project->project_id), [
            'first_name' => 'A', 'last_name' => 'B', 'address' => 'X',
            'contact_number' => '09123456789', 'email_address' => 'a@b.test',
            'quotation' => 10, 'project_description' => 'Y',
            'project_types' => [$type->type_id],
        ]);

        $error = (string) session('error');

        $this->assertNotSame('', $error);
        $this->assertStringNotContainsString('App\\Models', $error);
        $this->assertStringNotContainsString('No query results', $error);
        $this->assertStringContainsString('Unable to update project', $error);
    }

    /**
     * The narrowing must not swallow the messages that were written for a
     * person to read - a scheduling clash still names the technician.
     */
    public function test_a_deliberate_message_still_reaches_the_person(): void
    {
        $this->actingAsSuperAdmin();
        Config::set('app.debug', false);

        $shared = $this->technician('Shared Person');

        $other = $this->project([$shared]);
        $this->book($other, 5, 7);

        $mine = $this->project([$shared]);
        $schedule = $this->book($mine, 1, 2);

        $this->put(route('super-admin.schedules.update', $mine->project_id), [
            'ranges' => [[
                'schedule_id' => $schedule->schedule_id,
                'scheduling_mode' => Schedule::MODE_DATE_BASED,
                'start_date' => Schedule::businessToday()->addDays(5)->toDateString(),
                'end_date' => Schedule::businessToday()->addDays(6)->toDateString(),
            ]],
        ]);

        $this->assertStringContainsString('Shared Person', (string) session('error'));
    }

    // ==================================================================
    // BUG-013 - one clock, the office's
    // ==================================================================

    /**
     * Between midnight and 8am in Manila the office is already on tomorrow
     * while the server is still on yesterday. A project booked for the office's
     * today is not late, and used to read as though it were.
     */
    public function test_overdue_is_measured_against_the_office_clock(): void
    {
        // 2026-08-17 20:00 UTC is 2026-08-18 04:00 in Manila.
        $this->travelTo(CarbonImmutable::parse('2026-08-17 20:00:00', 'UTC'));

        $this->assertSame('2026-08-17', CarbonImmutable::today()->toDateString());
        $this->assertSame('2026-08-18', Schedule::businessToday()->toDateString());

        $technician = $this->technician('Clock Person');
        $project = $this->project([$technician]);

        // Booked for the office's today, which is the server's tomorrow.
        Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => '2026-08-18 00:00:00',
            'end_datetime' => '2026-08-18 23:59:59',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'status' => 'scheduled',
        ]);

        $this->assertFalse($project->fresh()->isOverdue(), 'Work booked for today is not late.');
        $this->assertSame(0, Project::overdue()->count());

        // Yesterday at the office really is late.
        Schedule::where('project_id', $project->project_id)->update([
            'start_datetime' => '2026-08-17 00:00:00',
            'end_datetime' => '2026-08-17 23:59:59',
        ]);

        $this->assertTrue($project->fresh()->isOverdue());
        $this->assertSame(1, Project::overdue()->count());

        $this->travelBack();
    }

    // ==================================================================
    // BUG-014 - the same job twice
    // ==================================================================

    public function test_the_administrative_board_refuses_a_duplicate_open_task(): void
    {
        $this->actingAsSuperAdmin();

        $lead = $this->technician('Board Lead', 'lead_technician');
        $project = $this->project([$lead]);
        $this->book($project, 0, 4);

        $payload = [
            'task_title' => 'Wire the panel',
            'task_description' => 'Description',
            'technician_id' => $lead->technician_id,
            'start_date' => Schedule::businessToday()->toDateString(),
            'due_date' => Schedule::businessToday()->addDay()->toDateString(),
        ];

        $this->post(route('super-admin.task.store', $project->project_id), $payload)
            ->assertSessionHas('success');

        // The same form submitted twice - a double-clicked Save.
        $this->post(route('super-admin.task.store', $project->project_id), $payload)
            ->assertSessionHas('error');

        $this->assertSame(1, Task::where('project_id', $project->project_id)->count());

        // A different job for the same person is still fine.
        $this->post(route('super-admin.task.store', $project->project_id), $payload + [])
            ->assertSessionHas('error');

        $this->post(
            route('super-admin.task.store', $project->project_id),
            array_merge($payload, ['task_title' => 'Mount the unit'])
        )->assertSessionHas('success');

        $this->assertSame(2, Task::where('project_id', $project->project_id)->count());
    }

    // ==================================================================
    // BUG-017 / BUG-020 - one status rule, applied everywhere
    // ==================================================================

    public function test_the_status_rule_reads_the_dates_in_both_directions(): void
    {
        $rules = app(ProjectStatusRules::class);
        $technician = $this->technician('Status Person');

        $unbooked = $this->project([$technician]);
        $this->assertSame('unscheduled', $rules->statusFor($unbooked));

        $future = $this->project([$technician]);
        $this->book($future, 5, 7);
        $this->assertSame('pending', $rules->statusFor($future->fresh()));

        $running = $this->project([$technician]);
        $this->book($running, -1, 3);
        $this->assertSame('ongoing', $rules->statusFor($running->fresh()));

        // All dates passed: Ongoing with nothing left to reach, which is what
        // Overdue is derived from rather than a status of its own.
        $late = $this->project([$technician]);
        $this->book($late, -8, -3);
        $this->assertSame('ongoing', $rules->statusFor($late->fresh()));
        $this->assertTrue($late->fresh()->isOverdue());
    }

    /**
     * A decision somebody made is never overwritten by the calendar.
     */
    public function test_the_status_rule_leaves_decisions_alone(): void
    {
        $rules = app(ProjectStatusRules::class);
        $technician = $this->technician('Decision Person');

        $held = $this->project([$technician]);
        $this->book($held, -2, 2);
        $held->update(['on_hold' => true, 'status' => 'unscheduled']);
        $this->assertNull($rules->statusFor($held->fresh()));

        foreach (['completed', 'cancelled', 'archived', Project::STATUS_AWAITING_CLIENT_CONFIRMATION] as $status) {
            $decided = $this->project([$technician]);
            $this->book($decided, -2, 2);
            $decided->update(['status' => $status]);

            $this->assertNull($rules->statusFor($decided->fresh()), $status.' is not the calendar to decide.');
        }
    }

    public function test_the_projects_page_applies_the_rule_without_touching_decided_work(): void
    {
        $this->actingAsSuperAdmin();

        $technician = $this->technician('Sweep Person');

        $stale = $this->project([$technician], 'pending');
        $this->book($stale, -1, 3);

        $completed = $this->project([$technician], 'completed');
        $this->book($completed, -5, -1);

        $this->get(route('super-admin.projects'))->assertOk();

        $this->assertSame('ongoing', $stale->fresh()->status, 'Dates that have arrived promote it.');
        $this->assertSame('completed', $completed->fresh()->status, 'A decision stands.');
    }
}
