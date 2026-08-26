<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\ProjectType;
use App\Models\Schedule;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The portal improvements: the client's filter bar and document buttons, the
 * dismissible toasts, the Admin/Super Admin split over archiving, the lead's
 * report filters, and the redesigned Project Details header.
 *
 * Each one is a rule about what a role may see or do, which is exactly the
 * kind of thing that breaks silently in a Blade template.
 */
class PortalImprovementsTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role, string $email): User
    {
        return User::create([
            'user_code' => strtoupper(substr($role, 0, 3)).'-'.random_int(1000, 9999),
            'name' => ucfirst($role),
            'first_name' => ucfirst($role),
            'last_name' => 'Person',
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'is_archived' => false,
            'must_change_password' => false,
            'password' => 'password',
        ] + $this->acceptedTerms());
    }

    private function project(string $email): Project
    {
        $type = ProjectType::create(['type_name' => 'Aircon Installation']);

        $project = Project::create([
            'reference_no' => 'PRJ-1',
            'name' => 'Dela Cruz',
            'status' => 'ongoing',
            'quotation' => 1500,
            'address' => '12 Mabini Street',
            'description' => "Line one\nLine two",
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Residential',
            'firstname' => 'Juan',
            'surname' => 'Dela Cruz',
            'fullname' => 'Juan Dela Cruz',
            'email_address' => $email,
            'contact_number' => '09171234567',
        ]);

        $project->projectTypes()->sync([$type->type_id]);

        Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => now()->subDay(),
            'end_datetime' => now()->addDay(),
            'status' => 'scheduled',
        ]);

        Document::create([
            'project_id' => $project->project_id,
            'document_type' => 'assessment',
            'document_name' => 'a.pdf',
            'document_path' => 'uploads/assessment/a.pdf',
            'uploaded_at' => now(),
        ]);

        return $project->refresh();
    }

    public function test_client_my_projects_has_filters_and_no_progress_bar(): void
    {
        $client = $this->account('client', 'c@example.test');
        $this->project($client->email);

        $this->actingAs($client)
            ->get(route('public.projects'))
            ->assertOk()
            ->assertSee('data-project-filter', escape: false)
            ->assertSee('data-project-search', escape: false)
            ->assertSee('Overdue')
            ->assertDontSee('project-card-progress', escape: false)
            ->assertDontSee('% complete');
    }

    public function test_client_project_details_shows_one_type_and_document_buttons(): void
    {
        $client = $this->account('client', 'c@example.test');
        $project = $this->project($client->email);

        // The buttons link at the route that serves the file, in a new tab,
        // the way the administrative pages open the same documents. Not at a
        // path under public/: the bytes are on a private disk and the route
        // is what checks this client may have them.
        $response = $this->actingAs($client)
            ->get(route('public.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('Project Documents')
            ->assertSee($project->documents()->firstOrFail()->url(), escape: false)
            // The group heading, not the bare word: the website's small print
            // mentions quotations, and the footer's Terms and Conditions
            // dialog now puts that sentence on every public page. What this
            // asserts is that the project has no Quotation GROUP - a group
            // with no files in it is left out entirely, and a client should
            // not be shown that a document is missing.
            ->assertDontSee('<span class="fw-semibold">Quotation</span>', escape: false);

        // The project type appears exactly once now that the text field is gone.
        $this->assertSame(1, substr_count($response->getContent(), 'Aircon Installation'));
    }

    public function test_the_client_notification_modal_replaces_the_page_link(): void
    {
        $client = $this->account('client', 'c@example.test');

        $this->actingAs($client)
            ->get(route('public.projects'))
            ->assertOk()
            ->assertSee('notificationCenterModal')
            ->assertSee('data-modal-list', escape: false)
            ->assertDontSee('data-center-module', escape: false);
    }

    public function test_toasts_carry_a_close_button(): void
    {
        $client = $this->account('client', 'c@example.test');

        $this->actingAs($client)
            ->withSession(['success' => 'Schedule updated successfully.'])
            ->get(route('public.projects'))
            ->assertOk()
            ->assertSee('Schedule updated successfully.')
            ->assertSee('data-bs-dismiss="toast"', escape: false);
    }

    public function test_an_admin_has_no_archive_controls(): void
    {
        $admin = $this->account('admin', 'a@example.test');
        $this->project('c@example.test');

        $this->actingAs($admin)
            ->get(route('super-admin.projects'))
            ->assertOk()
            ->assertDontSee('View Archived Projects')
            ->assertDontSee('Archive Project');

        $this->actingAs($admin)
            ->get(route('super-admin.configuration.index'))
            ->assertOk()
            ->assertDontSee('System Settings')
            ->assertDontSee('View Archived Accounts');

        // And the routes refuse them, not just the buttons.
        $this->actingAs($admin)->get(route('super-admin.projects.archived'))->assertRedirect();
        $this->actingAs($admin)->getJson(route('super-admin.configuration.users.archived'))->assertForbidden();
    }

    /**
     * Completing a project stays with both administrative roles. What decides
     * whether the button is drawn is mostly the project's state rather than
     * who is looking: an Unscheduled or paused project has had nobody on site
     * yet, so there is nothing to close out. Pending is the one exception, and
     * it belongs to a Super Admin alone - see the test below.
     *
     * Overdue keeps its button. Overdue is derived rather than stored - a late
     * project is stored as Ongoing - and closing it off is exactly what the
     * overdue banner asks for.
     */
    public function test_both_administrative_roles_may_complete_work_under_way(): void
    {
        $project = $this->project('c@example.test');

        foreach (['admin' => 'a@example.test', 'super_admin' => 'o@example.test'] as $role => $email) {
            $this->actingAs($this->account($role, $email))
                ->get(route('super-admin.projects'))
                ->assertOk()
                ->assertSee('data-bs-target="#completeProjectModal'.$project->project_id.'"', escape: false);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonCompletableProjectStates(): array
    {
        return ['unscheduled' => ['unscheduled'], 'on hold' => ['on_hold']];
    }

    /**
     * Each state is reached through its dates rather than by writing the
     * status, because the projects listing re-derives it as it draws - see
     * ProjectController::updateStatus(). A status set behind the calendar's
     * back would simply be corrected before the page rendered, and the test
     * would be asserting about a project in a state it was not in.
     */
    #[DataProvider('nonCompletableProjectStates')]
    public function test_no_completion_button_is_offered_for_work_nobody_has_started(string $state): void
    {
        $project = $this->project('c@example.test');

        match ($state) {
            // No dates at all.
            'unscheduled' => $project->schedules()->delete(),
            // Paused. The calendar does not decide a held project's status, so
            // this one keeps its dates and stays Ongoing underneath.
            'on_hold' => $project->update(['on_hold' => true]),
        };

        foreach (['admin' => 'a@example.test', 'super_admin' => 'o@example.test'] as $role => $email) {
            $this->actingAs($this->account($role, $email))
                ->get(route('super-admin.projects'))
                ->assertOk()
                ->assertDontSee(
                    'data-bs-target="#completeProjectModal'.$project->project_id.'"',
                    escape: false
                );
        }
    }

    /**
     * Pending is the one state a Super Admin is trusted with and an Admin is
     * not: work that is booked but has not reached its first day is still
     * theirs to close out - see Project::SUPER_ADMIN_COMPLETABLE_STATUSES.
     *
     * Booked forward rather than by writing the status, for the reason above.
     */
    public function test_only_a_super_admin_is_offered_completion_for_pending_work(): void
    {
        $project = $this->project('c@example.test');

        $project->schedules()->update([
            'start_datetime' => now()->addDays(3),
            'end_datetime' => now()->addDays(5),
        ]);

        $target = 'data-bs-target="#completeProjectModal'.$project->project_id.'"';

        $this->actingAs($this->account('super_admin', 'o@example.test'))
            ->get(route('super-admin.projects'))
            ->assertOk()
            ->assertSee($target, escape: false);

        $this->actingAs($this->account('admin', 'a@example.test'))
            ->get(route('super-admin.projects'))
            ->assertOk()
            ->assertDontSee($target, escape: false);
    }

    public function test_a_super_admin_keeps_the_archive(): void
    {
        $owner = $this->account('super_admin', 'o@example.test');
        $project = $this->project('c@example.test');
        $project->update(['is_archived' => true, 'status' => 'archived', 'archived_at' => now(), 'archived_by' => $owner->id]);

        $this->actingAs($owner)
            ->get(route('super-admin.projects.archived'))
            ->assertOk()
            ->assertSee('Reference No.')
            ->assertSee('Client Type')
            ->assertSee('Archived By')
            ->assertSee('Aircon Installation')
            ->assertSee($owner->fullName());

        $this->actingAs($owner)
            ->get(route('super-admin.configuration.index'))
            ->assertOk()
            ->assertSee('View Archived Accounts')
            ->assertSee('System Settings');
    }

    public function test_archived_accounts_list_and_restore(): void
    {
        $owner = $this->account('super_admin', 'o@example.test');
        $victim = $this->account('technician', 't@example.test');

        $this->actingAs($owner)
            ->delete(route('super-admin.configuration.users.archive', $victim))
            ->assertOk();

        $this->actingAs($owner)
            ->getJson(route('super-admin.configuration.users.archived'))
            ->assertOk()
            ->assertJsonPath('rows.0.email', 't@example.test')
            ->assertJsonPath('rows.0.archived_by', $owner->fullName())
            ->assertJsonPath('rows.0.status_label', 'Archived');

        $this->actingAs($owner)
            ->putJson(route('super-admin.configuration.users.restore', $victim))
            ->assertOk();

        $this->assertFalse($victim->refresh()->is_archived);
        $this->assertTrue($victim->isActive());
        $this->assertNull($victim->archived_by);

        $this->assertDatabaseHas('tbl_activity_logs', ['action' => 'Employee Restored']);
    }

    public function test_the_lead_reports_page_has_the_filter_bar(): void
    {
        $lead = $this->account('lead_technician', 'l@example.test');
        Technician::create(['account_id' => $lead->id, 'role' => 'lead_technician', 'name' => 'Lead Person']);

        $this->actingAs($lead)
            ->get(route('technician.reports'))
            ->assertOk()
            ->assertSee('data-filter-project', escape: false)
            ->assertSee('data-filter-type', escape: false)
            ->assertSee('data-filter-date', escape: false)
            ->assertSee('data-filter-search', escape: false);
    }

    /**
     * The staff Project Details pages keep their original layout; what changed
     * is the colour, which comes from the page wrapper.
     */
    public function test_the_staff_project_details_pages_wear_the_brand_blue(): void
    {
        $owner = $this->account('super_admin', 'o@example.test');
        $project = $this->project('c@example.test');

        $this->actingAs($owner)
            ->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('project-details-page', escape: false)
            ->assertSee('project-type-badge', escape: false)
            ->assertSee('project-reference', escape: false)
            ->assertSee('Project ID: '.sprintf('PROJ-%04d', $project->project_id))
            ->assertSee('PRJ-1')
            ->assertSee('Project Details')
            ->assertSee('Assigned Team')
            ->assertSee('Project Schedule')
            ->assertSee('Project Activity');

        $tech = $this->account('technician', 't@example.test');
        $technician = Technician::create(['account_id' => $tech->id, 'role' => 'technician', 'name' => 'Tech Person']);
        ProjectTechnician::create(['project_id' => $project->project_id, 'technician_id' => $technician->technician_id]);

        $this->actingAs($tech)
            ->get(route('technician.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('project-details-page', escape: false)
            ->assertSee('project-type-badge', escape: false)
            ->assertSee('Project ID: '.sprintf('PROJ-%04d', $project->project_id))
            ->assertSee('Assigned Team')
            // The assigned team still carries the picture, role and approved
            // specialties the profile work added.
            ->assertSee(asset('img/default-avatar.svg'), escape: false)
            ->assertSee('Project Activity');
    }
}
