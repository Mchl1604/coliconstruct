<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The bell and the Notification Center: what gets written, who can read it,
 * and what the reader can do with it.
 */
class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role, string $email): User
    {
        $sequence = User::count() + 1;

        return User::create([
            'user_code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => ucfirst($role).' Person',
            'first_name' => ucfirst(str_replace('_', ' ', $role)),
            'last_name' => 'Person',
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ] + $this->acceptedTerms());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function notification(User $user, array $overrides = []): Notification
    {
        return Notification::create(array_merge([
            'user_id' => $user->id,
            'title' => 'Something Happened',
            'message' => 'A thing you should look at.',
            'module' => Notification::MODULE_PROJECTS,
            'url' => '/super-admin/projects',
        ], $overrides));
    }

    private function service(): NotificationService
    {
        return app(NotificationService::class);
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    public function test_one_event_writes_one_row_per_recipient(): void
    {
        $first = $this->account('admin', 'first@example.test');
        $second = $this->account('admin', 'second@example.test');

        $this->service()->deliver(
            [$first, $second],
            'Project Completed',
            'A project finished.',
            Notification::MODULE_PROJECTS
        );

        $this->assertSame(1, Notification::where('user_id', $first->id)->count());
        $this->assertSame(1, Notification::where('user_id', $second->id)->count());
    }

    /**
     * A lead is also a member of their own team, so the same event can name
     * them twice.
     */
    public function test_a_recipient_named_twice_is_told_once(): void
    {
        $person = $this->account('admin', 'person@example.test');

        $this->service()->deliver(
            [$person, $person],
            'Project Completed',
            'A project finished.',
            Notification::MODULE_PROJECTS
        );

        $this->assertSame(1, Notification::where('user_id', $person->id)->count());
    }

    public function test_the_same_event_is_not_repeated(): void
    {
        $person = $this->account('admin', 'person@example.test');

        foreach (range(1, 3) as $ignored) {
            $this->service()->deliver(
                $person,
                'Project Completed',
                'A project finished.',
                Notification::MODULE_PROJECTS
            );
        }

        $this->assertSame(1, Notification::where('user_id', $person->id)->count());
    }

    /**
     * Different wording is a different event, however close together.
     */
    public function test_a_different_message_is_not_treated_as_a_repeat(): void
    {
        $person = $this->account('admin', 'person@example.test');

        $this->service()->deliver($person, 'Project Completed', 'First project.', Notification::MODULE_PROJECTS);
        $this->service()->deliver($person, 'Project Completed', 'Second project.', Notification::MODULE_PROJECTS);

        $this->assertSame(2, Notification::where('user_id', $person->id)->count());
    }

    /**
     * Nobody should be told about work that was rolled back.
     */
    public function test_nothing_is_written_when_the_transaction_rolls_back(): void
    {
        $person = $this->account('admin', 'person@example.test');

        try {
            DB::transaction(function () use ($person): void {
                $this->service()->deliver(
                    $person,
                    'Project Completed',
                    'A project finished.',
                    Notification::MODULE_PROJECTS
                );

                throw new \RuntimeException('The action failed after notifying.');
            });
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertSame(0, Notification::count());
    }

    /**
     * The action already succeeded; failing to tell somebody must not undo it.
     */
    public function test_a_write_failure_does_not_escape(): void
    {
        $person = $this->account('admin', 'person@example.test');

        $this->service()->deliver(
            $person,
            'Project Completed',
            str_repeat('x', 5000),
            Notification::MODULE_PROJECTS
        );

        $this->assertTrue(true);
    }

    public function test_a_deactivated_account_is_not_notified(): void
    {
        $person = $this->account('admin', 'person@example.test');
        $person->forceFill(['status' => User::STATUS_DEACTIVATED])->save();

        $this->service()->deliver($person, 'Project Completed', 'Done.', Notification::MODULE_PROJECTS);

        $this->assertSame(0, Notification::count());
    }

    /**
     * The same project is a different page in each portal, so the stored link
     * is resolved per reader.
     */
    public function test_the_link_is_resolved_for_each_reader(): void
    {
        $admin = $this->account('admin', 'admin@example.test');
        $technician = $this->account('technician', 'tech@example.test');

        $this->service()->deliver(
            [$admin, $technician],
            'Look At This',
            'Somewhere to go.',
            Notification::MODULE_PROJECTS,
            null,
            fn (User $user): string => $user->role === 'technician' ? '/technician/tasks' : '/super-admin/tasks'
        );

        $this->assertSame('/super-admin/tasks', Notification::where('user_id', $admin->id)->value('url'));
        $this->assertSame('/technician/tasks', Notification::where('user_id', $technician->id)->value('url'));
    }

    // ------------------------------------------------------------------
    // The bell
    // ------------------------------------------------------------------

    public function test_the_feed_carries_the_unread_count_and_the_newest_few(): void
    {
        $person = $this->account('admin', 'person@example.test');

        foreach (range(1, 14) as $index) {
            $this->notification($person, ['message' => 'Message '.$index]);
        }

        $response = $this->actingAs($person)->getJson(route('notifications.feed'));

        $response->assertOk();
        $this->assertSame(14, $response->json('unread_count'));
        // The dropdown shows the latest ten; the rest live in the centre.
        $this->assertCount(Notification::DROPDOWN_LIMIT, $response->json('notifications'));
    }

    public function test_the_feed_never_shows_somebody_elses_notifications(): void
    {
        $mine = $this->account('admin', 'mine@example.test');
        $theirs = $this->account('admin', 'theirs@example.test');

        $this->notification($theirs);

        $response = $this->actingAs($mine)->getJson(route('notifications.feed'));

        $this->assertSame(0, $response->json('unread_count'));
        $this->assertSame([], $response->json('notifications'));
    }

    public function test_read_notifications_do_not_count(): void
    {
        $person = $this->account('admin', 'person@example.test');

        $this->notification($person, ['message' => 'Unread one.']);
        $this->notification($person, ['message' => 'Read one.', 'is_read' => true, 'read_at' => now()]);

        $response = $this->actingAs($person)->getJson(route('notifications.feed'));

        $this->assertSame(1, $response->json('unread_count'));
    }

    // ------------------------------------------------------------------
    // Opening one
    // ------------------------------------------------------------------

    public function test_opening_marks_it_read_and_redirects_to_the_page(): void
    {
        $person = $this->account('admin', 'person@example.test');
        $notification = $this->notification($person, ['url' => '/super-admin/projects/7']);

        $response = $this->actingAs($person)
            ->get(route('notifications.open', $notification->notification_id));

        $response->assertRedirect('/super-admin/projects/7');
        $this->assertTrue($notification->fresh()->is_read);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_one_reader_cannot_open_anothers_notification(): void
    {
        $mine = $this->account('admin', 'mine@example.test');
        $theirs = $this->account('admin', 'theirs@example.test');

        $notification = $this->notification($theirs);

        $this->actingAs($mine)
            ->get(route('notifications.open', $notification->notification_id))
            ->assertNotFound();

        $this->assertFalse($notification->fresh()->is_read);
    }

    public function test_marking_all_as_read_clears_the_badge(): void
    {
        $person = $this->account('admin', 'person@example.test');

        foreach (range(1, 4) as $index) {
            $this->notification($person, ['message' => 'Message '.$index]);
        }

        $response = $this->actingAs($person)->putJson(route('notifications.read-all'));

        $response->assertOk();
        $this->assertSame(0, $response->json('unread_count'));
        $this->assertSame(0, Notification::forUser($person)->unread()->count());
    }

    /**
     * Marking mine read must leave everyone else's alone.
     */
    public function test_marking_all_as_read_stops_at_the_readers_own(): void
    {
        $mine = $this->account('admin', 'mine@example.test');
        $theirs = $this->account('admin', 'theirs@example.test');

        $this->notification($mine);
        $this->notification($theirs);

        $this->actingAs($mine)->putJson(route('notifications.read-all'));

        $this->assertSame(1, Notification::forUser($theirs)->unread()->count());
    }

    public function test_deleting_is_a_soft_delete(): void
    {
        $person = $this->account('admin', 'person@example.test');
        $notification = $this->notification($person);

        $this->actingAs($person)
            ->deleteJson(route('notifications.destroy', $notification->notification_id))
            ->assertOk();

        $this->assertSame(0, Notification::forUser($person)->count());
        $this->assertNotNull(Notification::withTrashed()->find($notification->notification_id)->deleted_at);
    }

    // ------------------------------------------------------------------
    // The Notification Center
    // ------------------------------------------------------------------

    public function test_the_centre_page_renders_for_every_role(): void
    {
        foreach (['super_admin', 'admin', 'lead_technician', 'technician', 'client'] as $index => $role) {
            $person = $this->account($role, $role.$index.'@example.test');

            $this->actingAs($person)
                ->get(route('notifications.index'))
                ->assertOk()
                ->assertSee('Notifications');
        }
    }

    public function test_the_list_searches_the_title_and_the_message(): void
    {
        $person = $this->account('admin', 'person@example.test');

        $this->notification($person, ['title' => 'Warehouse Wiring', 'message' => 'Nothing special.']);
        $this->notification($person, ['title' => 'Something Else', 'message' => 'Mentions warehouse here.']);
        $this->notification($person, ['title' => 'Unrelated', 'message' => 'Nothing to see.']);

        $response = $this->actingAs($person)
            ->getJson(route('notifications.list').'?'.http_build_query(['search' => 'warehouse']));

        $response->assertOk();
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_the_list_filters_by_module_and_status(): void
    {
        $person = $this->account('admin', 'person@example.test');

        $this->notification($person, ['module' => Notification::MODULE_PROJECTS, 'message' => 'A project.']);
        $this->notification($person, ['module' => Notification::MODULE_TASKS, 'message' => 'A task.']);
        $this->notification($person, [
            'module' => Notification::MODULE_TASKS,
            'message' => 'Another task.',
            'is_read' => true,
            'read_at' => now(),
        ]);

        $byModule = $this->actingAs($person)
            ->getJson(route('notifications.list').'?'.http_build_query(['module' => Notification::MODULE_TASKS]));

        $this->assertSame(2, $byModule->json('meta.total'));

        $unread = $this->actingAs($person)
            ->getJson(route('notifications.list').'?'.http_build_query([
                'module' => Notification::MODULE_TASKS,
                'status' => 'unread',
            ]));

        $this->assertSame(1, $unread->json('meta.total'));
    }

    public function test_the_list_pages_on_the_server_newest_first(): void
    {
        $person = $this->account('admin', 'person@example.test');

        foreach (range(1, 20) as $index) {
            $this->notification($person, ['message' => 'Message '.$index])
                ->forceFill(['created_at' => now()->addMinutes($index)])
                ->save();
        }

        $first = $this->actingAs($person)->getJson(route('notifications.list'));

        $this->assertSame(20, $first->json('meta.total'));
        $this->assertSame(2, $first->json('meta.last_page'));
        $this->assertCount(15, $first->json('rows'));
        $this->assertSame('Message 20', $first->json('rows.0.message'));

        $second = $this->actingAs($person)->getJson(route('notifications.list').'?page=2');

        $this->assertCount(5, $second->json('rows'));
    }

    public function test_the_list_never_leaks_another_readers_rows(): void
    {
        $mine = $this->account('admin', 'mine@example.test');
        $theirs = $this->account('admin', 'theirs@example.test');

        $this->notification($theirs, ['message' => 'Not yours.']);

        $response = $this->actingAs($mine)->getJson(route('notifications.list'));

        $this->assertSame(0, $response->json('meta.total'));
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('auth.login'));
    }

    /**
     * The bell lives in the shared layouts, so it has to appear in both
     * shells - the administrative one and the technician/client one.
     */
    public function test_the_bell_appears_in_both_portal_shells(): void
    {
        $admin = $this->account('super_admin', 'owner@example.test');
        $technician = $this->account('technician', 'tech@example.test');

        $this->actingAs($admin)
            ->get(route('super-admin.configuration.index'))
            ->assertOk()
            ->assertSee('data-notification-bell', escape: false)
            ->assertSee(route('notifications.index'), escape: false);

        $this->actingAs($technician)
            ->get(route('technician.projects'))
            ->assertOk()
            ->assertSee('data-notification-bell', escape: false)
            ->assertSee(route('notifications.index'), escape: false);
    }

    public function test_every_module_declares_an_icon(): void
    {
        foreach (Notification::MODULES as $module) {
            $this->assertArrayHasKey(
                $module,
                Notification::MODULE_ICONS,
                $module.' has no icon, so the dropdown would fall back to a bell.'
            );
        }
    }
}
