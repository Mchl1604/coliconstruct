<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What happens to a project when one of its crew is switched off.
 *
 * Deactivating an account deliberately does NOT take the person off the team
 * or hand their booked dates back - those are real commitments, and releasing
 * them silently would leave a project short-crewed with nobody told. Two
 * things follow from that, and this covers both.
 *
 * The project has to SAY it is short-handed, to everybody who can do something
 * about it. The administrative portal already did; the lead running the job is
 * the other such person, and they were being told nothing at all.
 *
 * And nobody may be handed work they cannot receive. An account that cannot
 * sign in cannot open the project, cannot close a task, and cannot read the
 * notification saying it has one - so the Assign To picker must not offer it,
 * and the form behind the picker must refuse it whatever a browser submits.
 */
class InactiveTechnicianOnProjectTest extends TestCase
{
    use RefreshDatabase;

    private User $leadAccount;

    private Technician $lead;

    private Technician $mate;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->leadAccount = $this->account('Rita Lead', 'lead_technician');
        $this->lead = Technician::create([
            'account_id' => $this->leadAccount->id,
            'role' => 'lead_technician',
        ]);

        $mateAccount = $this->account('Ana Mendoza', 'technician');
        $this->mate = Technician::create([
            'account_id' => $mateAccount->id,
            'role' => 'technician',
        ]);

        $this->project = Project::create([
            'name' => 'Aircon Retrofit',
            'reference_no' => 'REF-CREW-1',
            'status' => 'ongoing',
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);

        $this->assign($this->lead);
        $this->assign($this->mate);
        $this->schedule(1, 6);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function account(string $name, string $role): User
    {
        // Numbered from 100 so these never collide with the EMP-0001 that
        // actingAsSuperAdmin() claims when a test signs in as an
        // administrator part-way through.
        $sequence = User::count() + 100;

        return User::create([
            'user_code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => $name,
            'first_name' => explode(' ', $name)[0],
            'last_name' => explode(' ', $name)[1] ?? 'Person',
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);
    }

    private function assign(Technician $technician): ProjectTechnician
    {
        return ProjectTechnician::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $technician->technician_id,
        ]);
    }

    private function schedule(int $from, int $to): Schedule
    {
        $schedule = Schedule::create([
            'project_id' => $this->project->project_id,
            'start_datetime' => $this->day($from).' 00:00:00',
            'end_datetime' => $this->day($to).' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        foreach ($this->project->projectTechnicians()->get() as $assignment) {
            ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $assignment->project_technician_id,
            ]);
        }

