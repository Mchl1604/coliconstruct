<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The two rules that say a completion is not true yet.
 *
 * A task cannot be ticked off before the day it was due to begin, and a lead
 * technician cannot close a project whose phases are unset or unfinished. Both
 * are enforced on the way in rather than by the button being absent - a
 * disabled control is a courtesy, and the tests here post the request directly
 * to prove the rule does not depend on one.
 *
 * The third rule is who they apply to. An Admin or a Super Admin closing a
 * project on the team's behalf gets neither phase refusal, because closing out
 * a job the crew never finished tidying is exactly what they are for. That is
 * asserted as deliberately as the refusals are, so a later tightening cannot
 * quietly take it away.
 */
class CompletionRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $leadAccount;

    private Technician $lead;

    private Technician $mate;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->leadAccount = $this->employee('lead_technician', 'lead@example.test');
        $this->lead = Technician::create([
            'account_id' => $this->leadAccount->id,
            'role' => 'lead_technician',
        ]);

        $mateAccount = $this->employee('technician', 'mate@example.test');
        $this->mate = Technician::create([
            'account_id' => $mateAccount->id,
            'role' => 'technician',
        ]);

        $this->project = $this->newProject('REF-RULES-1');
        $this->assign($this->project, $this->lead);
        $this->assign($this->project, $this->mate);
        // Under way and running out: the project is in a state a completion
        // could be filed for, so nothing below is refused for a reason other
        // than the one it is asking about.
        $this->schedule($this->project, -10, 2);
    }

    // ==================================================================
    // A task, before the day it starts
    // ==================================================================

    public function test_a_technician_cannot_complete_a_task_that_has_not_started(): void
    {
        $task = $this->task($this->mate, 'Fit the unit', startsIn: 1);

        $this->actingAs($this->mate->account);

        $response = $this->postJson(route('technician.tasks.complete', $task), [
            'completion_notes' => 'Claiming tomorrow before it arrives.',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', Task::NOT_STARTED_REFUSAL);
        $this->assertSame('pending', $task->refresh()->status);
        $this->assertNull($task->completed_at);
    }

    /**
     * The boundary the rule allows. "Before the start date" means before, and
     * a task starting this morning is work somebody is on site for.
     */
    public function test_a_technician_can_complete_a_task_starting_today(): void
    {
        $task = $this->task($this->mate, 'Fit the unit', startsIn: 0);

        $this->actingAs($this->mate->account);

        $this->post(route('technician.tasks.complete', $task), [
            'completion_notes' => 'Fitted and tested.',
        ])->assertRedirect();

        $this->assertSame('completed', $task->refresh()->status);
    }

    public function test_a_technician_can_complete_a_task_that_started_earlier(): void
    {
        $task = $this->task($this->mate, 'Fit the unit', startsIn: -3);

        $this->actingAs($this->mate->account);

        $this->post(route('technician.tasks.complete', $task), [
            'completion_notes' => 'Finished this morning.',
        ])->assertRedirect();

        $this->assertSame('completed', $task->refresh()->status);
    }

    /**
     * A lead closing somebody else's task is closing the same task, so the
     * same day has to have arrived. Rank decides whose work you may touch, not
     * when the work happened.
     */
    public function test_a_lead_cannot_close_a_future_task_on_the_crews_behalf(): void
    {
        $task = $this->task($this->mate, 'Fit the unit', startsIn: 4);

        $this->actingAs($this->leadAccount);

        $this->postJson(route('technician.tasks.complete', $task), [])
            ->assertStatus(422)
            ->assertJsonPath('error', Task::NOT_STARTED_REFUSAL);

        $this->assertSame('pending', $task->refresh()->status);
    }

    /**
     * A task with no start date has no day to be early of. It is chased as
     * missing a date instead - see Task::assignmentGap() - and closing it is
     * not what this rule is about.
     */
    public function test_a_task_with_no_start_date_is_not_refused(): void
    {
        $task = $this->task($this->mate, 'Undated');
        $task->forceFill(['start_date' => null, 'due_date' => null])->save();

        $this->actingAs($this->mate->account);

        $this->post(route('technician.tasks.complete', $task), [
            'completion_notes' => 'Done.',
        ])->assertRedirect();

        $this->assertSame('completed', $task->refresh()->status);
    }

    /**
     * A stale page, or somebody who never had one. The button is not what
     * enforces this.
     */
    public function test_the_refusal_survives_a_request_that_never_saw_the_button(): void
    {
        $task = $this->task($this->mate, 'Fit the unit', startsIn: 6);

        $this->actingAs($this->mate->account);

        // No notes, no photos, nothing the dialog would have collected: the
        // shape of a request assembled by hand.
        $this->postJson(route('technician.tasks.complete', $task))
            ->assertStatus(422)
            ->assertJsonPath('error', Task::NOT_STARTED_REFUSAL);

        $this->assertSame('pending', $task->refresh()->status);
    }

    /**
     * Reach is still decided first: a task that was never this technician's is
     * refused as somebody else's, not explained to them.
     */
    public function test_somebody_elses_future_task_is_still_a_flat_refusal(): void
    {
        $task = $this->task($this->lead, 'Not yours', startsIn: 3);

        $this->actingAs($this->mate->account);

        $this->postJson(route('technician.tasks.complete', $task), [])
            ->assertForbidden();
    }

    /**
     * The office is not held to this. An administrator closing a task on the
     * crew's behalf is recording something they were told, and the calendar is
     * not theirs to argue with - the same allowance they have on every other
     * completion rule.
     */
    public function test_an_administrator_may_still_close_a_task_that_has_not_started(): void
    {
        $task = $this->task($this->mate, 'Fit the unit', startsIn: 2);

        $this->actingAs($this->employee('super_admin', 'boss@example.test'));

        $this->patch(route('super-admin.tasks.complete', $task->task_id), [])
            ->assertRedirect();

        $this->assertSame('completed', $task->refresh()->status);
    }

    // ------------------------------------------------------------------
    // What the pages draw
    // ------------------------------------------------------------------

    public function test_the_project_page_offers_a_disabled_button_with_the_reason(): void
    {
        $this->task($this->mate, 'Starts next week', startsIn: 7);

        $this->actingAs($this->mate->account);

        $page = $this->get(route('technician.projects.show', $this->project));

        $page->assertOk();
        $page->assertSee(Task::NOT_STARTED_REFUSAL);
        $page->assertDontSee('data-bs-target="#completeTaskModal', false);
    }

    public function test_the_task_board_offers_a_disabled_button_with_the_reason(): void
    {
        $this->task($this->mate, 'Starts next week', startsIn: 7);

        $this->actingAs($this->mate->account);

        $page = $this->get(route('technician.tasks'));

        $page->assertOk();
        $page->assertSee(Task::NOT_STARTED_REFUSAL);
        $page->assertDontSee('data-bs-target="#completeTaskModal', false);
    }

    /**
     * The schedule panel is drawn in the browser from this payload, so the
     * reason has to travel with the task rather than be re-derived there.
     */
    public function test_the_project_payload_carries_the_reason(): void
    {
        $this->task($this->mate, 'Starts next week', startsIn: 7);

        $this->actingAs($this->mate->account);

        $response = $this->getJson(route('technician.projects.details', $this->project));

        $response->assertOk();
        $response->assertJsonPath('tasks.0.can_complete', false);
        $response->assertJsonPath('tasks.0.completion_blocked_reason', Task::NOT_STARTED_REFUSAL);
    }

    // ==================================================================
    // A project, before its phases are done
    // ==================================================================

    public function test_a_lead_cannot_complete_a_project_with_no_phases_set_up(): void
    {
        // Deliberately never finalized, with the work on it finished: the only
        // thing outstanding is the structure nobody laid out.
        $this->closedTask();

        $this->actingAs($this->leadAccount);

        $response = $this->postJson(
            route('technician.projects.complete', $this->project),
            $this->completionPayload()
        );

        $response->assertStatus(422);
        $this->assertSame(
            ['Set up the project phases before completing the project.'],
            array_column($response->json('blockers'), 'message')
        );
        $this->assertSame('ongoing', $this->project->refresh()->status);
    }

    /**
     * The rule this whole check exists for. "Nothing is outstanding" is true of
     * a finished project AND of one nobody set any phases up for, and only the
     * first of those is finished work.
     */
    public function test_an_empty_phase_list_does_not_read_as_every_phase_complete(): void
    {
        $this->closedTask();

        $this->assertSame(0, $this->project->phases()->count());
        $this->assertFalse(
            app(ProjectPolicy::class)->complete($this->leadAccount, $this->project)
        );
    }

    public function test_a_lead_cannot_complete_a_project_with_one_phase_outstanding(): void
    {
        $phases = $this->finalizePhases($this->project, 3);
        $this->closedTask();

        // Two of three ticked off: the case an "are any phases incomplete?"
        // check gets right and an "is the list empty?" check gets wrong.
        $phases->take(2)->each(fn ($phase) => $phase->forceFill(['completed_at' => now()])->save());

        $this->actingAs($this->leadAccount);

        $response = $this->postJson(
            route('technician.projects.complete', $this->project),
            $this->completionPayload()
        );

        $response->assertStatus(422);
        $this->assertSame(
            ['Complete all project phases before completing the project.'],
            array_column($response->json('blockers'), 'message')
        );
        $this->assertSame('1 incomplete phase', $response->json('blockers.0.summary'));
        $this->assertSame('ongoing', $this->project->refresh()->status);
    }

    public function test_a_lead_cannot_complete_a_project_with_several_phases_outstanding(): void
    {
        $this->finalizePhases($this->project, 4);
        $this->closedTask();

        $this->actingAs($this->leadAccount);

        $response = $this->postJson(
            route('technician.projects.complete', $this->project),
            $this->completionPayload()
        );

        $response->assertStatus(422);
        $this->assertSame(
            ['Complete all project phases before completing the project.'],
            array_column($response->json('blockers'), 'message')
        );
        // Counted, so a notification title can say how much is left.
        $this->assertSame('4 incomplete phases', $response->json('blockers.0.summary'));
    }

    public function test_a_lead_can_complete_a_project_once_every_phase_is_done(): void
    {
        Storage::fake('uploads');

        $this->finalizePhases($this->project, 3);
        $this->closedTask();
        $this->completePhases($this->project);

        $this->actingAs($this->leadAccount);

        $this->post(
            route('technician.projects.complete', $this->project),
            $this->completionPayload()
        )->assertRedirect();

        $this->assertSame(
            Project::STATUS_AWAITING_CLIENT_CONFIRMATION,
            $this->project->refresh()->status
        );
    }

    /**
     * The state is read from the database on every attempt, so a page drawn
     * before a phase was reopened cannot get past it.
     */
    public function test_reopening_a_phase_blocks_the_lead_again(): void
    {
        $phases = $this->finalizePhases($this->project, 2);
        $this->closedTask();
        $this->completePhases($this->project);

        $this->assertTrue(
            app(ProjectPolicy::class)->complete($this->leadAccount, $this->project)
        );

        $phases->last()->forceFill(['completed_at' => null])->save();

        $this->actingAs($this->leadAccount);

        $this->postJson(
            route('technician.projects.complete', $this->project),
            $this->completionPayload()
        )->assertStatus(422);

        $this->assertSame('ongoing', $this->project->refresh()->status);
    }

    /**
     * A Super Admin unlocking the structure puts the project back to "no
     * phases agreed", whatever the old rows still say about being ticked off.
     */
    public function test_unlocking_the_structure_blocks_the_lead_again(): void
    {
        $this->finalizePhases($this->project, 2);
        $this->closedTask();
        $this->completePhases($this->project);

        $this->project->forceFill([
            'phase_setup_status' => Project::PHASE_SETUP_PENDING,
        ])->save();

        $this->actingAs($this->leadAccount);

        $response = $this->postJson(
            route('technician.projects.complete', $this->project),
            $this->completionPayload()
        );

        $response->assertStatus(422);
        $this->assertSame(
            ['Set up the project phases before completing the project.'],
            array_column($response->json('blockers'), 'message')
        );
    }

    /**
     * The dialog says what is in the way and where to go and deal with it,
     * rather than refusing and leaving the lead to work it out.
     */
    public function test_the_project_page_prints_the_phase_refusal(): void
    {
        $this->finalizePhases($this->project, 2);
        $this->closedTask();

        $this->actingAs($this->leadAccount);

        $page = $this->get(route('technician.projects.show', $this->project));

        $page->assertOk();
        $page->assertSee('Complete all project phases before completing the project.');
        $page->assertSee(
            route('technician.projects.show', $this->project->project_id).'#phases',
            false
        );
    }

    // ==================================================================
    // The office is not held to the phase rules
    // ==================================================================

    public function test_an_admin_may_complete_a_project_with_no_phases(): void
    {
        Storage::fake('uploads');

        $this->closedTask();
        $this->actingAs($this->employee('admin', 'admin@example.test'));

        $this->post(
            route('super-admin.projects.complete', $this->project->project_id),
            $this->completionPayload(photos: false)
        )->assertRedirect();

        $this->assertSame(
            Project::STATUS_AWAITING_CLIENT_CONFIRMATION,
            $this->project->refresh()->status
        );
        // Not an override: there was nothing for them to override.
        $this->assertNull($this->project->completion_override_reason);
    }

    public function test_an_admin_may_complete_a_project_with_phases_outstanding(): void
    {
        Storage::fake('uploads');

        $this->finalizePhases($this->project, 3);
        $this->closedTask();

        $this->actingAs($this->employee('admin', 'admin@example.test'));

        $this->post(
            route('super-admin.projects.complete', $this->project->project_id),
            $this->completionPayload(photos: false)
        )->assertRedirect();

        $this->assertSame(
            Project::STATUS_AWAITING_CLIENT_CONFIRMATION,
            $this->project->refresh()->status
        );
        $this->assertNull($this->project->completion_override_reason);
    }

    public function test_a_super_admin_may_complete_a_project_with_no_phases(): void
    {
        Storage::fake('uploads');

        $this->closedTask();
        $this->actingAs($this->employee('super_admin', 'boss@example.test'));

        $this->post(
            route('super-admin.projects.complete', $this->project->project_id),
            $this->completionPayload(photos: false)
        )->assertRedirect();

        $this->assertSame(
            Project::STATUS_AWAITING_CLIENT_CONFIRMATION,
            $this->project->refresh()->status
        );
    }

    public function test_a_super_admin_may_complete_a_project_with_phases_outstanding(): void
    {
        Storage::fake('uploads');

        $this->finalizePhases($this->project, 2);
        $this->closedTask();

        $this->actingAs($this->employee('super_admin', 'boss@example.test'));

        $this->post(
            route('super-admin.projects.complete', $this->project->project_id),
            $this->completionPayload(photos: false)
        )->assertRedirect();

        $this->assertSame(
            Project::STATUS_AWAITING_CLIENT_CONFIRMATION,
            $this->project->refresh()->status
        );
    }

    /**
     * Stated against the policy as well as through the routes, because this is
     * where the two roles part company and it should be readable in one place.
     */
    public function test_the_phase_refusals_are_the_crews_alone(): void
    {
        $this->finalizePhases($this->project, 2);
        $this->closedTask();

        $policy = app(ProjectPolicy::class);
        $admin = $this->employee('admin', 'admin@example.test');
        $superAdmin = $this->employee('super_admin', 'boss@example.test');

        $this->assertNotSame([], $policy->blockersFor($this->project, $this->leadAccount));
        $this->assertNotSame([], $policy->blockersFor($this->project, $this->mate->account));
        // No viewer is the stricter reading, which is the crew's.
        $this->assertNotSame([], $policy->blockersFor($this->project));

        $this->assertSame([], $policy->blockersFor($this->project, $admin));
        $this->assertSame([], $policy->blockersFor($this->project, $superAdmin));
    }

    // ==================================================================
    // Fixtures
    // ==================================================================

    private function employee(string $role, string $email): User
    {
        $sequence = User::count() + 1;

        return User::create([
            'user_code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => 'Test Person '.$sequence,
            'first_name' => 'Test',
            'last_name' => 'Person',
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);
    }

    private function newProject(string $reference): Project
    {
        return Project::create([
            'name' => 'Aircon Retrofit',
            'reference_no' => $reference,
            'status' => 'ongoing',
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);
    }

    private function assign(Project $project, Technician $technician): void
    {
        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);
    }

    private function schedule(Project $project, int $from, int $to): void
    {
        Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $this->day($from).' 00:00:00',
            'end_datetime' => $this->day($to).' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);
    }

    private function day(int $offset): string
    {
        return CarbonImmutable::today()->addDays($offset)->toDateString();
    }

    private function task(Technician $technician, string $title, int $startsIn = 0): Task
    {
        return Task::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $technician->technician_id,
            'task_title' => $title,
            'task_description' => 'Do the thing',
            'start_date' => $this->day($startsIn),
            'due_date' => $this->day($startsIn + 2),
            'status' => 'pending',
        ]);
    }

    /**
     * A finished task, so nothing but the phases is outstanding. Completion
     * also refuses a project with no work recorded on it at all, and that is a
     * different rule from the one every test here is about.
     */
    private function closedTask(): Task
    {
        $task = $this->task($this->mate, 'Done', startsIn: -5);

        $task->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => $this->mate->account->id,
        ])->save();

        return $task;
    }

    /**
     * @return array<string, mixed>
     */
    private function completionPayload(bool $photos = true): array
    {
        return array_filter([
            'completion_date' => CarbonImmutable::today()->toDateString(),
            'completion_summary' => 'Everything on site is finished.',
            'completion_photos' => $photos
                ? [UploadedFile::fake()->image('handover.jpg')]
                : null,
        ], fn ($value): bool => $value !== null);
    }
}
