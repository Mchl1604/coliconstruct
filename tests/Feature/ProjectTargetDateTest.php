<?php

namespace Tests\Feature;

use App\Mail\ProjectUpdateMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Schedule;
use App\Models\Skill;
use App\Models\TargetDateHistory;
use App\Models\Technician;
use App\Models\User;
use App\Support\PortalHome;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The target completion date: set at creation, changed only by Admin and
 * Super Admin with a reason, recorded in a history the client can read, and
 * shown as Overdue only once today is past it.
 */
class ProjectTargetDateTest extends TestCase
{
    use RefreshDatabase;

    private static int $sequence = 0;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->superAdmin = $this->actingAsSuperAdmin();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function day(int $offset): string
    {
        return Schedule::businessToday()->addDays($offset)->toDateString();
    }

    private function account(string $role, string $email): User
    {
        $sequence = ++self::$sequence;

        return User::create([
            'user_code' => 'EMP-8'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
            'name' => ucfirst($role).' '.$sequence,
            'first_name' => ucfirst($role),
            'last_name' => 'Person'.$sequence,
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'password' => 'correct-password',
            'must_change_password' => false,
        ] + $this->acceptedTerms());
    }

    /**
     * A live project booked from day 2 to day 5, promised for day 10.
     */
    private function project(array $overrides = []): Project
    {
        $project = Project::create(array_merge([
            'name' => 'Target Project',
            'reference_no' => 'REF-TARGET-'.(++self::$sequence),
            'status' => 'pending',
            'address' => '123 Sample Street',
            'description' => 'Description',
            'quotation' => 1000,
            'target_end_date' => $this->day(10),
        ], $overrides));

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Residential',
            'firstname' => 'Maria',
            'surname' => 'Santos',
            'fullname' => 'Maria Santos',
            'email_address' => 'maria@example.test',
            'contact_number' => '09123456789',
        ]);

        $project->projectTypes()->attach(
            ProjectType::firstOrCreate(['type_name' => 'Aircon Installation'])->type_id
        );

        Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $this->day(2).' 00:00:00',
            'end_datetime' => $this->day(5).' 23:59:59',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'status' => 'scheduled',
        ]);

        if ($project->target_end_date) {
            TargetDateHistory::create([
                'project_id' => $project->project_id,
                'previous_date' => null,
                'new_date' => $project->target_end_date,
                'actor_name' => 'System',
                'created_at' => now()->subDay(),
            ]);
        }

        return $project;
    }

    private function save(Project $project, array $overrides = [])
    {
        return $this->put(route('super-admin.projects.update', $project->project_id), array_merge([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'address' => $project->address,
            'contact_number' => '09123456789',
            'email_address' => 'maria@example.test',
            'quotation' => '1000',
            'quotation_change' => 'none',
            'project_description' => $project->description,
            'project_types' => $project->projectTypes->pluck('type_id')->all(),
            'target_end_date' => $project->target_end_date?->toDateString(),
        ], $overrides));
    }

    private function technician(string $role, string $name): Technician
    {
        $user = $this->account($role, strtolower(str_replace(' ', '.', $name)).'@example.test');

        return Technician::create(['account_id' => $user->id, 'role' => $role]);
    }

    private function wizard(array $overrides = [])
    {
        ProjectType::firstOrCreate(['type_name' => 'Aircon Installation']);
        $skill = Skill::firstOrCreate(['skill_name' => 'Aircon Installation']);

        $lead = $this->technician('lead_technician', 'Lead Tech '.self::$sequence);
        $technician = $this->technician('technician', 'Crew Tech '.self::$sequence);

        DB::table('tbl_skill_map')->insert([
            'technician_id' => $technician->technician_id,
            'skill_id' => $skill->skill_id,
        ]);

        return $this->post(route('super-admin.projects.create.store'), array_merge([
            'client_type' => 'Residential',
            'surname' => 'Santos',
            'firstname' => 'Maria',
            'client_email' => 'maria@example.test',
            'client_phone' => '09123456789',
            'project_address' => '123 Sample Street',
            'quotation_amount' => '1250.00',
            'project_types' => ['Aircon Installation'],
            'assessment_report' => [UploadedFile::fake()->create('assessment.pdf', 12, 'application/pdf')],
            'approved_quotation' => [UploadedFile::fake()->create('quotation.pdf', 12, 'application/pdf')],
            'project_description' => 'Install two units.',
            'lead_tech' => $lead->technician_id,
            'technicians' => [$technician->technician_id],
            'start_date' => $this->day(10),
            'end_date' => $this->day(12),
            'target_end_date' => $this->day(20),
        ], $overrides));
    }

    // ------------------------------------------------------------------
    // Creation
    // ------------------------------------------------------------------

    public function test_a_project_is_created_with_its_target_date_and_an_opening_history_entry(): void
    {
        $this->wizard()->assertSessionHasNoErrors();

        $project = Project::sole();

        $this->assertSame($this->day(20), $project->target_end_date->toDateString());

        $entry = TargetDateHistory::sole();
        $this->assertTrue($entry->isInitial());
        $this->assertSame($this->day(20), $entry->new_date->toDateString());
        $this->assertSame($this->superAdmin->id, $entry->actor_id);
    }

    public function test_the_wizard_requires_a_target_date(): void
    {
        $this->wizard(['target_end_date' => null])->assertSessionHasErrors('target_end_date');

        $this->assertSame(0, Project::count());
    }

    public function test_the_wizard_refuses_a_target_date_in_the_past(): void
    {
        $this->wizard(['target_end_date' => $this->day(-1)])
            ->assertSessionHasErrors(['target_end_date' => 'Target date cannot be in the past.']);
    }

    public function test_the_wizard_refuses_a_target_date_before_the_last_work_day(): void
    {
        $this->wizard(['target_end_date' => $this->day(11)])->assertSessionHasErrors('target_end_date');

        $this->assertSame(0, Project::count());

        // The last work day itself is fine.
        $this->wizard(['target_end_date' => $this->day(12)])->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------------
    // Editing
    // ------------------------------------------------------------------

    public function test_a_change_writes_one_history_row_one_log_and_notifies(): void
    {
        $team = $this->account('technician', 'crew@example.test');
        $client = $this->account('client', 'maria@example.test');
        $project = $this->project();

        DB::table('tbl_technicians')->insert(['account_id' => $team->id, 'role' => 'technician']);
        $project->projectTechnicians()->create([
            'technician_id' => Technician::where('account_id', $team->id)->value('technician_id'),
        ]);

        $this->save($project, [
            'target_end_date' => $this->day(15),
            'target_date_reason' => 'Parts arriving late',
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->day(15), $project->fresh()->target_end_date->toDateString());

        $change = TargetDateHistory::query()->whereNotNull('previous_date')->sole();
        $this->assertSame($this->day(10), $change->previous_date->toDateString());
        $this->assertSame('Parts arriving late', $change->reason);
        $this->assertSame('super_admin', $change->actor_role);

        $this->assertSame(1, ActivityLog::where('action', ActivityLog::PROJECT_TARGET_DATE_CHANGED)->count());

        // The office's bell, the crew's bell, and the client's.
        $this->assertTrue(Notification::where('user_id', $this->superAdmin->id)->where('title', 'Target Date Changed')->exists());
        $this->assertTrue(Notification::where('user_id', $team->id)->where('title', 'Target Date Changed')->exists());

        $clientBell = Notification::where('user_id', $client->id)->where('title', 'Target Date Changed')->sole();
        $this->assertStringContainsString('Parts arriving late', $clientBell->message);

        Mail::assertQueued(ProjectUpdateMail::class, fn (ProjectUpdateMail $mail): bool => $mail->hasTo('maria@example.test')
            && $mail->event === ProjectUpdateMail::TARGET_DATE_CHANGED
            && $mail->detail === 'Parts arriving late');
    }

    public function test_saving_the_same_date_writes_no_history_and_sends_nothing(): void
    {
        $project = $this->project();

        $this->save($project)->assertSessionHasNoErrors();

        $this->assertSame(1, TargetDateHistory::count());
        $this->assertSame(0, ActivityLog::where('action', ActivityLog::PROJECT_TARGET_DATE_CHANGED)->count());
        Mail::assertNotQueued(ProjectUpdateMail::class, fn (ProjectUpdateMail $mail): bool => $mail->event === ProjectUpdateMail::TARGET_DATE_CHANGED);
    }

    public function test_a_change_without_a_reason_is_refused(): void
    {
        $project = $this->project();

        $this->save($project, ['target_end_date' => $this->day(15)])
            ->assertSessionHasErrors(['target_date_reason' => 'Reason for change is required.']);

        $this->assertSame($this->day(10), $project->fresh()->target_end_date->toDateString());
        $this->assertSame(1, TargetDateHistory::count());
    }

    public function test_a_change_before_the_last_work_day_is_refused(): void
    {
        $project = $this->project();

        $this->save($project, ['target_end_date' => $this->day(4), 'target_date_reason' => 'Faster'])
            ->assertSessionHasErrors('target_end_date');

        $this->assertSame($this->day(10), $project->fresh()->target_end_date->toDateString());
    }

    public function test_an_admin_may_change_it(): void
    {
        $project = $this->project();

        $this->actingAs($this->account('admin', 'admin@example.test'));

        $this->save($project, ['target_end_date' => $this->day(15), 'target_date_reason' => 'Client asked'])
            ->assertSessionHasNoErrors();

        $this->assertSame('admin', TargetDateHistory::whereNotNull('previous_date')->sole()->actor_role);
    }

    public function test_technicians_and_clients_cannot_change_it(): void
    {
        $project = $this->project();

        foreach (['technician', 'lead_technician', 'client'] as $role) {
            $this->actingAs($this->account($role, $role.'@example.test'));

            // Sent back to their own portal, as every other wrong-portal
            // request is - see EnsureUserHasRole. A JSON request gets a 403.
            $this->save($project, ['target_end_date' => $this->day(15), 'target_date_reason' => 'Nope'])
                ->assertRedirect(PortalHome::url(auth()->user()));

            $this->putJson(route('super-admin.projects.update', $project->project_id), [
                'target_end_date' => $this->day(15),
                'target_date_reason' => 'Nope',
            ])->assertForbidden();
        }

        $this->assertSame($this->day(10), $project->fresh()->target_end_date->toDateString());
    }

    public function test_a_read_only_project_refuses_the_change(): void
    {
        $project = $this->project(['status' => 'completed']);

        $this->save($project, ['target_end_date' => $this->day(15), 'target_date_reason' => 'Late'])
            ->assertSessionHas('error');

        $this->assertSame($this->day(10), $project->fresh()->target_end_date->toDateString());
    }

    public function test_an_older_project_without_a_target_can_still_be_saved(): void
    {
        $project = $this->project(['target_end_date' => null]);

        $this->save($project, ['target_end_date' => ''])->assertSessionHasNoErrors();

        $this->assertNull($project->fresh()->target_end_date);
    }

    // ------------------------------------------------------------------
    // Schedule changes and Overdue
    // ------------------------------------------------------------------

    public function test_a_schedule_running_past_the_target_is_allowed(): void
    {
        $project = $this->project(['target_end_date' => $this->day(6)]);

        // The target never blocks the booking; the schedule simply outruns it.
        $project->schedules()->first()->update(['end_datetime' => $this->day(9).' 23:59:59']);

        $this->assertSame($this->day(9), $project->fresh()->scheduleEndsOn()->toDateString());
        $this->assertFalse($project->fresh()->isPastTargetDate());
    }

    public function test_overdue_only_once_today_is_after_the_target(): void
    {
        $today = Schedule::businessToday();

        $this->assertFalse($this->project(['target_end_date' => $today->toDateString()])->isPastTargetDate());
        $this->assertTrue($this->project(['target_end_date' => $today->subDay()->toDateString()])->isPastTargetDate());
    }

    public function test_finished_cancelled_and_archived_projects_are_never_overdue(): void
    {
        $yesterday = Schedule::businessToday()->subDay()->toDateString();

        foreach (['completed', Project::STATUS_AWAITING_CLIENT_CONFIRMATION, 'cancelled', 'archived'] as $status) {
            $this->assertFalse(
                $this->project(['status' => $status, 'target_end_date' => $yesterday])->isPastTargetDate(),
                $status
            );
        }
    }

    public function test_the_staff_page_shows_the_date_overdue_badge_and_history(): void
    {
        $project = $this->project(['target_end_date' => CarbonImmutable::parse($this->day(-1))->toDateString()]);

        TargetDateHistory::create([
            'project_id' => $project->project_id,
            'previous_date' => $this->day(-5),
            'new_date' => $this->day(-1),
            'reason' => 'Rain delay',
            'actor_name' => 'Test Super Admin',
            'actor_role' => 'super_admin',
            'created_at' => now(),
        ]);

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('Target Completion Date')
            ->assertSee('data-target-date-overdue', false)
            ->assertSee('Target Date History')
            ->assertSee('Rain delay')
            ->assertSee('Test Super Admin');
    }

    // ------------------------------------------------------------------
    // Client view
    // ------------------------------------------------------------------

    public function test_the_client_sees_the_date_and_reasons_but_no_staff_names(): void
    {
        $client = $this->account('client', 'maria@example.test');
        $project = $this->project();

        TargetDateHistory::create([
            'project_id' => $project->project_id,
            'previous_date' => $this->day(8),
            'new_date' => $this->day(10),
            'reason' => 'Waiting on permit',
            'actor_name' => 'Hidden Staffer',
            'actor_role' => 'admin',
            'created_at' => now(),
        ]);

        $this->actingAs($client)
            ->get(route('public.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('Target Completion')
            ->assertSee('Waiting on permit')
            ->assertDontSee('Hidden Staffer');

        $this->actingAs($client)
            ->get(route('public.projects'))
            ->assertOk()
            ->assertSee('data-target-date', false);
    }

    public function test_the_history_dialog_uses_the_shared_history_styling(): void
    {
        $project = $this->project();

        TargetDateHistory::create([
            'project_id' => $project->project_id,
            'previous_date' => $this->day(8),
            'new_date' => $this->day(10),
            'reason' => 'Rain delay',
            'actor_name' => 'Test Super Admin',
            'created_at' => now(),
        ]);

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('/css/targetDateHistory.css', false)
            ->assertSee('target-date-history-badge is-set', false)
            ->assertSee('target-date-history-badge is-changed', false);
    }

    // ------------------------------------------------------------------
    // NEW flag on the projects list
    // ------------------------------------------------------------------

    public function test_a_new_project_is_flagged_until_its_details_are_first_opened(): void
    {
        $this->wizard()->assertSessionHasNoErrors();

        $project = Project::sole();
        $this->assertTrue($project->isNew());

        $this->get(route('super-admin.projects'))->assertSee('data-project-new-flag', false);

        $updatedAt = $project->updated_at;

        $this->get(route('super-admin.projects.show', $project->project_id))->assertOk();

        $project->refresh();
        $this->assertFalse($project->isNew());
        // Opening a page is not a save: the edit dialog's version check reads updated_at.
        $this->assertEquals($updatedAt, $project->updated_at);

        $this->get(route('super-admin.projects'))->assertDontSee('data-project-new-flag', false);
    }

    public function test_a_client_cannot_read_another_clients_project(): void
    {
        $project = $this->project();

        $this->actingAs($this->account('client', 'someone.else@example.test'))
            ->get(route('public.projects.show', $project->project_id))
            ->assertNotFound();
    }
}
