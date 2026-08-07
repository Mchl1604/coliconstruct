<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Skill;
use App\Models\SpecialtyRequest;
use App\Models\Technician;
use App\Models\User;
use App\Services\ProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The specialty approval loop, as a notification story: a technician asks, the
 * administrators are told, one of them decides, and the technician is told the
 * answer.
 *
 * The case that matters is the second time round. A technician who asks again
 * the same day, and an administrator who decides again the same day, must each
 * still be told - the duplicate guard exists to stop one event being announced
 * twice, not to stop two separate events being announced once each.
 */
class SpecialtyNotificationTest extends TestCase
{
    use RefreshDatabase;

    private static int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        self::$sequence = 0;
    }

    private function account(string $role, string $email): User
    {
        $sequence = ++self::$sequence;

        return User::create([
            'user_code' => 'EMP-9'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
            'name' => ucfirst(str_replace('_', ' ', $role)).' '.$sequence,
            'first_name' => ucfirst(str_replace('_', ' ', $role)),
            'last_name' => 'Person'.$sequence,
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'password' => 'correct-password',
        ]);
    }

    private function profile(): ProfileService
    {
        return app(ProfileService::class);
    }

    /**
     * @return array{0: User, 1: Technician}
     */
    private function technicianWith(string $role = 'technician'): array
    {
        $account = $this->account($role, 'tech'.self::$sequence.'@example.test');

        return [$account, Technician::create(['account_id' => $account->id, 'role' => $role])];
    }

    private function notificationsFor(User $user, string $title): int
    {
        return Notification::query()->where('user_id', $user->id)->where('title', $title)->count();
    }

    // ------------------------------------------------------------------
    // The request
    // ------------------------------------------------------------------

    public function test_every_administrator_is_told_a_technician_has_asked(): void
    {
        $superAdmin = $this->account('super_admin', 'super@example.test');
        $admin = $this->account('admin', 'admin@example.test');
        [$techAccount, $technician] = $this->technicianWith();

        $ducting = Skill::create(['skill_name' => 'Ducting']);

        $this->actingAs($techAccount);
        $this->profile()->requestSpecialties($technician, [$ducting->skill_id]);

        $this->assertSame(1, $this->notificationsFor($superAdmin, 'Specialty Request'));
        $this->assertSame(1, $this->notificationsFor($admin, 'Specialty Request'));

        // And it points at the page where the decision is taken.
        $notification = Notification::where('user_id', $admin->id)->firstOrFail();
        $this->assertSame(route('super-admin.technicians.index', [], false), $notification->url);
    }

    /**
     * The reported failure: a technician asks, is decided on, and asks again
     * the same day. The second request must reach the administrators too.
     */
    public function test_a_second_request_the_same_day_still_reaches_the_administrators(): void
    {
        $admin = $this->account('super_admin', 'super@example.test');
        [$techAccount, $technician] = $this->technicianWith();

        $ducting = Skill::create(['skill_name' => 'Ducting']);
        $wiring = Skill::create(['skill_name' => 'Wiring']);

        $this->actingAs($techAccount);
        $first = $this->profile()->requestSpecialties($technician, [$ducting->skill_id]);

        $this->actingAs($admin);
        $this->profile()->approveSpecialtyRequest($first, $admin);

        // Same technician, same day, a different set.
        $this->actingAs($techAccount);
        $this->profile()->requestSpecialties($technician, [$ducting->skill_id, $wiring->skill_id]);

        $this->assertSame(2, $this->notificationsFor($admin, 'Specialty Request'));
    }

    // ------------------------------------------------------------------
    // The decision
    // ------------------------------------------------------------------

    public function test_the_technician_is_told_their_request_was_approved(): void
    {
        $admin = $this->account('super_admin', 'super@example.test');
        [$techAccount, $technician] = $this->technicianWith();

        $ducting = Skill::create(['skill_name' => 'Ducting']);

        $this->actingAs($techAccount);
        $request = $this->profile()->requestSpecialties($technician, [$ducting->skill_id]);

        $this->actingAs($admin);
        $this->profile()->approveSpecialtyRequest($request, $admin);

        $this->assertSame(1, $this->notificationsFor($techAccount, 'Specialty Update Approved'));
    }

    public function test_the_technician_is_told_their_request_was_rejected(): void
    {
        $admin = $this->account('super_admin', 'super@example.test');
        [$techAccount, $technician] = $this->technicianWith();

        $ducting = Skill::create(['skill_name' => 'Ducting']);

        $this->actingAs($techAccount);
        $request = $this->profile()->requestSpecialties($technician, [$ducting->skill_id]);

        $this->actingAs($admin);
        $this->profile()->rejectSpecialtyRequest($request, $admin);

        $this->assertSame(1, $this->notificationsFor($techAccount, 'Specialty Update Rejected'));
    }

    /**
     * The other half of the reported failure: two decisions on the same
     * technician the same day are two pieces of news, not one repeated.
     */
    public function test_a_second_decision_the_same_day_still_reaches_the_technician(): void
    {
        $admin = $this->account('super_admin', 'super@example.test');
        [$techAccount, $technician] = $this->technicianWith();

        $ducting = Skill::create(['skill_name' => 'Ducting']);
        $wiring = Skill::create(['skill_name' => 'Wiring']);

        $this->actingAs($techAccount);
        $first = $this->profile()->requestSpecialties($technician, [$ducting->skill_id]);

        $this->actingAs($admin);
        $this->profile()->approveSpecialtyRequest($first, $admin);

        $this->actingAs($techAccount);
        $second = $this->profile()->requestSpecialties($technician, [$ducting->skill_id, $wiring->skill_id]);

        $this->actingAs($admin);
        $this->profile()->approveSpecialtyRequest($second, $admin);

        $this->assertSame(2, $this->notificationsFor($techAccount, 'Specialty Update Approved'));
    }

    /**
     * A lead technician holds specialties on exactly the same terms.
     */
    public function test_a_lead_technician_is_told_the_same_way(): void
    {
        $admin = $this->account('super_admin', 'super@example.test');
        [$leadAccount, $lead] = $this->technicianWith('lead_technician');

        $ducting = Skill::create(['skill_name' => 'Ducting']);

        $this->actingAs($leadAccount);
        $request = $this->profile()->requestSpecialties($lead, [$ducting->skill_id]);

        $this->actingAs($admin);
        $this->profile()->rejectSpecialtyRequest($request, $admin);

        $this->assertSame(1, $this->notificationsFor($leadAccount, 'Specialty Update Rejected'));
    }

    /**
     * Each notification is about one request, so opening it can only ever mean
     * one thing.
     */
    public function test_each_decision_references_the_request_it_is_about(): void
    {
        $admin = $this->account('super_admin', 'super@example.test');
        [$techAccount, $technician] = $this->technicianWith();

        $ducting = Skill::create(['skill_name' => 'Ducting']);

        $this->actingAs($techAccount);
        $request = $this->profile()->requestSpecialties($technician, [$ducting->skill_id]);

        $this->actingAs($admin);
        $this->profile()->approveSpecialtyRequest($request, $admin);

        $notification = Notification::query()
            ->where('user_id', $techAccount->id)
            ->where('title', 'Specialty Update Approved')
            ->firstOrFail();

        $this->assertSame(class_basename(SpecialtyRequest::class), $notification->reference_type);
        $this->assertSame($request->specialty_request_id, (int) $notification->reference_id);
        $this->assertSame(route('profile.edit', [], false), $notification->url);
    }

    /**
     * Opening one from the Notification Center takes the reader to the page it
     * is about, and marks it read on the way.
     */
    public function test_opening_a_specialty_notification_lands_on_the_right_page(): void
    {
        $admin = $this->account('super_admin', 'super@example.test');
        [$techAccount, $technician] = $this->technicianWith();

        $ducting = Skill::create(['skill_name' => 'Ducting']);

        $this->actingAs($techAccount);
        $request = $this->profile()->requestSpecialties($technician, [$ducting->skill_id]);

        // The administrator's copy opens the Technicians page, where the
        // decision is taken.
        $forAdmin = Notification::where('user_id', $admin->id)->firstOrFail();

        $this->actingAs($admin)
            ->get(route('notifications.open', $forAdmin->notification_id))
            ->assertRedirect(route('super-admin.technicians.index', [], false));

        $this->assertTrue($forAdmin->refresh()->is_read);

        // The technician's copy opens their own profile, where the specialties
        // they hold are listed.
        $this->actingAs($admin);
        $this->profile()->approveSpecialtyRequest($request, $admin);

        $forTechnician = Notification::where('user_id', $techAccount->id)->firstOrFail();

        $this->actingAs($techAccount)
            ->get(route('notifications.open', $forTechnician->notification_id))
            ->assertRedirect(route('profile.edit', [], false));
    }

    /**
     * The Notification Center's rows carry the link, so clicking one goes
     * somewhere - the page used to offer only a small icon.
     */
    public function test_the_notification_centre_rows_are_openable(): void
    {
        $admin = $this->account('super_admin', 'super@example.test');
        [$techAccount, $technician] = $this->technicianWith();

        $ducting = Skill::create(['skill_name' => 'Ducting']);

        $this->actingAs($techAccount);
        $this->profile()->requestSpecialties($technician, [$ducting->skill_id]);

        $notification = Notification::where('user_id', $admin->id)->firstOrFail();

        $response = $this->actingAs($admin)->getJson(route('notifications.list'));

        $response->assertOk();
        $response->assertJsonPath(
            'rows.0.open_url',
            route('notifications.open', $notification->notification_id)
        );
    }
}
