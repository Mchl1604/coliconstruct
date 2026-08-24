<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\TechnicianReportImage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Archiving a technician report.
 *
 * Two questions are asked over and over here, because they are the whole
 * feature: who may archive a report, and what survives when one is archived.
 *
 * The permission model is exact and is enforced on the endpoint rather than in
 * the pages that draw the buttons:
 *
 *   Lead Technician  only the reports they filed themselves.
 *   Admin            any report, whoever filed it.
 *   Super Admin      any report, whoever filed it.
 *
 * And archiving is a decision about a list, never about the record: the report
 * keeps its project, its technician, its submitter, its images and the day it
 * was filed, and the project it belongs to is not touched at all.
 */
class TechnicianReportArchiveTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $admin;

    private User $leadAccount;

    private Technician $lead;

    private User $otherLeadAccount;

    private Technician $otherLead;

    private User $technicianAccount;

    private Technician $technician;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = $this->account('super_admin', 'owner@example.test');
        $this->admin = $this->account('admin', 'admin@example.test');

        $this->leadAccount = $this->account('lead_technician', 'lead@example.test');
        $this->lead = Technician::create([
            'account_id' => $this->leadAccount->id,
            'role' => 'lead_technician',
        ]);

        $this->otherLeadAccount = $this->account('lead_technician', 'other.lead@example.test');
        $this->otherLead = Technician::create([
            'account_id' => $this->otherLeadAccount->id,
            'role' => 'lead_technician',
        ]);

        $this->technicianAccount = $this->account('technician', 'mate@example.test');
        $this->technician = Technician::create([
            'account_id' => $this->technicianAccount->id,
            'role' => 'technician',
        ]);

        $this->project = $this->project('Aircon Retrofit');
        $this->assign($this->project, $this->lead);
        $this->assign($this->project, $this->otherLead);
        $this->assign($this->project, $this->technician);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function account(string $role, string $email): User
    {
        $sequence = User::count() + 1;

        return User::create([
            'user_code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => 'Test Person '.$sequence,
            'first_name' => 'Test',
            'last_name' => 'Person '.$sequence,
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);
    }

    private function project(string $name, string $status = 'ongoing', bool $archived = false): Project
    {
        $project = Project::create([
            'name' => $name,
            'reference_no' => 'REF-'.strtoupper(substr(md5($name.microtime()), 0, 8)),
            'status' => $status,
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
            'is_archived' => $archived,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Commercial',
            'company_name' => $name.' Holdings',
            'firstname' => 'Client',
            'surname' => 'Of '.$project->project_id,
            'fullname' => 'Client Of '.$project->project_id,
            'email_address' => 'client'.$project->project_id.'@example.test',
            'contact_number' => '09123456789',
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

    /**
     * A report filed by $submitter, against $technician's work.
     */
    private function report(
        ?User $submitter,
        ?Technician $technician = null,
        ?Project $project = null,
        int $images = 0,
        string $title = 'Site visit'
    ): TechnicianReport {
        $report = TechnicianReport::create([
            'project_id' => ($project ?? $this->project)->project_id,
            'technician_id' => ($technician ?? $this->lead)->technician_id,
            'submitted_by' => $submitter?->id,
            'report_type' => 'progress',
            'report_title' => $title,
            'report_description' => "First line\nSecond line",
            'report_date' => CarbonImmutable::today()->toDateString(),
        ]);

        for ($index = 0; $index < $images; $index++) {
            TechnicianReportImage::create([
                'technician_report_id' => $report->id,
                'image_path' => 'report_images/sample-'.$report->id.'-'.$index.'.jpg',
            ]);
        }

        return $report;
    }

    private function archive(User $actor, TechnicianReport $report)
    {
        return $this->actingAs($actor)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('technician-reports.archive', $report->id));
    }

    private function restore(User $actor, TechnicianReport $report)
    {
        return $this->actingAs($actor)
            ->withHeaders(['Accept' => 'application/json'])
            ->put(route('technician-reports.restore', $report->id));
    }

    // ------------------------------------------------------------------
    // Lead technician: only their own
    // ------------------------------------------------------------------

    public function test_a_lead_technician_archives_a_report_they_submitted(): void
    {
        $report = $this->report($this->leadAccount);

        $this->archive($this->leadAccount, $report)->assertOk();

        $this->assertTrue($report->refresh()->isArchived());
        $this->assertSame($this->leadAccount->id, (int) $report->archived_by);
        $this->assertNotNull($report->archived_at);
    }

    public function test_a_lead_technician_cannot_archive_another_leads_report(): void
    {
        $report = $this->report($this->otherLeadAccount, $this->otherLead);

        $this->archive($this->leadAccount, $report)->assertForbidden();

        $this->assertFalse($report->refresh()->isArchived());
    }

    public function test_a_lead_technician_cannot_archive_a_technicians_report(): void
    {
        $report = $this->report($this->technicianAccount, $this->technician);

        $this->archive($this->leadAccount, $report)->assertForbidden();

        $this->assertFalse($report->refresh()->isArchived());
    }

    public function test_a_lead_technician_cannot_archive_an_admins_report(): void
    {
        // Filed by an administrator and credited to this very lead's technician
        // record, which is what the Reports page does when the form does not
        // say otherwise. Ownership is the submitting account, so this is still
        // not the lead's report to archive.
        $report = $this->report($this->admin, $this->lead);

        $this->archive($this->leadAccount, $report)->assertForbidden();

        $this->assertFalse($report->refresh()->isArchived());
    }

    public function test_a_lead_technician_archives_a_legacy_report_filed_under_their_own_record(): void
    {
        // Reports written before submitted_by existed carry no account, and for
        // those the technician the report is filed under is who wrote it.
        $report = $this->report(null, $this->lead);

        $this->archive($this->leadAccount, $report)->assertOk();

        $this->assertTrue($report->refresh()->isArchived());
    }

    public function test_a_lead_technician_cannot_archive_a_legacy_report_filed_under_somebody_else(): void
    {
        $report = $this->report(null, $this->otherLead);

        $this->archive($this->leadAccount, $report)->assertForbidden();

        $this->assertFalse($report->refresh()->isArchived());
    }

    // ------------------------------------------------------------------
    // Administrators: anybody's
    // ------------------------------------------------------------------

    public function test_an_admin_archives_their_own_report(): void
    {
        $report = $this->report($this->admin);

        $this->archive($this->admin, $report)->assertOk();

        $this->assertTrue($report->refresh()->isArchived());
    }

    public function test_an_admin_archives_a_lead_technicians_report(): void
    {
        $report = $this->report($this->leadAccount);

        $this->archive($this->admin, $report)->assertOk();

        $this->assertTrue($report->refresh()->isArchived());
    }

    public function test_an_admin_archives_another_admins_report(): void
    {
        $otherAdmin = $this->account('admin', 'second.admin@example.test');
        $report = $this->report($otherAdmin);

        $this->archive($this->admin, $report)->assertOk();

        $this->assertTrue($report->refresh()->isArchived());
    }

    public function test_a_super_admin_archives_any_report(): void
    {
        foreach ([$this->leadAccount, $this->technicianAccount, $this->admin] as $submitter) {
            $report = $this->report($submitter);

            $this->archive($this->superAdmin, $report)->assertOk();

            $this->assertTrue($report->refresh()->isArchived());
        }
    }

    // ------------------------------------------------------------------
    // The endpoint is the rule, not the button
    // ------------------------------------------------------------------

    public function test_a_plain_technician_cannot_reach_the_archive_endpoint(): void
    {
        $report = $this->report($this->technicianAccount, $this->technician);

        $this->archive($this->technicianAccount, $report)->assertForbidden();

        $this->assertFalse($report->refresh()->isArchived());
    }

    public function test_a_client_cannot_reach_the_archive_endpoint(): void
    {
        $client = $this->account('client', 'client.account@example.test');
        $report = $this->report($this->leadAccount);

        $this->archive($client, $report)->assertForbidden();

        $this->assertFalse($report->refresh()->isArchived());
    }

    public function test_a_guest_cannot_archive_a_report(): void
    {
        $report = $this->report($this->leadAccount);

        $this->post(route('technician-reports.archive', $report->id))
            ->assertRedirect(route('auth.login'));

        $this->assertFalse($report->refresh()->isArchived());
    }

    public function test_changing_the_report_id_does_not_get_a_lead_past_the_check(): void
    {
        $mine = $this->report($this->leadAccount);
        $theirs = $this->report($this->otherLeadAccount, $this->otherLead);

        // The lead may archive one of these and not the other, and which is
        // which is decided per report rather than by the fact they are a lead.
        $this->archive($this->leadAccount, $mine)->assertOk();
        $this->archive($this->leadAccount, $theirs)->assertForbidden();

        $this->assertTrue($mine->refresh()->isArchived());
        $this->assertFalse($theirs->refresh()->isArchived());
    }

    public function test_archiving_a_report_twice_is_refused_and_changes_nothing(): void
    {
        $report = $this->report($this->leadAccount);

        $this->archive($this->superAdmin, $report)->assertOk();

        $archivedAt = $report->refresh()->archived_at;

        $this->archive($this->admin, $report)
            ->assertStatus(422)
            ->assertJsonPath('error', 'That report is already archived.');

        $report->refresh();

        $this->assertTrue($report->isArchived());
        $this->assertSame($this->superAdmin->id, (int) $report->archived_by);
        $this->assertEquals($archivedAt, $report->archived_at);
    }

    // ------------------------------------------------------------------
    // Nothing is lost
    // ------------------------------------------------------------------

    public function test_archiving_keeps_every_detail_of_the_report(): void
    {
        $report = $this->report($this->leadAccount, $this->lead, null, 3);
        $filedOn = $report->report_date;

        $this->archive($this->superAdmin, $report)->assertOk();

        $report->refresh()->load('images');

        $this->assertSame($this->project->project_id, (int) $report->project_id);
        $this->assertSame($this->lead->technician_id, (int) $report->technician_id);
        $this->assertSame($this->leadAccount->id, (int) $report->submitted_by);
        $this->assertSame('Site visit', $report->report_title);
        $this->assertSame("First line\nSecond line", $report->report_description);
        $this->assertEquals($filedOn, $report->report_date);
        $this->assertCount(3, $report->images);

        // The files themselves are untouched: an image still resolves to the
        // route that serves it, which is what every page links to.
        $this->assertDatabaseCount('tbl_technician_report_images', 3);
    }

    public function test_archiving_does_not_touch_the_project_its_schedule_or_its_team(): void
    {
        $schedule = Schedule::create([
            'project_id' => $this->project->project_id,
            'start_datetime' => CarbonImmutable::today()->toDateString().' 00:00:00',
            'end_datetime' => CarbonImmutable::today()->addDays(5)->toDateString().' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        // Read from the stored row, so the comparison is between two states of
        // the project rather than between a model and a row.
        $before = $this->project->refresh()->only(['status', 'is_archived', 'on_hold']);
        $report = $this->report($this->leadAccount);

        $this->archive($this->superAdmin, $report)->assertOk();

        $this->assertSame($before, $this->project->refresh()->only(['status', 'is_archived', 'on_hold']));
        $this->assertDatabaseHas('tbl_schedule', ['schedule_id' => $schedule->schedule_id]);
        $this->assertSame(3, ProjectTechnician::where('project_id', $this->project->project_id)->count());
    }

    // ------------------------------------------------------------------
    // Closed projects still archive
    // ------------------------------------------------------------------

    /**
     * A report on work that is over is exactly what somebody wants to file
     * away, so a closed project must not stand in the way of archiving one.
     */
    public function test_reports_on_completed_cancelled_and_archived_projects_can_be_archived(): void
    {
        foreach (['completed', 'cancelled'] as $status) {
            $project = $this->project('Closed '.$status, $status);
            $report = $this->report($this->leadAccount, $this->lead, $project);

            $this->archive($this->superAdmin, $report)->assertOk();

            $this->assertTrue($report->refresh()->isArchived());
        }

        $archivedProject = $this->project('Filed away', 'archived', true);
        $report = $this->report($this->leadAccount, $this->lead, $archivedProject);

        $this->archive($this->superAdmin, $report)->assertOk();

        $this->assertTrue($report->refresh()->isArchived());
    }

    // ------------------------------------------------------------------
    // Restoring
    // ------------------------------------------------------------------

    public function test_an_archived_report_is_restored_without_being_duplicated(): void
    {
        $report = $this->report($this->leadAccount, $this->lead, null, 2);

        $this->archive($this->superAdmin, $report)->assertOk();
        $this->restore($this->superAdmin, $report)->assertOk();

        $report->refresh()->load('images');

        $this->assertFalse($report->isArchived());
        $this->assertNull($report->archived_at);
        $this->assertNull($report->archived_by);
        $this->assertSame($this->leadAccount->id, (int) $report->submitted_by);
        $this->assertSame($this->project->project_id, (int) $report->project_id);
        $this->assertSame($this->lead->technician_id, (int) $report->technician_id);
        $this->assertCount(2, $report->images);
        $this->assertSame(1, TechnicianReport::where('report_title', 'Site visit')->count());
    }

    public function test_restoring_a_report_that_is_not_archived_is_refused(): void
    {
        $report = $this->report($this->leadAccount);

        $this->restore($this->superAdmin, $report)
            ->assertStatus(422)
            ->assertJsonPath('error', 'That report is not archived.');
    }

    public function test_a_lead_technician_restores_only_their_own_archived_report(): void
    {
        $mine = $this->report($this->leadAccount);
        $theirs = $this->report($this->otherLeadAccount, $this->otherLead);

        $this->archive($this->superAdmin, $mine)->assertOk();
        $this->archive($this->superAdmin, $theirs)->assertOk();

        $this->restore($this->leadAccount, $theirs)->assertForbidden();
        $this->assertTrue($theirs->refresh()->isArchived());

        $this->restore($this->leadAccount, $mine)->assertOk();
        $this->assertFalse($mine->refresh()->isArchived());
    }

    // ------------------------------------------------------------------
    // The lists
    // ------------------------------------------------------------------

    public function test_an_archived_report_leaves_the_active_reports_list_and_appears_in_the_archive(): void
    {
        $archived = $this->report($this->leadAccount, $this->lead, null, 0, 'Filed away');
        $active = $this->report($this->leadAccount, $this->lead, null, 0, 'Still open');

        $this->archive($this->superAdmin, $archived)->assertOk();

        $listing = $this->actingAs($this->superAdmin)
            ->getJson(route('super-admin.reports.technician'))
            ->assertOk();

        $titles = collect($listing->json('reports'))->pluck('report_title')->all();

        $this->assertContains('Still open', $titles);
        $this->assertNotContains('Filed away', $titles);
        $this->assertSame(1, $listing->json('meta.total'));

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.reports.archived'))
            ->assertOk()
            ->assertSee('Filed away')
            ->assertDontSee('Still open');
    }

    public function test_a_report_archived_from_project_details_leaves_the_reports_page_and_the_other_way_round(): void
    {
        $fromDetails = $this->report($this->leadAccount, $this->lead, null, 0, 'Archived from details');
        $fromReports = $this->report($this->leadAccount, $this->lead, null, 0, 'Archived from reports');

        // The Project Details form posts without asking for JSON, and comes
        // back with the flashed toast the layout raises.
        $this->actingAs($this->superAdmin)
            ->from(route('super-admin.projects.show', $this->project->project_id))
            ->post(route('technician-reports.archive', $fromDetails->id))
            ->assertRedirect(route('super-admin.projects.show', $this->project->project_id))
            ->assertSessionHas('success', 'Report archived successfully.');

        $this->archive($this->superAdmin, $fromReports)->assertOk();

        // Neither is on the Reports page any longer.
        $titles = collect(
            $this->actingAs($this->superAdmin)
                ->getJson(route('super-admin.reports.technician'))
                ->json('reports')
        )->pluck('report_title')->all();

        $this->assertSame([], $titles);

        // Nor on the project's own report list, whichever page filed them away.
        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertDontSee('Archived from details')
            ->assertDontSee('Archived from reports');
    }

    public function test_a_restored_report_returns_to_both_active_lists(): void
    {
        $report = $this->report($this->leadAccount, $this->lead, null, 0, 'Back again');

        $this->archive($this->superAdmin, $report)->assertOk();
        $this->restore($this->superAdmin, $report)->assertOk();

        $titles = collect(
            $this->actingAs($this->superAdmin)
                ->getJson(route('super-admin.reports.technician'))
                ->json('reports')
        )->pluck('report_title')->all();

        $this->assertSame(['Back again'], $titles);

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertSee('Back again');
    }

    public function test_the_lead_portal_lists_active_and_archived_reports_separately(): void
    {
        $archived = $this->report($this->leadAccount, $this->lead, null, 0, 'Lead filed away');
        $this->report($this->leadAccount, $this->lead, null, 0, 'Lead still open');

        $this->archive($this->leadAccount, $archived)->assertOk();

        $this->actingAs($this->leadAccount)
            ->get(route('technician.reports'))
            ->assertOk()
            ->assertSee('Lead still open')
            ->assertDontSee('Lead filed away');

        $this->actingAs($this->leadAccount)
            ->get(route('technician.reports.archived'))
            ->assertOk()
            ->assertSee('Lead filed away')
            ->assertDontSee('Lead still open');
    }

    public function test_the_action_flags_a_row_carries_match_the_policy(): void
    {
        $mine = $this->report($this->leadAccount);
        $theirs = $this->report($this->otherLeadAccount, $this->otherLead);

        // What the buttons follow. A lead sees Archive on their own report and
        // on no other; an administrator sees it on both.
        $this->assertTrue($this->leadAccount->can('archive', $mine));
        $this->assertFalse($this->leadAccount->can('archive', $theirs));
        $this->assertTrue($this->admin->can('archive', $theirs));
        $this->assertTrue($this->superAdmin->can('archive', $theirs));

        $rows = collect(
            $this->actingAs($this->admin)
                ->getJson(route('super-admin.reports.technician'))
                ->json('reports')
        );

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn (array $row): bool => $row['can_archive'] === true));
        $this->assertTrue($rows->every(fn (array $row): bool => $row['is_archived'] === false));
    }
}
