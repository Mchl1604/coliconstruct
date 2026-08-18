<?php

namespace Tests\Feature;

use App\Mail\ContactInquiryMail;
use App\Mail\InquiryReplyMail;
use App\Models\ActivityLog;
use App\Models\Inquiry;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Inquiries: the public Contact form, and Configuration > Inquiries where the
 * messages it produces are read, answered and filed away.
 */
class InquiryManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A mailer that reaches people, which is what replying requires.
     */
    private function withWorkingMailer(string $inbox = 'inbox@coliconstruct.test'): void
    {
        Config::set('mail.default', 'smtp');
        Config::set('mail.inquiries_to', $inbox);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rosa Villanueva',
            'email' => 'rosa@example.test',
            'subject' => 'Aircon installation quote',
            'message' => 'We need two split-type units installed at our office in Cavite.',
        ], $overrides);
    }

    private function submit(array $overrides = []): Inquiry
    {
        $this->post(route('public.contact.send'), $this->payload($overrides))
            ->assertSessionHas('success');

        return Inquiry::query()->latest('inquiry_id')->firstOrFail();
    }

    private function makeUser(string $role, string $email): User
    {
        return User::create([
            'user_code' => strtoupper(substr($role, 0, 3)).'-'.str_pad((string) (User::count() + 1), 4, '0', STR_PAD_LEFT),
            'name' => ucfirst($role).' Person',
            'first_name' => ucfirst($role),
            'last_name' => 'Person',
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'test-password',
        ]);
    }

    private function admin(): User
    {
        return $this->makeUser('admin', 'admin@coliconstruct.test');
    }

    // ==================================================================
    // The public form
    // ==================================================================

    public function test_a_visitor_submits_an_inquiry_without_an_account(): void
    {
        Mail::fake();
        $this->withWorkingMailer();

        $this->get(route('public.contact'))
            ->assertOk()
            ->assertSee('action="'.route('public.contact.send').'"', false);

        $inquiry = $this->submit();

        $this->assertSame('Rosa Villanueva', $inquiry->name);
        $this->assertSame('rosa@example.test', $inquiry->email);
        $this->assertSame('Aircon installation quote', $inquiry->subject);
        $this->assertStringContainsString('split-type units', $inquiry->message);
        $this->assertSame(Inquiry::STATUS_NEW, $inquiry->status, 'A new inquiry arrives as New.');
        $this->assertFalse($inquiry->is_archived);
        $this->assertNull($inquiry->replied_at);
        $this->assertNull($inquiry->replied_by);

        // Nothing was created on the enquirer's behalf.
        $this->assertSame(0, User::where('role', User::ROLE_CLIENT)->count());
        $this->assertSame(0, Project::count());

        // The company still gets its copy, on top of the record.
        Mail::assertQueued(ContactInquiryMail::class);
    }

    public function test_the_inquiry_is_recorded_in_the_activity_trail(): void
    {
        Mail::fake();
        $this->withWorkingMailer();

        $inquiry = $this->submit();

        $entry = ActivityLog::where('action', ActivityLog::CONTACT_INQUIRY_SENT)->first();

        $this->assertNotNull($entry);
        $this->assertSame(ActivityLog::MODULE_CONFIGURATION, $entry->module);
        $this->assertSame('Rosa Villanueva', $entry->actor_name);
        $this->assertNull($entry->actor_id, 'Nobody was signed in.');
        $this->assertStringContainsString($inquiry->code(), (string) $entry->description);
    }

    public function test_administrators_are_told_a_new_inquiry_arrived(): void
    {
        Mail::fake();
        $this->withWorkingMailer();

        $admin = $this->admin();
        $technician = $this->makeUser('technician', 'tech@coliconstruct.test');

        $this->submit();

        $this->assertSame(
            1,
            Notification::where('user_id', $admin->id)
                ->where('module', Notification::MODULE_INQUIRIES)
                ->count()
        );

        $this->assertSame(
            0,
            Notification::where('user_id', $technician->id)->count(),
            'A technician has no page to read an inquiry on.'
        );
    }

    public function test_an_inquiry_is_checked_before_it_is_stored(): void
    {
        Mail::fake();
        $this->withWorkingMailer();

        $this->post(route('public.contact.send'), [
            'name' => '',
            'email' => 'not-an-address',
            'subject' => '',
            'message' => 'short',
        ])->assertSessionHasErrors(['name', 'email', 'subject', 'message']);

        $this->assertSame(0, Inquiry::count());
    }

    public function test_excessively_long_input_is_refused(): void
    {
        Mail::fake();
        $this->withWorkingMailer();

        $this->post(route('public.contact.send'), $this->payload([
            'name' => str_repeat('a', Inquiry::MAX_NAME + 1),
            'subject' => str_repeat('b', Inquiry::MAX_SUBJECT + 1),
            'message' => str_repeat('c', Inquiry::MAX_MESSAGE + 1),
        ]))->assertSessionHasErrors(['name', 'subject', 'message']);

        $this->assertSame(0, Inquiry::count());
    }

    public function test_a_filled_honeypot_stores_nothing(): void
    {
        Mail::fake();
        $this->withWorkingMailer();

        $this->post(route('public.contact.send'), $this->payload([
            'company_website' => 'http://spam.example',
        ]))->assertSessionHasErrors('company_website');

        $this->assertSame(0, Inquiry::count());
    }

    public function test_repeated_submissions_are_throttled(): void
    {
        Mail::fake();
        $this->withWorkingMailer();

        // The route allows five in ten minutes; the sixth is refused.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('public.contact.send'), $this->payload([
                'subject' => 'Quote request '.$attempt,
            ]))->assertSessionHas('success');
        }

        $this->post(route('public.contact.send'), $this->payload([
            'subject' => 'One too many',
        ]))->assertStatus(429);

        $this->assertSame(5, Inquiry::count());
    }

    /**
     * The form used to close itself when no mailer was configured, because an
     * enquiry that could not be emailed went nowhere. It is stored now, so it
     * stays open and the message still arrives in Configuration.
     */
    public function test_the_form_stays_open_when_email_is_not_configured(): void
    {
        Config::set('mail.default', 'array');

        $this->get(route('public.contact'))
            ->assertOk()
            ->assertDontSee('Online inquiries are not being accepted yet');

        $inquiry = $this->submit();

        $this->assertSame(Inquiry::STATUS_NEW, $inquiry->status);
    }

    // ==================================================================
    // Who may reach the tab
    // ==================================================================

    public function test_the_configuration_page_shows_the_inquiries_tab(): void
    {
        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.configuration.index'))
            ->assertOk()
            ->assertSee('id="inquiriesTab"', false)
            ->assertSee('Inquiries');
    }

    public function test_an_admin_may_work_the_inquiry_list(): void
    {
        $this->actingAs($this->admin());

        $this->getJson(route('super-admin.configuration.inquiries.index'))->assertOk();
    }

    public function test_nobody_outside_the_two_administrator_roles_reaches_inquiries(): void
    {
        $inquiry = $this->submit();

        // A guest is sent to sign in, as they are everywhere behind `auth`.
        $this->getJson(route('super-admin.configuration.inquiries.index'))
            ->assertRedirect(route('auth.login'));

        foreach (['client', 'technician', 'lead_technician'] as $role) {
            $this->actingAs($this->makeUser($role, $role.'@coliconstruct.test'));

            $this->getJson(route('super-admin.configuration.inquiries.index'))->assertForbidden();
            $this->getJson(route('super-admin.configuration.inquiries.show', $inquiry->inquiry_id))
                ->assertForbidden();
        }
    }

    // ==================================================================
    // The table
    // ==================================================================

    public function test_the_table_searches_filters_and_sorts(): void
    {
        $first = Inquiry::create($this->payload([
            'name' => 'Rosa Villanueva',
            'subject' => 'Aircon quote',
        ]) + ['status' => Inquiry::STATUS_NEW]);

        $first->forceFill(['created_at' => now()->subDays(3)])->save();

        $second = Inquiry::create($this->payload([
            'name' => 'Mateo Cruz',
            'email' => 'mateo@example.test',
            'subject' => 'Roof repair',
        ]) + ['status' => Inquiry::STATUS_CLOSED]);

        $this->actingAsSuperAdmin();

        $rows = $this->getJson(route('super-admin.configuration.inquiries.index'))
            ->assertOk()
            ->json('rows');

        // Newest first by default.
        $this->assertSame($second->inquiry_id, $rows[0]['id']);
        $this->assertSame($first->code(), $rows[1]['code']);

        $ascending = $this->getJson(route('super-admin.configuration.inquiries.index', ['direction' => 'asc']))
            ->json('rows');

        $this->assertSame($first->inquiry_id, $ascending[0]['id']);

        // Search matches the name, and the code as it is printed.
        $this->assertCount(
            1,
            $this->getJson(route('super-admin.configuration.inquiries.index', ['search' => 'Mateo']))->json('rows')
        );

        $found = $this->getJson(route('super-admin.configuration.inquiries.index', ['search' => $first->code()]))
            ->json('rows');

        $this->assertCount(1, $found);
        $this->assertSame($first->inquiry_id, $found[0]['id']);

        // And the status filter.
        $closed = $this->getJson(route('super-admin.configuration.inquiries.index', ['status' => Inquiry::STATUS_CLOSED]))
            ->json('rows');

        $this->assertCount(1, $closed);
        $this->assertSame($second->inquiry_id, $closed[0]['id']);
    }

    public function test_the_details_endpoint_returns_the_whole_record(): void
    {
        Mail::fake();
        $this->withWorkingMailer();

        $inquiry = $this->submit();

        $this->actingAsSuperAdmin();

        $this->getJson(route('super-admin.configuration.inquiries.show', $inquiry->inquiry_id))
            ->assertOk()
            ->assertJsonPath('inquiry.code', $inquiry->code())
            ->assertJsonPath('inquiry.name', 'Rosa Villanueva')
            ->assertJsonPath('inquiry.email', 'rosa@example.test')
            ->assertJsonPath('inquiry.status', Inquiry::STATUS_NEW)
            ->assertJsonPath('inquiry.has_reply', false)
            ->assertJsonPath('inquiry.message', $inquiry->message);
    }

    // ==================================================================
    // Status
    // ==================================================================

    public function test_an_administrator_changes_the_status_in_any_order(): void
    {
        Mail::fake();
        $this->withWorkingMailer();

        $inquiry = $this->submit();

        $this->actingAs($this->admin());

        // New straight to Closed: no sequence is forced.
        $this->putJson(
            route('super-admin.configuration.inquiries.status', $inquiry->inquiry_id),
            ['status' => Inquiry::STATUS_CLOSED]
        )->assertOk()->assertJsonPath('inquiry.status', Inquiry::STATUS_CLOSED);

        $this->assertSame(Inquiry::STATUS_CLOSED, $inquiry->fresh()->status);

        $entry = ActivityLog::where('action', ActivityLog::INQUIRY_STATUS_CHANGED)->first();

        $this->assertNotNull($entry);
        $this->assertStringContainsString('New to Closed', (string) $entry->description);
        $this->assertSame('Inquiry', $entry->record_type);
        $this->assertSame($inquiry->inquiry_id, (int) $entry->record_id);
    }

    public function test_an_unknown_status_is_refused(): void
    {
        Mail::fake();
        $this->withWorkingMailer();

        $inquiry = $this->submit();

        $this->actingAsSuperAdmin();

        $this->putJson(
            route('super-admin.configuration.inquiries.status', $inquiry->inquiry_id),
            ['status' => 'escalated']
        )->assertStatus(422);

        $this->assertSame(Inquiry::STATUS_NEW, $inquiry->fresh()->status);
    }

    // ==================================================================
    // Replying
    // ==================================================================

    public function test_a_reply_is_emailed_recorded_and_marks_the_inquiry_responded(): void
    {
        Mail::fake();
        $this->withWorkingMailer();

        $inquiry = $this->submit();
        $admin = $this->admin();

        $this->actingAs($admin);

        $this->postJson(
            route('super-admin.configuration.inquiries.reply', $inquiry->inquiry_id),
            ['message' => 'Thank you for writing in. We can install both units next Tuesday.']
        )->assertOk()->assertJsonPath('inquiry.status', Inquiry::STATUS_RESPONDED);

        $replied = $inquiry->fresh();

        $this->assertSame(Inquiry::STATUS_RESPONDED, $replied->status);
        $this->assertSame($admin->id, $replied->replied_by);
        $this->assertNotNull($replied->replied_at);
        $this->assertStringContainsString('next Tuesday', (string) $replied->reply_message);
        // The original message is never touched.
        $this->assertStringContainsString('split-type units', $replied->message);

        Mail::assertQueued(InquiryReplyMail::class, function (InquiryReplyMail $mail) use ($inquiry): bool {
            // The recipient comes from the inquiry, never from the form.
            return $mail->hasTo($inquiry->email)
                && str_contains($mail->replyBody, 'next Tuesday')
                && str_contains($mail->originalMessage, 'split-type units');
        });

        $entry = ActivityLog::where('action', ActivityLog::INQUIRY_REPLY_SENT)->first();

        $this->assertNotNull($entry);
        $this->assertSame($admin->id, $entry->actor_id);
    }

    /**
     * Whoever writes it, a reply leaves from the company's own address - never
     * from the administrator who happened to be signed in.
     */
    public function test_every_reply_is_sent_from_the_company_address(): void
    {
        Mail::fake();
        $this->withWorkingMailer();
        Config::set('mail.from.address', 'michaelcapanayan@gmail.com');
        Config::set('mail.from.name', 'Coliconstruct');
        Config::set('mail.inquiries_to', 'michaelcapanayan@gmail.com');

        $inquiry = $this->submit();

        $this->actingAs($this->admin());

        $this->postJson(
            route('super-admin.configuration.inquiries.reply', $inquiry->inquiry_id),
            ['message' => 'We can install both units next Tuesday.']
        )->assertOk();

        Mail::assertQueued(InquiryReplyMail::class, function (InquiryReplyMail $mail): bool {
            return $mail->hasFrom('michaelcapanayan@gmail.com')
                // Not the admin's own address, whoever they are.
                && ! $mail->hasFrom('admin@coliconstruct.test')
                && $mail->hasReplyTo('michaelcapanayan@gmail.com');
        });
    }

    /**
     * The Send button reads the reply box, and only the reply box.
     *
     * The dialog also prints a reply that has already been sent, and while the
     * two shared one `data-inquiry-reply-message` hook the script found the
     * printed block first, asked a <div> for its value, and posted nothing -
     * so a written reply came back as "Write a reply before sending it."
     */
    public function test_only_one_element_carries_the_reply_box_hook(): void
    {
        $this->actingAsSuperAdmin();

        $html = $this->get(route('super-admin.configuration.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-inquiry-reply-message'));
        $this->assertSame(1, substr_count($html, 'data-inquiry-reply-text'));
    }

    public function test_a_reply_that_cannot_be_sent_leaves_the_inquiry_alone(): void
    {
        Mail::fake();
        $this->withWorkingMailer();

        $inquiry = $this->submit();

        $this->actingAsSuperAdmin();

        // No deliverable mailer: the message would go nowhere.
        Config::set('mail.default', 'array');

        $this->postJson(
            route('super-admin.configuration.inquiries.reply', $inquiry->inquiry_id),
            ['message' => 'We can install both units next Tuesday.']
        )->assertStatus(422)->assertJsonStructure(['error']);

        $unchanged = $inquiry->fresh();

        $this->assertSame(Inquiry::STATUS_NEW, $unchanged->status, 'Nothing was sent, so nothing was answered.');
        $this->assertNull($unchanged->reply_message);
        $this->assertNull($unchanged->replied_at);
        $this->assertNull($unchanged->replied_by);

        Mail::assertNotQueued(InquiryReplyMail::class);
        $this->assertSame(0, ActivityLog::where('action', ActivityLog::INQUIRY_REPLY_SENT)->count());
    }

    public function test_an_empty_reply_is_refused(): void
    {
        Mail::fake();
        $this->withWorkingMailer();

        $inquiry = $this->submit();

        $this->actingAsSuperAdmin();

        $this->postJson(
            route('super-admin.configuration.inquiries.reply', $inquiry->inquiry_id),
            ['message' => '']
        )->assertStatus(422);

        $this->assertSame(Inquiry::STATUS_NEW, $inquiry->fresh()->status);
    }

    // ==================================================================
    // Archiving
    // ==================================================================

    public function test_an_admin_archives_an_inquiry_without_deleting_it(): void
    {
        Mail::fake();
        $this->withWorkingMailer();

        $inquiry = $this->submit();
        $admin = $this->admin();

        $this->actingAs($admin);

        $this->deleteJson(route('super-admin.configuration.inquiries.archive', $inquiry->inquiry_id))
            ->assertOk();

        $archived = $inquiry->fresh();

        $this->assertTrue($archived->is_archived);
        $this->assertNotNull($archived->archived_at);
        $this->assertSame($admin->id, $archived->archived_by);

        // Off the working list, still in the database.
        $this->assertSame([], $this->getJson(route('super-admin.configuration.inquiries.index'))->json('rows'));
        $this->assertSame(1, Inquiry::count());

        $this->assertSame(1, ActivityLog::where('action', ActivityLog::INQUIRY_ARCHIVED)->count());
    }

    public function test_only_a_super_admin_reads_and_restores_the_archive(): void
    {
        Mail::fake();
        $this->withWorkingMailer();

        $inquiry = $this->submit();
        $inquiry->forceFill(['is_archived' => true, 'archived_at' => now()])->save();

        // An Admin archives, but the archive itself is not theirs.
        $this->actingAs($this->admin());

        $this->getJson(route('super-admin.configuration.inquiries.archived'))->assertForbidden();
        $this->putJson(route('super-admin.configuration.inquiries.restore', $inquiry->inquiry_id))
            ->assertForbidden();

        $this->actingAsSuperAdmin();

        $rows = $this->getJson(route('super-admin.configuration.inquiries.archived'))
            ->assertOk()
            ->json('rows');

        $this->assertCount(1, $rows);
        $this->assertSame($inquiry->code(), $rows[0]['code']);

        $this->putJson(route('super-admin.configuration.inquiries.restore', $inquiry->inquiry_id))
            ->assertOk();

        $this->assertFalse($inquiry->fresh()->is_archived);
        $this->assertSame(1, ActivityLog::where('action', ActivityLog::INQUIRY_RESTORED)->count());
        $this->assertCount(1, $this->getJson(route('super-admin.configuration.inquiries.index'))->json('rows'));
    }

    public function test_an_archived_inquiry_cannot_be_replied_to(): void
    {
        Mail::fake();
        $this->withWorkingMailer();

        $inquiry = $this->submit();
        $inquiry->forceFill(['is_archived' => true, 'archived_at' => now()])->save();

        $this->actingAsSuperAdmin();

        $this->postJson(
            route('super-admin.configuration.inquiries.reply', $inquiry->inquiry_id),
            ['message' => 'We can install both units next Tuesday.']
        )->assertStatus(422);

        Mail::assertNotQueued(InquiryReplyMail::class);
    }
}
