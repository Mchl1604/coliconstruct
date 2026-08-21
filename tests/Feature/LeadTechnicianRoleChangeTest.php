<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Skill;
use App\Models\Technician;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What a change of job title may and may not do to work already booked.
 *
 * A project's lead is derived from the account role rather than stored, so
 * editing an account in Configuration used to reach into live projects without
 * saying so - a demotion stripped the lead from every project at once, and a
 * promotion either handed somebody a project or put a second lead on one.
 * TechnicianRoleChangeRules refuses both until the projects have been sorted
 * out by hand, which is what this covers.
 *
 * The other half is the projects that were left lead-less before that guard
 * existed. Those cannot be repaired automatically - who leads a job is not a
 * thing to guess - so they are flagged instead, on the same warning the
 * inactive-crew case already uses.
 */
class LeadTechnicianRoleChangeTest extends TestCase
{
    use RefreshDatabase;

    private Skill $skill;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
        $this->skill = Skill::create(['skill_name' => 'Aircon']);
    }

    // ------------------------------------------------------------------
    // Demotion
    // ------------------------------------------------------------------

    public function test_a_lead_cannot_be_demoted_while_they_lead_a_live_project(): void
    {
        $lead = $this->technician('Rita Lead', User::ROLE_LEAD_TECHNICIAN);
        $project = $this->project('PRJ-0001', 'ongoing');
        $this->assign($project, $lead);

        $response = $this->changeRole($lead->account, 'technician');

        $response->assertStatus(422)
            ->assertJsonPath('role_change.message', 'Role change affects 1 project.')
            ->assertJsonPath('role_change.action', 'Assign a new Lead Technician before continuing.')
            ->assertJsonPath('role_change.projects.0.name', 'Project PRJ-0001');

        // The link goes to the team that has to change, not to the top of the
        // project page.
        $this->assertStringContainsString(
            route('super-admin.projects.show', $project->project_id).'#assigned-team',
            $response->json('role_change.projects.0.url')
        );

        // Nothing was written: the account keeps its role and the project
        // keeps its lead.
        $this->assertSame(User::ROLE_LEAD_TECHNICIAN, $lead->account->fresh()->role);
        $this->assertTrue($project->fresh()->hasLead());
    }

    public function test_the_refusal_names_every_project_in_the_way(): void
    {
        $lead = $this->technician('Rita Lead', User::ROLE_LEAD_TECHNICIAN);

        foreach (['PRJ-0001', 'PRJ-0002', 'PRJ-0003'] as $reference) {
            $this->assign($this->project($reference, 'ongoing'), $lead);
        }

        $body = $this->changeRole($lead->account, 'technician')->json('role_change');

        $this->assertSame('Role change affects 3 projects.', $body['message']);
        $this->assertSame(
            ['Project PRJ-0001', 'Project PRJ-0002', 'Project PRJ-0003'],
            array_column($body['projects'], 'name')
        );
    }

    public function test_a_lead_may_be_demoted_once_the_lead_has_been_handed_over(): void
    {
        $lead = $this->technician('Rita Lead', User::ROLE_LEAD_TECHNICIAN);
        $successor = $this->technician('Eve Spare', User::ROLE_LEAD_TECHNICIAN);
        $mate = $this->technician('Ana Mendoza', 'technician');

        $project = $this->project('PRJ-0001', 'ongoing');
        $this->assign($project, $lead);
        $this->assign($project, $mate);
        $this->schedule($project, 1, 5);

        // The hand-over, done the way an administrator would: the team editor,
        // with the successor chosen in the lead select.
        $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $successor->technician_id,
            'technicians' => [$mate->technician_id],
        ])->assertSessionHas('success');

        $this->changeRole($lead->account, 'technician')->assertOk();

        $this->assertSame('technician', $lead->account->fresh()->role);
        $this->assertSame(
            (int) $successor->technician_id,
            (int) $project->fresh()->leadAssignment()?->technician_id
        );
    }

    public function test_history_never_blocks_a_demotion(): void
    {
        $lead = $this->technician('Rita Lead', User::ROLE_LEAD_TECHNICIAN);

        foreach (['completed', 'cancelled'] as $status) {
            $this->assign($this->project('PRJ-'.$status, $status), $lead);
        }

        $archived = $this->project('PRJ-archived', 'ongoing');
        $archived->is_archived = true;
        $archived->save();
        $this->assign($archived, $lead);

        $this->changeRole($lead->account, 'technician')->assertOk();
    }

    public function test_leaving_the_technician_roles_altogether_is_guarded_the_same_way(): void
    {
        $lead = $this->technician('Rita Lead', User::ROLE_LEAD_TECHNICIAN);
        $this->assign($this->project('PRJ-0001', 'ongoing'), $lead);

        // What matters is that the account stops being a Lead Technician, not
        // what it becomes instead.
        $this->changeRole($lead->account, User::ROLE_ADMIN)->assertStatus(422);
    }

    public function test_an_edit_that_does_not_touch_the_role_is_left_alone(): void
    {
        $lead = $this->technician('Rita Lead', User::ROLE_LEAD_TECHNICIAN);
        $this->assign($this->project('PRJ-0001', 'ongoing'), $lead);

        $this->changeRole($lead->account, User::ROLE_LEAD_TECHNICIAN, ['first_name' => 'Rita-Anne'])
            ->assertOk();

        $this->assertSame('Rita-Anne', $lead->account->fresh()->first_name);
    }

    // ------------------------------------------------------------------
    // Promotion
    // ------------------------------------------------------------------

    public function test_a_promotion_may_not_silently_hand_somebody_a_lead_less_project(): void
    {
        $mate = $this->technician('Ana Mendoza', 'technician');
        $project = $this->project('PRJ-0001', 'ongoing');
        $this->assign($project, $mate);

        $this->changeRole($mate->account, User::ROLE_LEAD_TECHNICIAN)
            ->assertStatus(422)
            ->assertJsonPath('role_change.message', 'Cannot update role.')
            ->assertJsonPath(
                'role_change.action',
                'This change would make them Lead Technician on assigned projects.'
            )
            ->assertJsonPath('role_change.projects.0.name', 'Project PRJ-0001');

        $this->assertSame('technician', $mate->account->fresh()->role);
    }

    public function test_a_promotion_may_not_put_a_second_lead_on_a_project(): void
    {
        $lead = $this->technician('Rita Lead', User::ROLE_LEAD_TECHNICIAN);
        $mate = $this->technician('Ana Mendoza', 'technician');

        $project = $this->project('PRJ-0001', 'ongoing');
        $this->assign($project, $lead);
        $this->assign($project, $mate);

        $this->changeRole($mate->account, User::ROLE_LEAD_TECHNICIAN)
            ->assertStatus(422)
            ->assertJsonPath('role_change.message', 'Cannot update role.')
            ->assertJsonPath(
                'role_change.action',
                'This change would create multiple Lead Technicians.'
            )
            ->assertJsonPath('role_change.projects.0.name', 'Project PRJ-0001');

        // The project still carries exactly one.
        $leads = $project->fresh()->projectTechnicians
            ->filter(fn (ProjectTechnician $a): bool => (bool) $a->technician?->isLead());

        $this->assertCount(1, $leads);
    }

    public function test_a_technician_with_no_live_work_may_be_promoted(): void
    {
        $mate = $this->technician('Ana Mendoza', 'technician');
        $this->assign($this->project('PRJ-done', 'completed'), $mate);

        $this->changeRole($mate->account, User::ROLE_LEAD_TECHNICIAN)->assertOk();

        $this->assertSame(User::ROLE_LEAD_TECHNICIAN, $mate->account->fresh()->role);
    }

    // ------------------------------------------------------------------
    // The projects that were left lead-less before the guard existed
    // ------------------------------------------------------------------

    public function test_a_live_project_with_no_lead_is_flagged_for_re_crewing(): void
    {
        $mate = $this->technician('Ana Mendoza', 'technician');
        $project = $this->project('PRJ-0001', 'ongoing');
        $this->assign($project, $mate);

        $project = $project->fresh();

        $this->assertFalse($project->hasLead());
        $this->assertTrue($project->needsRecrew());
        $this->assertSame('No lead technician', $project->recrewFlagLabel());
    }

    public function test_a_finished_project_with_no_lead_is_not_flagged(): void
    {
        $mate = $this->technician('Ana Mendoza', 'technician');
        $project = $this->project('PRJ-done', 'completed');
        $this->assign($project, $mate);

        $this->assertFalse($project->fresh()->needsRecrew());
    }

    public function test_the_projects_page_shows_the_missing_lead_warning(): void
    {
        $mate = $this->technician('Ana Mendoza', 'technician');
        $project = $this->project('PRJ-0001', 'ongoing');
        $this->assign($project, $mate);

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('No lead technician assigned', false)
            // The landing point every refused role change links to. Without it
            // those links open the project and leave the reader to find the
            // team themselves.
            ->assertSee('id="assigned-team"', false);
    }

    // ------------------------------------------------------------------
    // Replacement leads
    // ------------------------------------------------------------------

    public function test_a_lead_can_be_replaced_on_a_project_that_has_no_dates_yet(): void
    {
        $lead = $this->technician('Rita Lead', User::ROLE_LEAD_TECHNICIAN);
        $successor = $this->technician('Eve Spare', User::ROLE_LEAD_TECHNICIAN);
        $mate = $this->technician('Ana Mendoza', 'technician');

        $project = $this->project('PRJ-0001', 'unscheduled');
        $this->assign($project, $lead);
        $this->assign($project, $mate);

        // The panel has somebody to offer, where it used to offer nobody at
        // all and leave the lead impossible to remove.
        $this->get(route('super-admin.technicians.assignment', [$lead->technician_id, $project->project_id]))
            ->assertOk()
            ->assertJsonPath('replacement_leads.0.name', 'Eve Spare');

        $this->delete(
            route('super-admin.technicians.projects.destroy', [$lead->technician_id, $project->project_id]),
            ['replacement_lead_id' => $successor->technician_id]
        )->assertOk();

        $this->assertSame(
            (int) $successor->technician_id,
            (int) $project->fresh()->leadAssignment()?->technician_id
        );
    }

    public function test_a_switched_off_lead_is_never_offered_or_accepted_as_a_replacement(): void
    {
        $lead = $this->technician('Rita Lead', User::ROLE_LEAD_TECHNICIAN);
        $disabled = $this->technician('Ken Dormant', User::ROLE_LEAD_TECHNICIAN);
        $mate = $this->technician('Ana Mendoza', 'technician');

        $project = $this->project('PRJ-0001', 'ongoing');
        $this->assign($project, $lead);
        $this->assign($project, $mate);
        $this->schedule($project, 1, 5);

        $this->put(route('super-admin.configuration.users.status', $disabled->account->id), [
            'status' => User::STATUS_DEACTIVATED,
        ])->assertOk();

        $this->get(route('super-admin.technicians.assignment', [$lead->technician_id, $project->project_id]))
            ->assertOk()
            ->assertJsonPath('replacement_leads', []);

        // And refused whatever a stale page submits.
        $this->delete(
            route('super-admin.technicians.projects.destroy', [$lead->technician_id, $project->project_id]),
            ['replacement_lead_id' => $disabled->technician_id]
        )->assertStatus(422);

        $this->assertSame(
            (int) $lead->technician_id,
            (int) $project->fresh()->leadAssignment()?->technician_id
        );
    }

    public function test_an_archived_lead_is_not_offered_as_a_replacement(): void
    {
        $lead = $this->technician('Rita Lead', User::ROLE_LEAD_TECHNICIAN);
        $archived = $this->technician('Bea Filed', User::ROLE_LEAD_TECHNICIAN);
        $mate = $this->technician('Ana Mendoza', 'technician');

        $project = $this->project('PRJ-0001', 'ongoing');
        $this->assign($project, $lead);
        $this->assign($project, $mate);
        $this->schedule($project, 1, 5);

        $this->delete(route('super-admin.configuration.users.archive', $archived->account->id))->assertOk();

        $this->get(route('super-admin.technicians.assignment', [$lead->technician_id, $project->project_id]))
            ->assertOk()
            ->assertJsonPath('replacement_leads', []);
    }

    // ------------------------------------------------------------------
    // The policy no longer trusts the door alone
    // ------------------------------------------------------------------

    public function test_a_deactivated_lead_holds_no_powers_on_their_project(): void
    {
        $lead = $this->technician('Rita Lead', User::ROLE_LEAD_TECHNICIAN);
        $project = $this->project('PRJ-0001', 'ongoing');
        $this->assign($project, $lead);
        $this->schedule($project, 1, 5);

        $policy = app(ProjectPolicy::class);

        $this->assertTrue($policy->manageTasks($lead->account->fresh(), $project->fresh()));

        $this->put(route('super-admin.configuration.users.status', $lead->account->id), [
            'status' => User::STATUS_DEACTIVATED,
        ])->assertOk();

        $account = $lead->account->fresh();

        $this->assertFalse($policy->viewAssigned($account, $project->fresh()));
        $this->assertFalse($policy->manageTasks($account, $project->fresh()));
        $this->assertFalse($policy->submitReport($account, $project->fresh()));
        $this->assertFalse($policy->complete($account, $project->fresh()));
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function technician(string $name, string $role): Technician
    {
        $sequence = User::count() + 100;

        $account = User::create([
            'user_code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => $name,
            'first_name' => explode(' ', $name)[0],
            'last_name' => explode(' ', $name)[1] ?? 'Person',
            'contact_number' => '09171234567',
            'birthdate' => '1990-01-01',
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);

        $technician = Technician::create(['account_id' => $account->id, 'role' => $role]);
        $technician->skills()->sync([$this->skill->skill_id]);

        return $technician;
    }

    private function project(string $reference, string $status): Project
    {
        return Project::create([
            'name' => 'Project '.$reference,
            'reference_no' => $reference,
            'status' => $status,
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);
    }

    private function assign(Project $project, Technician $technician): ProjectTechnician
    {
        return ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);
    }

    private function schedule(Project $project, int $from, int $to): Schedule
    {
        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => now()->addDays($from)->format('Y-m-d').' 00:00:00',
            'end_datetime' => now()->addDays($to)->format('Y-m-d').' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        foreach ($project->projectTechnicians()->get() as $assignment) {
            ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $assignment->project_technician_id,
            ]);
        }

        return $schedule;
    }

    /**
     * Save the account through the real Configuration endpoint, which is the
     * only place a role is ever changed.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function changeRole(User $user, string $role, array $overrides = []): TestResponse
    {
        return $this->post(
            route('super-admin.configuration.users.employees.update', $user->id),
            array_merge([
                'first_name' => $user->first_name,
                'middle_name' => $user->middle_name,
                'last_name' => $user->last_name,
                'contact_number' => '09171234567',
                'birthdate' => '1990-01-01',
                'email' => $user->email,
                'role' => $role,
                'skill_ids' => [$this->skill->skill_id],
            ], $overrides)
        );
    }
}