        return $schedule;
    }

    private function day(int $offset): string
    {
        return CarbonImmutable::today()->addDays($offset)->toDateString();
    }

    private function deactivate(Technician $technician): void
    {
        $technician->account->forceFill(['status' => User::STATUS_DEACTIVATED])->save();
    }

    private function task(Technician $technician, string $title = 'Pull the wiring'): Task
    {
        return Task::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $technician->technician_id,
            'task_title' => $title,
            'task_description' => 'Do the thing',
            'start_date' => $this->day(2),
            'due_date' => $this->day(3),
            'status' => 'pending',
        ]);
    }

    /**
     * Every Assign To radio on the page offering this technician, as raw
     * tags.
     *
     * Read with a pattern rather than by looking for a literal string:
     * Blade's @checked and @disabled sit on their own line in the templates,
     * so the rendered attributes are separated by whatever indentation the
     * markup happens to carry.
     *
     * @return array<int, string>
     */
    private function assignRadios(string $html, int $technicianId): array
    {
        preg_match_all('/<input\b[^>]*name="technician_id"[^>]*>/s', $html, $matches);

        return array_values(array_filter(
            $matches[0],
            fn (string $tag): bool => str_contains($tag, 'value="'.$technicianId.'"')
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function taskPayload(array $overrides = []): array
    {
        return array_merge([
            'task_title' => 'Install condenser',
            'task_description' => 'Mount and wire the outdoor unit.',
            'technician_id' => $this->mate->technician_id,
            'start_date' => $this->day(2),
            'due_date' => $this->day(4),
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // The lead is told
    // ------------------------------------------------------------------

    public function test_the_lead_sees_the_flag_on_their_projects_table(): void
    {
        $this->deactivate($this->mate);
        $this->actingAs($this->leadAccount);

        $page = $this->get(route('technician.projects'));

        $page->assertOk();
        $page->assertSee('Inactive technician');
        $page->assertSee('project-row-needs-recrew', false);
        $page->assertSee('Ana Mendoza can no longer sign in', false);
    }

    public function test_the_lead_sees_the_warning_on_project_details(): void
    {
        $this->deactivate($this->mate);
        $this->actingAs($this->leadAccount);

        $page = $this->get(route('technician.projects.show', $this->project->project_id));

        $page->assertOk();
        $page->assertSee('This team needs attention.');
        $page->assertSee('Ana Mendoza');
        $page->assertSee('Account inactive');
    }

    /**
     * Nothing is flagged while the whole crew can still sign in - the warning
     * has to be absent by default, or it says nothing when it appears.
     */
    public function test_nothing_is_flagged_while_the_whole_crew_is_active(): void
    {
        $this->actingAs($this->leadAccount);

        $this->get(route('technician.projects'))
            ->assertOk()
            ->assertDontSee('Inactive technician');

        $this->get(route('technician.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertDontSee('This team needs attention.');
    }

    /**
     * A plain technician is not shown it. They cannot act on somebody else's
     * account and have no tasks of anyone else's to move, so the flag would be
     * an alarm with nothing behind it.
     */
    public function test_a_plain_technician_is_not_shown_the_crew_warning(): void
    {
        $spare = Technician::create([
            'account_id' => $this->account('Ben Cruz', 'technician')->id,
            'role' => 'technician',
        ]);
        $this->assign($spare);

        $this->deactivate($this->mate);
        $this->actingAs($spare->account);

        $this->get(route('technician.projects'))
            ->assertOk()
            ->assertDontSee('Inactive technician');

        $this->get(route('technician.projects.show', $this->project->project_id))
            ->assertOk()
            ->assertDontSee('This team needs attention.');
    }

    // ------------------------------------------------------------------
    // The picker stops offering them
    // ------------------------------------------------------------------

    public function test_the_administrative_create_task_payload_says_who_cannot_take_work(): void
    {
        $this->deactivate($this->mate);
        $this->actingAsSuperAdmin();

        $technicians = collect(
            $this->getJson(route('super-admin.projects.task-form-data', $this->project->project_id))
                ->assertOk()
                ->json('technicians')
        );

        // Still listed: deactivating an account does not take somebody off a
        // team, and a picker that drops them reads as one that forgot them.
        $this->assertSame(
            ['Rita Lead', 'Ana Mendoza'],
            $technicians->pluck('name')->all()
        );

        $this->assertTrue($technicians->firstWhere('name', 'Rita Lead')['can_receive_work']);
        $this->assertFalse($technicians->firstWhere('name', 'Ana Mendoza')['can_receive_work']);
    }

    public function test_the_portal_create_task_payload_says_who_cannot_take_work(): void
    {
        $this->deactivate($this->mate);
        $this->actingAs($this->leadAccount);

        $technicians = collect(
            $this->getJson(route('technician.projects.task-form-data', $this->project->project_id))
                ->assertOk()
                ->json('technicians')
        );

        $this->assertFalse($technicians->firstWhere('name', 'Ana Mendoza')['can_receive_work']);
    }

    public function test_the_assign_card_is_not_clickable_on_project_details(): void
    {
        $this->deactivate($this->mate);

        foreach ([
            [fn () => $this->actingAsSuperAdmin(), route('super-admin.projects.show', $this->project->project_id)],
            [fn () => $this->actingAs($this->leadAccount), route('technician.projects.show', $this->project->project_id)],
        ] as [$signIn, $url]) {
            $signIn();

            $page = $this->get($url);
            $page->assertOk();

            $radios = $this->assignRadios($page->getContent(), (int) $this->mate->technician_id);

            $this->assertNotEmpty($radios, 'They are still listed on the team.');

            foreach ($radios as $radio) {
                $this->assertStringContainsString('disabled', $radio, $url);
            }
        }
    }

    // ------------------------------------------------------------------
    // The form refuses it whatever the browser submits
    // ------------------------------------------------------------------

    public function test_the_administrative_board_refuses_a_task_for_an_inactive_technician(): void
    {
        $this->deactivate($this->mate);
        $this->actingAsSuperAdmin();

        $this->from(route('super-admin.projects.show', $this->project->project_id))
            ->post(route('super-admin.task.store', $this->project->project_id), $this->taskPayload())
            ->assertSessionHasErrors('technician_id');

        $this->assertSame(0, Task::where('project_id', $this->project->project_id)->count());
    }

    public function test_the_portal_refuses_a_task_for_an_inactive_technician(): void
    {
        $this->deactivate($this->mate);
        $this->actingAs($this->leadAccount);

        $this->postJson(
            route('technician.tasks.store', $this->project->project_id),
            $this->taskPayload()
        )->assertStatus(422);

        $this->assertSame(0, Task::where('project_id', $this->project->project_id)->count());
    }

    /**
     * The refusal names the person and says what is wrong with the account,
     * rather than reading as an unexplained "pick somebody else".
     */
    public function test_the_refusal_says_whose_account_and_why(): void
    {
        $this->deactivate($this->mate);
        $this->actingAs($this->leadAccount);

        $message = $this->postJson(
            route('technician.tasks.store', $this->project->project_id),
            $this->taskPayload()
        )->json('error');

        $this->assertStringContainsString('Ana Mendoza', $message);
        $this->assertStringContainsString('deactivated', $message);
    }

    /**
     * An active teammate is unaffected: the rule refuses one person, not the
     * form.
     */
    public function test_an_active_technician_can_still_be_given_the_work(): void
    {
        $this->deactivate($this->mate);
        $this->actingAs($this->leadAccount);

        $this->postJson(
            route('technician.tasks.store', $this->project->project_id),
            $this->taskPayload(['technician_id' => $this->lead->technician_id])
        )->assertStatus(201);

        $this->assertSame(1, Task::where('project_id', $this->project->project_id)->count());
    }

    // ------------------------------------------------------------------
    // What the inactive technician is already holding
    // ------------------------------------------------------------------

    /**
     * Work already on an inactive technician stays editable. Its dates may
     * need changing, and above all it has to be possible to hand it to
     * somebody else - which is a save that re-submits the current owner up
     * until the moment it does not.
     */
    public function test_a_task_already_held_by_an_inactive_technician_stays_editable(): void
    {
        $task = $this->task($this->mate);
        $this->deactivate($this->mate);
        $this->actingAs($this->leadAccount);

        $this->postJson(route('technician.tasks.update', $task->task_id), $this->taskPayload([
            'task_title' => 'Pull the wiring again',
            'technician_id' => $this->mate->technician_id,
        ]))->assertOk();

        $this->assertSame('Pull the wiring again', $task->fresh()->task_title);
    }

    public function test_the_work_can_be_handed_to_somebody_who_can_do_it(): void
    {
        $task = $this->task($this->mate);
        $this->deactivate($this->mate);
        $this->actingAs($this->leadAccount);

        $this->postJson(route('technician.tasks.update', $task->task_id), $this->taskPayload([
            'task_title' => $task->task_title,
            'technician_id' => $this->lead->technician_id,
        ]))->assertOk();

        $this->assertSame(
            (int) $this->lead->technician_id,
            (int) $task->fresh()->technician_id
        );
    }

    /**
     * The exception is only ever for the owner it already has. Moving a task
     * ONTO an inactive technician is the thing being refused, whichever task
     * it is.
     */
    public function test_a_task_cannot_be_moved_onto_an_inactive_technician(): void
    {
        $task = $this->task($this->lead);
        $this->deactivate($this->mate);
        $this->actingAsSuperAdmin();

        $this->from(route('super-admin.projects.show', $this->project->project_id))
            ->put(route('super-admin.tasks.update', $task->task_id), $this->taskPayload([
                'task_title' => $task->task_title,
                'technician_id' => $this->mate->technician_id,
            ]))
            ->assertSessionHasErrors('technician_id');

        $this->assertSame(
            (int) $this->lead->technician_id,
            (int) $task->fresh()->technician_id
        );
    }

    /**
     * The card for whoever holds the task stays selectable, or saving the
     * dialog at all would be impossible - including the handover.
     */
    public function test_the_current_owners_card_is_still_selectable(): void
    {
        $task = $this->task($this->mate);
        $this->deactivate($this->mate);
        $this->actingAs($this->leadAccount);

        $page = $this->get(route('technician.projects.show', $this->project->project_id));
        $page->assertOk();

        $checked = array_filter(
            $this->assignRadios($page->getContent(), (int) $this->mate->technician_id),
            fn (string $radio): bool => str_contains($radio, 'checked')
        );

        $this->assertCount(1, $checked, 'The edit dialog pre-selects whoever holds the task.');
        $this->assertStringNotContainsString('disabled', reset($checked));
    }
}
