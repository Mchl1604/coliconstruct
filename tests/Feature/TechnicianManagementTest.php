<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Skill;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Super-admin Technicians page: specialty management on the Details tab and
 * schedule management (view / remove / assign) on the Schedules tab.
 */
class TechnicianManagementTest extends TestCase
{
    use RefreshDatabase;

    private function technician(string $name, string $role = 'technician'): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => $role])->save();

        return Technician::create([
            'account_id' => $user->id,
            'role' => $role,
        ]);
    }

    private function leadTechnician(string $name): Technician
    {
        return $this->technician($name, 'lead_technician');
    }

    /**
     * @param  array<int, Technician>  $technicians
     */
    private function project(string $name, array $technicians, string $status = 'ongoing'): Project
    {
        $project = Project::create([
            'name' => $name,
            'reference_no' => 'REF-'.strtoupper(substr(md5($name.microtime()), 0, 8)),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
        ]);

        foreach ($technicians as $technician) {
            ProjectTechnician::create([
                'project_id' => $project->project_id,
                'technician_id' => $technician->technician_id,
            ]);
        }

        return $project;
    }

    private function schedule(Project $project, string $start, string $end): Schedule
    {
        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $start.' 00:00:00',
            'end_datetime' => $end.' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        $project->projectTechnicians()->get()->each(function (ProjectTechnician $assignment) use ($schedule): void {
            ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $assignment->project_technician_id,
            ]);
        });

        return $schedule;
    }

    private function day(int $offset): string
    {
        return CarbonImmutable::today()->addDays($offset)->toDateString();
    }

    // ------------------------------------------------------------------
    // Details tab
    // ------------------------------------------------------------------

    public function test_the_page_lists_technicians_with_position_and_specialties(): void
    {
        $lead = $this->leadTechnician('Jose Garcia');
        $regular = $this->technician('Ana Mendoza');

        $skill = Skill::create(['skill_name' => 'Aircon Repair']);
        $lead->skills()->attach($skill->skill_id);

        $response = $this->get(route('super-admin.technicians.index'));

        $response->assertOk();
        $response->assertSee('Jose Garcia');
        $response->assertSee('Ana Mendoza');
        $response->assertSee('Lead Technician');
        $response->assertSee('Aircon Repair');
    }

    public function test_it_returns_technician_details(): void
    {
        $technician = $this->technician('Ana Mendoza');
        $skill = Skill::create(['skill_name' => 'Aircon Cleaning']);
        $technician->skills()->attach($skill->skill_id);

        $response = $this->getJson(route('super-admin.technicians.show', $technician->technician_id));

        $response->assertOk();
        $response->assertJsonPath('name', 'Ana Mendoza');
        $response->assertJsonPath('position', 'Technician');
        $response->assertJsonPath('specialties.0.skill_name', 'Aircon Cleaning');
    }

    public function test_it_adds_specialties_without_creating_duplicates(): void
    {
        $technician = $this->technician('Ana Mendoza');
        $cleaning = Skill::create(['skill_name' => 'Aircon Cleaning']);
        $repair = Skill::create(['skill_name' => 'Aircon Repair']);

        $technician->skills()->attach($cleaning->skill_id);

        // Re-sending an existing specialty alongside a new one must not
        // create a second pivot row.
        $response = $this->postJson(
            route('super-admin.technicians.specialties.store', $technician->technician_id),
            ['skill_ids' => [$cleaning->skill_id, $repair->skill_id]]
        );

        $response->assertOk();

        $this->assertSame(
            1,
            DB::table('tbl_skill_map')
                ->where('technician_id', $technician->technician_id)
                ->where('skill_id', $cleaning->skill_id)
                ->count()
        );
        $this->assertSame(
            2,
            DB::table('tbl_skill_map')->where('technician_id', $technician->technician_id)->count()
        );
    }

    public function test_it_removes_a_specialty(): void
    {
        $technician = $this->technician('Ana Mendoza');
        $skill = Skill::create(['skill_name' => 'Aircon Cleaning']);
        $technician->skills()->attach($skill->skill_id);

        $response = $this->deleteJson(route('super-admin.technicians.specialties.destroy', [
            $technician->technician_id,
            $skill->skill_id,
        ]));

        $response->assertOk();
        $response->assertJsonCount(0, 'technician.specialties');
        $this->assertSame(
            0,
            DB::table('tbl_skill_map')->where('technician_id', $technician->technician_id)->count()
        );
    }

    public function test_removing_a_specialty_the_technician_lacks_is_rejected(): void
    {
        $technician = $this->technician('Ana Mendoza');
        $skill = Skill::create(['skill_name' => 'Aircon Cleaning']);

        $this->deleteJson(route('super-admin.technicians.specialties.destroy', [
            $technician->technician_id,
            $skill->skill_id,
        ]))->assertStatus(422);
    }

    public function test_specialty_endpoints_return_json_not_a_redirect(): void
    {
        $technician = $this->technician('Ana Mendoza');

        $response = $this->postJson(
            route('super-admin.technicians.specialties.store', $technician->technician_id),
            ['skill_ids' => []]
        );

        $response->assertStatus(422);
        $this->assertSame('Select at least one specialty to add.', $response->json('error'));
    }

    // ------------------------------------------------------------------
    // Schedules tab - calendar
    // ------------------------------------------------------------------

    public function test_the_calendar_returns_only_this_technicians_projects(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $other = $this->technician('Someone Else');

        $mine = $this->project('My Project', [$ana]);
        $this->schedule($mine, $this->day(5), $this->day(6));

        $theirs = $this->project('Their Project', [$other]);
        $this->schedule($theirs, $this->day(5), $this->day(6));

        $response = $this->getJson(route('super-admin.technicians.calendar', $ana->technician_id));

        $response->assertOk();
        $response->assertJsonCount(1, 'events');
        $response->assertJsonPath('events.0.extendedProps.projectName', 'My Project');
        $response->assertJsonPath('assignmentCount', 1);
    }

    // ------------------------------------------------------------------
    // Removing a technician from a project
    // ------------------------------------------------------------------

    public function test_a_non_lead_can_be_removed_directly(): void
    {
        $lead = $this->leadTechnician('Jose Garcia');
        $ana = $this->technician('Ana Mendoza');
        $project = $this->project('Some Project', [$lead, $ana]);
        $this->schedule($project, $this->day(5), $this->day(6));

        $response = $this->deleteJson(route('super-admin.technicians.projects.destroy', [
            $ana->technician_id,
            $project->project_id,
        ]));

        $response->assertOk();

        $this->assertDatabaseMissing('tbl_project_technicians', [
            'project_id' => $project->project_id,
            'technician_id' => $ana->technician_id,
        ]);
        // The lead is untouched.
        $this->assertDatabaseHas('tbl_project_technicians', [
            'project_id' => $project->project_id,
            'technician_id' => $lead->technician_id,
        ]);
        // And their schedule rows went with them.
        $this->assertSame(1, ScheduleTechnician::count());
    }

    public function test_the_last_remaining_technician_cannot_be_removed(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $project = $this->project('Solo Project', [$ana]);
        $this->schedule($project, $this->day(5), $this->day(6));

        $response = $this->deleteJson(route('super-admin.technicians.projects.destroy', [
            $ana->technician_id,
            $project->project_id,
        ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('at least one technician', $response->json('error'));
        $this->assertDatabaseHas('tbl_project_technicians', [
            'project_id' => $project->project_id,
            'technician_id' => $ana->technician_id,
        ]);
    }

    public function test_a_lead_cannot_be_removed_without_a_replacement(): void
    {
        $lead = $this->leadTechnician('Jose Garcia');
        $ana = $this->technician('Ana Mendoza');
        $project = $this->project('Some Project', [$lead, $ana]);
        $this->schedule($project, $this->day(5), $this->day(6));

        $response = $this->deleteJson(route('super-admin.technicians.projects.destroy', [
            $lead->technician_id,
            $project->project_id,
        ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('Choose a replacement lead', $response->json('error'));
        $this->assertDatabaseHas('tbl_project_technicians', [
            'project_id' => $project->project_id,
            'technician_id' => $lead->technician_id,
        ]);
    }

    /**
     * Replacements are lead-role technicians from OUTSIDE the project who are
     * free for its whole schedule.
     */
    public function test_replacement_leads_exclude_busy_and_already_assigned_leads(): void
    {
        $lead = $this->leadTechnician('Jose Garcia');
        $ana = $this->technician('Ana Mendoza');
        $project = $this->project('Some Project', [$lead, $ana]);
        $this->schedule($project, $this->day(5), $this->day(9));

        $freeLead = $this->leadTechnician('Free Lead');

        // Busy on a day in the middle of the target range.
        $busyLead = $this->leadTechnician('Busy Lead');
        $clash = $this->project('Clashing Project', [$busyLead]);
        $this->schedule($clash, $this->day(7), $this->day(7));

        // A lead already on the project can't replace themselves.
        $secondLead = $this->leadTechnician('Second Lead');
        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $secondLead->technician_id,
        ]);

        $response = $this->getJson(route('super-admin.technicians.assignment', [
            $lead->technician_id,
            $project->project_id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('is_lead', true);

        $names = collect($response->json('replacement_leads'))->pluck('name')->all();

        $this->assertContains('Free Lead', $names);
        $this->assertNotContains('Busy Lead', $names);
        $this->assertNotContains('Second Lead', $names);
        $this->assertNotContains('Jose Garcia', $names);
    }

    public function test_an_unavailable_replacement_lead_is_rejected(): void
    {
        $lead = $this->leadTechnician('Jose Garcia');
        $ana = $this->technician('Ana Mendoza');
        $project = $this->project('Some Project', [$lead, $ana]);
        $this->schedule($project, $this->day(5), $this->day(9));

        $busyLead = $this->leadTechnician('Busy Lead');
        $clash = $this->project('Clashing Project', [$busyLead]);
        $this->schedule($clash, $this->day(7), $this->day(7));

        $response = $this->deleteJson(
            route('super-admin.technicians.projects.destroy', [$lead->technician_id, $project->project_id]),
            ['replacement_lead_id' => $busyLead->technician_id]
        );

        $response->assertStatus(422);
        $this->assertDatabaseHas('tbl_project_technicians', [
            'project_id' => $project->project_id,
            'technician_id' => $lead->technician_id,
        ]);
    }

    /**
     * The whole point of the flow: the incoming lead must be installed before
     * the outgoing one leaves, so the project is never lead-less.
     */
    public function test_replacing_the_lead_swaps_assignments_and_schedule_rows(): void
    {
        $lead = $this->leadTechnician('Jose Garcia');
        $ana = $this->technician('Ana Mendoza');
        $project = $this->project('Some Project', [$lead, $ana]);
        $schedule = $this->schedule($project, $this->day(5), $this->day(6));

        $newLead = $this->leadTechnician('New Lead');

        $response = $this->deleteJson(
            route('super-admin.technicians.projects.destroy', [$lead->technician_id, $project->project_id]),
            ['replacement_lead_id' => $newLead->technician_id]
        );

        $response->assertOk();

        // Outgoing lead is gone, incoming lead is on the project.
        $this->assertDatabaseMissing('tbl_project_technicians', [
            'project_id' => $project->project_id,
            'technician_id' => $lead->technician_id,
        ]);
        $this->assertDatabaseHas('tbl_project_technicians', [
            'project_id' => $project->project_id,
            'technician_id' => $newLead->technician_id,
        ]);

        // The replacement inherited the project's schedule.
        $newAssignment = ProjectTechnician::where('project_id', $project->project_id)
            ->where('technician_id', $newLead->technician_id)
            ->first();

        $this->assertDatabaseHas('tbl_schedule_technicians', [
            'schedule_id' => $schedule->schedule_id,
            'project_technician_id' => $newAssignment->project_technician_id,
        ]);

        // Project still has exactly two technicians, one of them a lead.
        $this->assertSame(
            2,
            ProjectTechnician::where('project_id', $project->project_id)->count()
        );
        $this->assertSame(2, ScheduleTechnician::count());
    }

    public function test_removing_a_technician_releases_their_unfinished_tasks(): void
    {
        $lead = $this->leadTechnician('Jose Garcia');
        $ana = $this->technician('Ana Mendoza');
        $project = $this->project('Some Project', [$lead, $ana]);
        $this->schedule($project, $this->day(5), $this->day(6));

        $open = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $ana->technician_id,
            'task_title' => 'Open Task',
            'task_description' => 'Description',
            'status' => 'pending',
        ]);

        $done = Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $ana->technician_id,
            'task_title' => 'Finished Task',
            'task_description' => 'Description',
            'status' => 'completed',
        ]);

        $this->deleteJson(route('super-admin.technicians.projects.destroy', [
            $ana->technician_id,
            $project->project_id,
        ]))->assertOk();

        $open->refresh();
        $done->refresh();

        $this->assertNull($open->technician_id);
        $this->assertSame('unassigned', $open->status);

        // Finished work keeps its history.
        $this->assertSame($ana->technician_id, $done->technician_id);
        $this->assertSame('completed', $done->status);
    }

    public function test_a_technician_cannot_be_removed_from_a_read_only_project(): void
    {
        $lead = $this->leadTechnician('Jose Garcia');
        $ana = $this->technician('Ana Mendoza');
        $project = $this->project('Done Project', [$lead, $ana], 'completed');
        $this->schedule($project, $this->day(5), $this->day(6));

        $response = $this->deleteJson(route('super-admin.technicians.projects.destroy', [
            $ana->technician_id,
            $project->project_id,
        ]));

        $response->assertStatus(422);
        $this->assertDatabaseHas('tbl_project_technicians', [
            'project_id' => $project->project_id,
            'technician_id' => $ana->technician_id,
        ]);
    }

    // ------------------------------------------------------------------
    // Assigning a technician to more projects
    // ------------------------------------------------------------------

    public function test_assignable_projects_respect_status_and_availability(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $lead = $this->leadTechnician('Jose Garcia');

        // Already booked days 5-6.
        $current = $this->project('Current Project', [$ana]);
        $this->schedule($current, $this->day(5), $this->day(6));

        $free = $this->project('Free Project', [$lead]);
        $this->schedule($free, $this->day(20), $this->day(21));

        // Overlaps Ana's existing booking only in the middle.
        $clashing = $this->project('Clashing Project', [$lead]);
        $this->schedule($clashing, $this->day(4), $this->day(8));

        $completed = $this->project('Completed Project', [$lead], 'completed');
        $this->schedule($completed, $this->day(20), $this->day(21));

        $response = $this->getJson(route('super-admin.technicians.assignable', $ana->technician_id));

        $response->assertOk();

        $eligible = collect($response->json('projects'))->pluck('name')->all();
        $blocked = collect($response->json('blocked'))->pluck('name')->all();

        $this->assertContains('Free Project', $eligible);
        $this->assertContains('Clashing Project', $blocked);
        $this->assertNotContains('Current Project', array_merge($eligible, $blocked));
        $this->assertNotContains('Completed Project', array_merge($eligible, $blocked));
    }

    public function test_assigning_creates_project_and_schedule_rows(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $lead = $this->leadTechnician('Jose Garcia');

        $project = $this->project('Target Project', [$lead]);
        $schedule = $this->schedule($project, $this->day(5), $this->day(6));

        $response = $this->postJson(
            route('super-admin.technicians.projects.store', $ana->technician_id),
            ['project_ids' => [$project->project_id]]
        );

        $response->assertOk();

        $assignment = ProjectTechnician::where('project_id', $project->project_id)
            ->where('technician_id', $ana->technician_id)
            ->first();

        $this->assertNotNull($assignment);
        $this->assertDatabaseHas('tbl_schedule_technicians', [
            'schedule_id' => $schedule->schedule_id,
            'project_technician_id' => $assignment->project_technician_id,
        ]);
    }

    public function test_assigning_to_an_unavailable_project_is_rejected(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $lead = $this->leadTechnician('Jose Garcia');

        $current = $this->project('Current Project', [$ana]);
        $this->schedule($current, $this->day(5), $this->day(9));

        // Free at both ends, busy in the middle.
        $target = $this->project('Target Project', [$lead]);
        $this->schedule($target, $this->day(3), $this->day(12));

        $response = $this->postJson(
            route('super-admin.technicians.projects.store', $ana->technician_id),
            ['project_ids' => [$target->project_id]]
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('unavailable on', $response->json('error'));
        $this->assertDatabaseMissing('tbl_project_technicians', [
            'project_id' => $target->project_id,
            'technician_id' => $ana->technician_id,
        ]);
    }

    /**
     * The browser greys out overlapping projects, but a stale page could post
     * them together anyway.
     */
    public function test_assigning_two_overlapping_projects_at_once_is_rejected(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $lead = $this->leadTechnician('Jose Garcia');

        $first = $this->project('First Project', [$lead]);
        $this->schedule($first, $this->day(5), $this->day(9));

        $second = $this->project('Second Project', [$lead]);
        $this->schedule($second, $this->day(7), $this->day(12));

        $response = $this->postJson(
            route('super-admin.technicians.projects.store', $ana->technician_id),
            ['project_ids' => [$first->project_id, $second->project_id]]
        );

        $response->assertStatus(422);

        // All-or-nothing: neither assignment may survive.
        $this->assertSame(
            0,
            ProjectTechnician::where('technician_id', $ana->technician_id)->count()
        );
    }

    public function test_assigning_to_a_read_only_project_is_rejected(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $lead = $this->leadTechnician('Jose Garcia');

        $completed = $this->project('Completed Project', [$lead], 'completed');
        $this->schedule($completed, $this->day(5), $this->day(6));

        $this->postJson(
            route('super-admin.technicians.projects.store', $ana->technician_id),
            ['project_ids' => [$completed->project_id]]
        )->assertStatus(422);

        $this->assertSame(
            0,
            ProjectTechnician::where('technician_id', $ana->technician_id)->count()
        );
    }
}
