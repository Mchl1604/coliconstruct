<?php

namespace Tests\Feature;

use App\Mail\ContactInquiryMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Inquiry;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use App\Services\DashboardMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The low-severity findings from the system audit.
 */
class LowSeverityAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private function technician(string $name): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => mb_strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => 'technician', 'status' => User::STATUS_ACTIVE])->save();

        return Technician::create(['account_id' => $user->id, 'role' => 'technician']);
    }

    private function project(array $technicians = [], string $status = 'ongoing'): Project
    {
        $project = Project::create([
            'name' => 'Low Project '.uniqid(),
            'reference_no' => 'REF-'.strtoupper(uniqid()),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 1000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Commercial',
            'company_name' => 'Low Holdings',
            'firstname' => 'Low',
            'surname' => 'Client',
            'fullname' => 'Low Client',
            'email_address' => 'low.client@example.test',
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
     * The Contact form only offers itself when a message would actually reach
     * somebody, so a test that posts one has to say the mailer works.
     */
    private function withWorkingMailer(string $recipient = 'inbox@coliconstruct.test'): void
    {
        Config::set('mail.default', 'smtp');
        Config::set('mail.inquiries_to', $recipient);
    }

    // ==================================================================
    // BUG-021 - the row dialogs are not children of <tbody>
    // ==================================================================

    public function test_the_project_dialogs_are_not_written_inside_the_table_body(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([$this->technician('Markup Person')]);
        $this->book($project, 0, 2);

        $html = $this->get(route('super-admin.projects'))->assertOk()->getContent();

        $tbodyStart = mb_strpos($html, '<tbody>');
        $tbodyEnd = mb_strpos($html, '</tbody>');

        $this->assertNotFalse($tbodyStart);
        $this->assertNotFalse($tbodyEnd);

        $tbody = mb_substr($html, $tbodyStart, $tbodyEnd - $tbodyStart);

        // The dialogs still exist - they simply are not in here.
        $this->assertStringNotContainsString('modal fade', $tbody);
        $this->assertStringContainsString('onHoldModal'.$project->project_id, $html);
        $this->assertStringContainsString('completeProjectModal'.$project->project_id, $html);
    }

    // ==================================================================
    // BUG-022 - On Hold is a state the table can be filtered by
    // ==================================================================

    public function test_the_projects_page_offers_an_on_hold_tab_with_a_count(): void
    {
        $this->actingAsSuperAdmin();

        $held = $this->project([$this->technician('Paused Person')]);
        $this->book($held, -2, 2);
        $this->put(route('super-admin.projects.hold', $held->project_id));

        $response = $this->get(route('super-admin.projects'))->assertOk();

        $response->assertSee('data-status-filter="on_hold"', false);

        $tabs = collect($response->viewData('statusTabs'))->keyBy('key');
        $this->assertSame(1, $tabs['on_hold']['count']);

        // The row carries what the filter reads: the tab it belongs under,
        // decided once by Project::tabKey().
        $response->assertSee('data-tab="on_hold"', false);
        $response->assertSee('data-on-hold="1"', false);
    }

    // ==================================================================
    // BUG-023 - a paused project is not work happening today
    // ==================================================================

    public function test_active_today_leaves_out_work_that_is_on_hold(): void
    {
        $this->actingAsSuperAdmin();

        $running = $this->project([$this->technician('Running Person')]);
        $this->book($running, -1, 3);

        $held = $this->project([$this->technician('Paused Person')]);
        $this->book($held, -2, 2);
        $this->put(route('super-admin.projects.hold', $held->project_id));

        DashboardMetrics::flush();
        $counts = app(DashboardMetrics::class)->projectCounts();

        $this->assertSame(1, $counts['active_today'], 'Only the project with a crew on site.');
        $this->assertSame(1, $counts['on_hold'], 'And the paused one is counted as paused.');
    }

    /**
     * The figures have to agree with the tabs they link to: a held project is
     * counted once, under On Hold, not again under Pending.
     */
    public function test_held_work_is_not_also_counted_as_pending(): void
    {
        $this->actingAsSuperAdmin();

        $held = $this->project([$this->technician('Paused Person')]);
        $this->book($held, -2, 2);
        $this->put(route('super-admin.projects.hold', $held->project_id));

        DashboardMetrics::flush();
        $counts = app(DashboardMetrics::class)->projectCounts();

        $this->assertSame(0, $counts['pending']);
        $this->assertSame(0, $counts['ongoing']);
        $this->assertSame(1, $counts['on_hold']);
    }

    // ==================================================================
    // BUG-024 - the Contact form actually sends
    // ==================================================================

    public function test_a_website_enquiry_is_emailed_to_the_company(): void
    {
        Mail::fake();
        $this->withWorkingMailer('michael@coliconstruct.test');

        $this->get(route('public.contact'))
            ->assertOk()
            ->assertSee('action="'.route('public.contact.send').'"', false);

        $this->post(route('public.contact.send'), [
            'name' => 'Rosa Villanueva',
            'email' => 'rosa@example.test',
            'subject' => 'Aircon installation quote',
            'message' => 'We need two split-type units installed at our office in Cavite.',
        ])->assertRedirect()->assertSessionHas('success');

        Mail::assertQueued(ContactInquiryMail::class, function (ContactInquiryMail $mail): bool {
            return $mail->hasTo('michael@coliconstruct.test')
                // Replying answers the enquirer, not the company's own inbox.
                && $mail->hasReplyTo('rosa@example.test')
                && $mail->senderName === 'Rosa Villanueva'
                && str_contains($mail->body, 'two split-type units');
        });
    }

    public function test_the_enquiry_is_recorded_in_the_activity_trail(): void
    {
        Mail::fake();
        $this->withWorkingMailer();

        $this->post(route('public.contact.send'), [
            'name' => 'Rosa Villanueva',
            'email' => 'rosa@example.test',
            'subject' => 'Aircon installation quote',
            'message' => 'We need two split-type units installed at our office.',
        ])->assertSessionHas('success');

        $entry = ActivityLog::where('action', ActivityLog::CONTACT_INQUIRY_SENT)->first();

        $this->assertNotNull($entry, 'The only record an enquiry leaves.');
        $this->assertStringContainsString('rosa@example.test', (string) $entry->description);
        $this->assertSame('Rosa Villanueva', $entry->actor_name);
        $this->assertNull($entry->actor_id, 'Nobody was signed in.');
    }

    public function test_an_enquiry_is_checked_before_it_is_sent(): void
    {
        Mail::fake();
        $this->withWorkingMailer();

        $this->post(route('public.contact.send'), [
            'name' => '',
            'email' => 'not-an-address',
            'subject' => '',
            'message' => 'short',
        ])->assertSessionHasErrors(['name', 'email', 'subject', 'message']);

        Mail::assertNothingQueued();
    }

    /**
     * The honeypot. A bot that fills every field is answered as though it had
     * succeeded, so it has nothing to learn from and nothing to retry.
     */
    public function test_a_filled_honeypot_sends_nothing_and_says_nothing(): void
    {
        Mail::fake();
        $this->withWorkingMailer();

        $this->post(route('public.contact.send'), [
            'name' => 'Spam Bot',
            'email' => 'bot@example.test',
            'subject' => 'Cheap deals',
            'message' => 'Buy things from this website right now please.',
            'company_website' => 'http://spam.example',
        ])->assertSessionHasErrors('company_website');

        Mail::assertNothingQueued();
    }

    /**
     * And the other half, which has since changed.
     *
     * The form used to close itself when no mailer was configured, because an
     * enquiry that could not be emailed went nowhere at all. Enquiries are
     * stored now and read in Configuration > Inquiries, so the form stays open
     * and the message still arrives - the company's copy is what is lost, not
     * what somebody typed. See InquiryManagementTest for the rest of it.
     */
    public function test_the_form_still_takes_a_message_when_there_is_no_mailer(): void
    {
        Config::set('mail.default', 'array');

        $this->get(route('public.contact'))->assertOk();

        $this->post(route('public.contact.send'), [
            'name' => 'Rosa Villanueva',
            'email' => 'rosa@example.test',
            'subject' => 'Aircon installation quote',
            'message' => 'We need two split-type units installed at our office.',
        ])->assertSessionHas('success');

        $this->assertSame(1, Inquiry::count());
    }

    // ==================================================================
    // BUG-025 - a record with nothing in it says so
    // ==================================================================

    public function test_a_task_completed_before_the_system_kept_dates_says_so(): void
    {
        $this->actingAsSuperAdmin();

        $technician = $this->technician('Legacy Person');
        $project = $this->project([$technician]);
        $this->book($project, -4, 2);

        // A row of the kind the audit found: completed, with nothing recorded
        // about when or by whom.
        Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
            'task_title' => 'Legacy work',
            'task_description' => 'Finished under the old rules.',
            'status' => 'completed',
            'completed_at' => null,
            'completed_by' => null,
        ]);

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('Completed On')
            ->assertSee('Not recorded');
    }

    public function test_a_normal_completion_still_prints_its_date(): void
    {
        $this->actingAsSuperAdmin();

        $technician = $this->technician('Recent Person');
        $project = $this->project([$technician]);
        $this->book($project, -4, 2);

        Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
            'task_title' => 'Recent work',
            'task_description' => 'Finished properly.',
            'status' => 'completed',
            'completed_at' => CarbonImmutable::parse('2026-08-14 09:00:00'),
        ]);

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('Aug 14, 2026')
            ->assertDontSee('Not recorded');
    }
}
