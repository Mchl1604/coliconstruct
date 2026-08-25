<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectCompletionPhoto;
use App\Models\ProjectCompletionReport;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two things that used to go wrong once a project was finished.
 *
 * A finished project could not be archived. Archive was drawn only for live
 * work, so Completed and Cancelled records - the ones there is nothing more to
 * do about - stayed in the active listing forever. They can be archived now,
 * by the Super Admin alone, and the archive keeps every one of them whole:
 * tasks, reports, documents, team and schedule.
 *
 * And reopening a project left its completion report where it was. That report
 * lives on the project's own columns, which is the one place every page reads
 * the CURRENT completion report from - so a project that was live again went
 * on presenting a finished project's report, and the next completion wrote
 * straight over it. The report is now filed away as history instead: marked
 * Superseded, numbered by cycle, with its photographs, and readable through
 * View Previous Completion Reports. Nothing is ever deleted.
 */
class ProjectCompletionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_EMAIL = 'history.owner@example.test';

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function technician(string $name): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => 'technician'])->save();

        return Technician::create(['account_id' => $user->id, 'role' => 'technician']);
    }

    private function clientAccount(): User
    {
        $user = User::factory()->create(['name' => 'History Owner', 'email' => self::CLIENT_EMAIL]);

        $user->forceFill(['role' => User::ROLE_CLIENT, 'status' => User::STATUS_ACTIVE] + $this->acceptedTerms())->save();

        return $user;
    }

    /**
     * A project with one finished task behind it, which is what the completion
     * rules ask for - these tests are about what happens after a completion,
     * not about the rules that permit one.
     */
    private function project(string $status = 'ongoing'): Project
    {
        $project = Project::create([
            'name' => 'History Project',
            'reference_no' => 'REF-'.strtoupper(substr(md5(uniqid('', true)), 0, 8)),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 5000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Residential',
            'firstname' => 'History',
            'surname' => 'Owner',
            'fullname' => 'History Owner',
            'email_address' => self::CLIENT_EMAIL,
            'contact_number' => '09123456789',
        ]);

        Task::create([
            'project_id' => $project->project_id,
            'task_title' => 'Work that was carried out',
            'task_description' => 'Description',
            'status' => 'completed',
            'completed_at' => CarbonImmutable::now(),
        ]);

        return $project;
    }

    private function assign(Project $project, Technician $technician): ProjectTechnician
    {
        return ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);
    }

    private function schedule(Project $project, int $startOffset, int $endOffset): Schedule
    {
        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => CarbonImmutable::today()->addDays($startOffset)->startOfDay(),
            'end_datetime' => CarbonImmutable::today()->addDays($endOffset)->endOfDay(),
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
     * Close the project out the way the application does, so every test starts
     * from a real state rather than a hand-written row.
     */
    private function requestCompletion(Project $project, string $summary): Project
    {
        $this->post(route('super-admin.projects.complete', $project->project_id), [
            'completion_date' => CarbonImmutable::today()->toDateString(),
            'completion_summary' => $summary,
        ])->assertRedirect();

        return $project->refresh();
    }

    /**
     * One photograph filed against the project's current completion cycle.
     */
    private function photo(Project $project, string $path): ProjectCompletionPhoto
    {
        return ProjectCompletionPhoto::create([
            'project_id' => $project->project_id,
            'photo_path' => $path,
            'uploaded_at' => now(),
        ]);
    }

    private function reopen(Project $project, string $reason, int $startOffset = 5, int $endOffset = 6): Project
    {
        $this->post(route('super-admin.projects.reopen', $project->project_id), [
            'reopen_reason' => $reason,
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => CarbonImmutable::today()->addDays($startOffset)->toDateString(),
            'end_date' => CarbonImmutable::today()->addDays($endOffset)->toDateString(),
        ])->assertRedirect();

        return $project->refresh();
    }

    /**
     * A project sitting in Awaiting Client Confirmation with a completion
     * report and a photograph behind it - the state every reopen starts from.
     */
    private function awaitingProject(string $summary = 'First visit finished.'): Project
    {
        $project = $this->project();
        $this->assign($project, $this->technician('Ana Reyes'.uniqid()));
        $this->schedule($project, -5, -2);

        $project = $this->requestCompletion($project, $summary);
        $this->photo($project, 'completion_photos/first-visit.jpg');

        return $project->refresh();
    }

    private function cancel(Project $project): Project
    {
        $this->post(route('super-admin.projects.cancel', $project->project_id), [
            'cancellation_date' => CarbonImmutable::today()->toDateString(),
            'cancellation_reason' => 'The client withdrew.',
        ])->assertRedirect();

        return $project->refresh();
    }

    // ==================================================================
    // Archiving a finished project
    // ==================================================================

    public function test_a_completed_project_offers_the_archive_button_to_the_super_admin(): void
    {
        // Kept rather than re-created: actingAsSuperAdmin() writes a fresh
        // EMP-0001 each time, and calling it twice in one test collides.
        $admin = $this->actingAsSuperAdmin();

        $project = $this->awaitingProject();

        $this->actingAs($this->clientAccount());
        $this->post(route('public.projects.confirm', $project->project_id));

        $this->assertSame('completed', $project->refresh()->status);

        $this->actingAs($admin);

        $this->get(route('super-admin.projects'))
            ->assertOk()
            ->assertSee('archiveProjectModal'.$project->project_id);
    }

    public function test_a_cancelled_project_offers_the_archive_button_to_the_super_admin(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Ben Cruz'));
        $this->schedule($project, 1, 3);
        $project = $this->cancel($project);

        $this->assertSame('cancelled', $project->status);

        $this->get(route('super-admin.projects'))
            ->assertOk()
            ->assertSee('archiveProjectModal'.$project->project_id);
    }

    /**
     * A project waiting on its client is read-only too, but it is a question
     * that has not been answered yet - archiving it would strand both the
     * answer and the seven-day clock.
     */
    public function test_a_project_awaiting_client_confirmation_is_not_archivable(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->awaitingProject();

        $this->assertFalse($project->isArchivable());

        $this->post(route('super-admin.projects.archive', $project->project_id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertFalse($project->refresh()->isArchived());
    }

    /**
     * Archiving is not deleting. Everything the project holds is still there
     * afterwards, and the project is readable on the archive page.
     */
    public function test_archiving_a_completed_project_keeps_every_record_it_holds(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $project = $this->awaitingProject();

        $this->actingAs($this->clientAccount());
        $this->post(route('public.projects.confirm', $project->project_id));
        $this->actingAs($admin);

        $technician = $project->projectTechnicians->first();

        $report = TechnicianReport::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
            'report_title' => 'Second-floor progress',
            'report_description' => 'All good.',
            'report_date' => CarbonImmutable::today()->toDateString(),
            'report_type' => 'progress',
        ]);

        $document = Document::create([
            'project_id' => $project->project_id,
            'document_type' => 'assessment',
            'document_name' => 'assessment.pdf',
            'document_path' => 'documents/assessment.pdf',
            'uploaded_at' => now(),
        ]);

        $this->post(route('super-admin.projects.archive', $project->project_id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $project->refresh();

        $this->assertTrue($project->isArchived());
        $this->assertSame('completed', $project->pre_archive_status);

        // Nothing was deleted on the way in.
        $this->assertDatabaseHas('tbl_projects', ['project_id' => $project->project_id]);
        $this->assertDatabaseHas('tbl_technician_reports', ['id' => $report->id]);
        $this->assertDatabaseHas('tbl_documents', ['document_id' => $document->document_id]);
        $this->assertSame(1, $project->tasks()->count());
        $this->assertSame(1, $project->projectTechnicians()->count());
        $this->assertSame(1, $project->schedules()->count());
        $this->assertSame(1, ProjectCompletionPhoto::where('project_id', $project->project_id)->count());
    }

    public function test_archiving_a_cancelled_project_takes_it_out_of_the_active_listing_only(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Cara Diaz'));
        $this->schedule($project, 1, 3);
        $project = $this->cancel($project);

        $this->post(route('super-admin.projects.archive', $project->project_id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $project->refresh();

        $this->assertTrue($project->isArchived());
        $this->assertSame('cancelled', $project->pre_archive_status);

        $this->get(route('super-admin.projects'))
            ->assertOk()
            ->assertDontSee($project->reference_no);

        $this->get(route('super-admin.projects.archived'))
            ->assertOk()
            ->assertSee($project->reference_no);
    }

    /**
     * Hiding a button is not a permission. An Admin posting straight at the
     * route is refused by the route itself.
     */
    public function test_an_admin_cannot_archive_a_completed_project_through_a_direct_request(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->awaitingProject();

        $this->actingAs($this->clientAccount());
        $this->post(route('public.projects.confirm', $project->project_id));

        $admin = User::factory()->create(['email' => 'plain.admin@example.test']);
        $admin->forceFill(['role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE])->save();

        $this->actingAs($admin);

        // An Admin in the wrong place is sent to their own portal rather than
        // shown a 403 - see EnsureUserHasRole. What matters is that nothing
        // was archived.
        $this->post(route('super-admin.projects.archive', $project->project_id))
            ->assertRedirect()
            ->assertSessionMissing('success');

        $this->assertFalse($project->refresh()->isArchived());
        $this->assertSame('completed', $project->status);

        // And the JSON-shaped version of the same request is refused outright.
        $this->postJson(route('super-admin.projects.archive', $project->project_id))
            ->assertForbidden();

        $this->assertFalse($project->refresh()->isArchived());
    }

    // ==================================================================
    // Reading an archived project
    // ==================================================================

    public function test_the_archive_listing_offers_a_view_button_into_the_existing_project_details_page(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Dan Elpo'));
        $project = $this->cancel($project);

        $this->post(route('super-admin.projects.archive', $project->project_id))->assertRedirect();

        $this->get(route('super-admin.projects.archived'))
            ->assertOk()
            ->assertSee(route('super-admin.projects.show', $project->project_id));
    }

    public function test_viewing_an_archived_project_leaves_it_archived(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Ella Faro'));
        $this->schedule($project, 1, 3);
        $project = $this->cancel($project);

        $this->post(route('super-admin.projects.archive', $project->project_id))->assertRedirect();

        $archivedAt = $project->refresh()->archived_at;

        $this->get(route('super-admin.projects.show', $project->project_id))->assertOk();

        $project->refresh();

        $this->assertTrue($project->isArchived());
        $this->assertSame('archived', $project->status);
        $this->assertSame('cancelled', $project->pre_archive_status);
        $this->assertEquals($archivedAt, $project->archived_at);

        // And it is still on the archive page, not back in the active list.
        $this->get(route('super-admin.projects.archived'))->assertOk()->assertSee($project->reference_no);
        $this->get(route('super-admin.projects'))->assertOk()->assertDontSee($project->reference_no);
    }

    /**
     * An archived project is already out of the active system, so the page
     * that reads it must not offer to take it out again.
     */
    public function test_an_archived_project_is_not_offered_the_archive_button(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $project = $this->cancel($project);

        $this->post(route('super-admin.projects.archive', $project->project_id))->assertRedirect();

        $this->assertFalse($project->refresh()->isArchivable());

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertDontSee('Archive Project');
    }

    // ==================================================================
    // Reopening: the report is filed away, never destroyed
    // ==================================================================

    public function test_reopening_files_the_completion_report_away_as_superseded_history(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $project = $this->awaitingProject('The first visit finished the wiring.');

        $this->assertTrue($project->hasCompletionReport());

        $project = $this->reopen($project, 'Snagging found on the second floor.');

        $this->assertSame('ongoing', $project->status);

        // Nothing is left on the project itself, so the normal completion
        // section has nothing to show.
        $this->assertFalse($project->hasCompletionReport());
        $this->assertNull($project->completed_at);
        $this->assertNull($project->completion_summary);

        $history = $project->completionReports()->get();

        $this->assertCount(1, $history);

        $superseded = $history->first();

        $this->assertSame(1, $superseded->cycle);
        $this->assertSame(ProjectCompletionReport::STATUS_SUPERSEDED, $superseded->status);
        $this->assertSame('The first visit finished the wiring.', $superseded->completion_summary);
        $this->assertSame(Project::STATUS_AWAITING_CLIENT_CONFIRMATION, $superseded->project_status);
        $this->assertSame('Snagging found on the second floor.', $superseded->supersede_reason);
        $this->assertSame($admin->id, (int) $superseded->superseded_by);
        $this->assertNotNull($superseded->superseded_at);
    }

    /**
     * The photographs are the evidence of the visit, so they go with the
     * report they belong to rather than staying beside the next one.
     */
    public function test_the_cycles_photographs_move_with_the_report_and_are_never_deleted(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->awaitingProject();
        $photoId = ProjectCompletionPhoto::where('project_id', $project->project_id)->value('completion_photo_id');

        $project = $this->reopen($project, 'More work was found on site.');

        $superseded = $project->completionReports()->first();

        $this->assertDatabaseHas('tbl_project_completion_photos', [
            'completion_photo_id' => $photoId,
            'completion_report_id' => $superseded->completion_report_id,
        ]);

        // The project's own completion photos are the current cycle's, and
        // there is no current cycle.
        $this->assertCount(0, $project->fresh()->completionPhotos);
        $this->assertCount(1, $superseded->photos);
    }

    public function test_a_reopened_project_shows_the_reopened_notice_instead_of_the_old_report(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->awaitingProject('Everything on site was finished.');
        $project = $this->reopen($project, 'The client reported a fault.');

        $response = $this->get(route('super-admin.projects.show', $project->project_id))->assertOk();

        $response->assertSee('Project Reopened');
        $response->assertSee('View Previous Completion Reports');
        $response->assertSee('The client reported a fault.');

        // The old report is not presented as this project's current one: the
        // normal completion section is simply not on the page. Its summary IS
        // still in the markup - it is inside the history dialog, which is
        // where it belongs and which a person has to open.
        $response->assertDontSee('class="completion-report"', false);
    }

    /**
     * The notice is about a project that is live again. Once it is finished
     * again it would be describing something no longer true.
     */
    public function test_the_reopened_notice_goes_away_once_the_project_is_finished_again(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->awaitingProject();
        $project = $this->reopen($project, 'A fault was reported by the client.');

        $this->assertTrue($project->showsReopenedNotice());

        $project = $this->requestCompletion($project, 'The fault has been put right.');

        $this->assertFalse($project->showsReopenedNotice());

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertDontSee('Project Reopened')
            ->assertSee('The fault has been put right.');
    }

    // ==================================================================
    // Completing again
    // ==================================================================

    public function test_completing_again_makes_a_new_current_report_and_keeps_the_previous_one(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->awaitingProject('The first visit finished the wiring.');
        $project = $this->reopen($project, 'The client reported a fault.');
        $project = $this->requestCompletion($project, 'The second visit put the fault right.');

        // The new report is the current one, on the project itself.
        $this->assertTrue($project->hasCompletionReport());
        $this->assertSame('The second visit put the fault right.', $project->completion_summary);

        // The first one is still there, and is still history.
        $history = $project->completionReports()->get();

        $this->assertCount(1, $history);
        $this->assertSame('The first visit finished the wiring.', $history->first()->completion_summary);
        $this->assertSame(ProjectCompletionReport::STATUS_SUPERSEDED, $history->first()->status);

        $response = $this->get(route('super-admin.projects.show', $project->project_id))->assertOk();

        $response->assertSee('The second visit put the fault right.');
        $response->assertSee('View Previous Completion Reports');
    }

    /**
     * The photographs of the new cycle belong to the new report, and the
     * earlier cycle's stay with theirs.
     */
    public function test_each_cycle_keeps_its_own_photographs(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->awaitingProject();
        $project = $this->reopen($project, 'The client reported a fault.');
        $project = $this->requestCompletion($project, 'The second visit put the fault right.');

        $this->photo($project, 'completion_photos/second-visit.jpg');

        $project->refresh();

        $this->assertCount(1, $project->completionPhotos);
        $this->assertSame(
            'completion_photos/second-visit.jpg',
            $project->completionPhotos->first()->photo_path
        );

        $this->assertSame(
            'completion_photos/first-visit.jpg',
            $project->completionReports()->first()->photos->first()->photo_path
        );

        // Two rows, both still on disk as far as the database is concerned.
        $this->assertSame(2, ProjectCompletionPhoto::where('project_id', $project->project_id)->count());
    }

    public function test_several_completion_cycles_each_keep_their_own_report(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->awaitingProject('Report one.');
        $project = $this->reopen($project, 'The first fault was reported.', 5, 6);
        $project = $this->requestCompletion($project, 'Report two.');
        $project = $this->reopen($project, 'A second fault was reported.', 20, 21);
        $project = $this->requestCompletion($project, 'Report three.');

        // Three cycles: two in the history, the third current.
        $history = $project->completionReports()->get();

        $this->assertCount(2, $history);
        $this->assertSame([2, 1], $history->pluck('cycle')->all());
        $this->assertSame(['Report two.', 'Report one.'], $history->pluck('completion_summary')->all());
        $history->each(fn (ProjectCompletionReport $report) => $this->assertTrue($report->isSuperseded()));

        $this->assertSame('Report three.', $project->completion_summary);

        $response = $this->get(route('super-admin.projects.show', $project->project_id))->assertOk();

        $response->assertSee('Completion Report #1');
        $response->assertSee('Completion Report #2');
        $response->assertSee('Superseded');
        $response->assertSee('Report three.');
    }

    /**
     * Reopening must not disturb anything else the project holds.
     */
    public function test_reopening_leaves_tasks_reports_documents_and_team_intact(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->awaitingProject();
        $technician = $project->projectTechnicians->first();

        $report = TechnicianReport::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
            'report_title' => 'Progress',
            'report_description' => 'All good.',
            'report_date' => CarbonImmutable::today()->toDateString(),
            'report_type' => 'progress',
        ]);

        $document = Document::create([
            'project_id' => $project->project_id,
            'document_type' => 'assessment',
            'document_name' => 'assessment.pdf',
            'document_path' => 'documents/assessment.pdf',
            'uploaded_at' => now(),
        ]);

        $project = $this->reopen($project, 'The client reported a fault.');

        $this->assertDatabaseHas('tbl_technician_reports', ['id' => $report->id]);
        $this->assertDatabaseHas('tbl_documents', ['document_id' => $document->document_id]);
        $this->assertSame(1, $project->tasks()->count());
        $this->assertSame(1, $project->projectTechnicians()->count());
    }

    /**
     * The history has to stay reachable on both sides of the client's reply,
     * not only while the project is ongoing. Once it is finished again the
     * notice is gone, so the button lives in the current report's own header -
     * and it must be there whether the project is still Awaiting Client
     * Confirmation or has reached Completed.
     */
    public function test_the_history_stays_reachable_once_the_project_is_awaiting_confirmation_again(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $project = $this->awaitingProject('Report one.');
        $project = $this->reopen($project, 'The client reported a fault.');
        $project = $this->requestCompletion($project, 'Report two.');

        $this->assertSame(Project::STATUS_AWAITING_CLIENT_CONFIRMATION, $project->status);

        $response = $this->get(route('super-admin.projects.show', $project->project_id))->assertOk();

        $response->assertSee('View Previous Completion Reports');
        $response->assertSee('Completion Report #1');
        $response->assertSee('Report two.');

        // And once the client signs it off.
        $this->actingAs($this->clientAccount());
        $this->post(route('public.projects.confirm', $project->project_id));
        $this->actingAs($admin);

        $this->assertSame('completed', $project->refresh()->status);

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('View Previous Completion Reports')
            ->assertSee('Completion Report #1');
    }

    /**
     * A project waiting on its client is a closed record: the work is done and
     * the client is reading the report on it. The endpoint has always refused
     * a report for one, but the button was still drawn, so pressing it could
     * only ever produce an error.
     */
    public function test_a_project_awaiting_client_confirmation_is_not_offered_the_add_report_button(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->awaitingProject();

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertDontSee('addTechnicianReportModal');

        // And the endpoint behind it still refuses, whatever the page drew.
        $this->post(route('super-admin.technician.reports.store', $project->project_id), [
            'report_type' => 'progress',
            'report_title' => 'Filed after the work finished',
            'report_description' => 'Should not be accepted.',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, TechnicianReport::where('project_id', $project->project_id)->count());
    }

    /**
     * The same rule for the other closed records, so the button is not simply
     * missing for one status and present for the rest.
     */
    public function test_a_completed_or_cancelled_project_is_not_offered_the_add_report_button(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $completed = $this->awaitingProject();

        $this->actingAs($this->clientAccount());
        $this->post(route('public.projects.confirm', $completed->project_id));
        $this->actingAs($admin);

        $this->assertSame('completed', $completed->refresh()->status);

        $this->get(route('super-admin.projects.show', $completed->project_id))
            ->assertOk()
            ->assertDontSee('addTechnicianReportModal');

        $cancelled = $this->project();
        $this->assign($cancelled, $this->technician('Ivy Jara'));
        $cancelled = $this->cancel($cancelled);

        $this->get(route('super-admin.projects.show', $cancelled->project_id))
            ->assertOk()
            ->assertDontSee('addTechnicianReportModal');
    }

    /**
     * Live work still gets it, or the fix above would have taken the feature
     * away rather than narrowed it.
     */
    public function test_an_ongoing_project_still_offers_the_add_report_button(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Hana Ilo'));
        $this->schedule($project, -1, 3);

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('addTechnicianReportModal');
    }

    /**
     * A cancelled project was abandoned, not finished. It has no completion
     * cycle, so nothing may file one for it.
     */
    public function test_a_cancelled_project_has_no_completion_report_of_either_kind(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project();
        $this->assign($project, $this->technician('Gus Haro'));
        $this->schedule($project, 1, 3);
        $project = $this->cancel($project);

        $this->assertFalse($project->hasCompletionReport());
        $this->assertSame(0, $project->completionReports()->count());

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('Cancellation Report')
            ->assertDontSee('View Previous Completion Reports');
    }
}
