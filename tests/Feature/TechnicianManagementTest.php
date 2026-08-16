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

    protected function setUp(): void
    {
        parent::setUp();

        // Every administrative route is behind `auth` and a role check.
        $this->actingAsSuperAdmin();
    }

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

    /**
     * The table and the picker print a code, not the bare key, so a
     * technician can be quoted out loud.
     */
    public function test_the_page_prints_technicians_by_their_code(): void
    {
        $technician = $this->technician('Ana Mendoza');
        $code = sprintf('TECH-%04d', $technician->technician_id);

        $response = $this->get(route('super-admin.technicians.index'));

        $response->assertOk();
        $response->assertSee($code);
        // The picker's datalist entry, which the page's script matches on.
        $response->assertSee($code.' — Ana Mendoza', escape: false);
        $response->assertSee('"display_code":"'.$code.'"', escape: false);
    }

    public function test_it_returns_technician_details(): void
    {
        $technician = $this->technician('Ana Mendoza');
        $skill = Skill::create(['skill_name' => 'Aircon Cleaning']);
        $technician->skills()->attach($skill->skill_id);

        $response = $this->getJson(route('super-admin.technicians.show', $technician->technician_id));

        $response->assertOk();
        $response->assertJsonPath('name', 'Ana Mendoza');
        $response->assertJsonPath('display_code', sprintf('TECH-%04d', $technician->technician_id));
        $response->assertJsonPath('position', 'Technician');
        $response->assertJsonPath('specialties.0.skill_name', 'Aircon Cleaning');
    }

    /**
     * The modal stages adds and removes, then saves the whole desired set in
     * one call, so a single sync has to handle both directions at once.
     */
    public function test_it_syncs_specialties_in_one_call(): void
    {
        $technician = $this->technician('Ana Mendoza');
        $cleaning = Skill::create(['skill_name' => 'Aircon Cleaning']);
        $repair = Skill::create(['skill_name' => 'Aircon Repair']);
        $ducting = Skill::create(['skill_name' => 'Ducting Installation']);

        $technician->skills()->attach([$cleaning->skill_id, $repair->skill_id]);

        // Drop Aircon Repair and add Ducting Installation in one save.
        $response = $this->putJson(
            route('super-admin.technicians.specialties.sync', $technician->technician_id),
            ['skill_ids' => [$cleaning->skill_id, $ducting->skill_id]]
        );

        $response->assertOk();

        $assigned = DB::table('tbl_skill_map')
            ->where('technician_id', $technician->technician_id)
            ->pluck('skill_id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([$cleaning->skill_id, $ducting->skill_id], $assigned);
    }

    public function test_syncing_never_creates_duplicate_rows(): void
    {
        $technician = $this->technician('Ana Mendoza');
        $cleaning = Skill::create(['skill_name' => 'Aircon Cleaning']);

        $technician->skills()->attach($cleaning->skill_id);

        // Same id twice, plus one it already has.
        $this->putJson(
            route('super-admin.technicians.specialties.sync', $technician->technician_id),
            ['skill_ids' => [$cleaning->skill_id, $cleaning->skill_id]]
        )->assertOk();

        $this->assertSame(
            1,
            DB::table('tbl_skill_map')
                ->where('technician_id', $technician->technician_id)
                ->count()
        );
    }

    public function test_syncing_an_empty_list_clears_every_specialty(): void
    {
        $technician = $this->technician('Ana Mendoza');
        $skill = Skill::create(['skill_name' => 'Aircon Cleaning']);
        $technician->skills()->attach($skill->skill_id);

        $response = $this->putJson(
            route('super-admin.technicians.specialties.sync', $technician->technician_id),
            ['skill_ids' => []]
        );

        $response->assertOk();
        $response->assertJsonCount(0, 'technician.specialties');
        $this->assertSame(
            0,
            DB::table('tbl_skill_map')->where('technician_id', $technician->technician_id)->count()
        );
    }

    public function test_syncing_an_unknown_specialty_is_rejected_as_json(): void
    {
        $technician = $this->technician('Ana Mendoza');

        $response = $this->putJson(
            route('super-admin.technicians.specialties.sync', $technician->technician_id),
            ['skill_ids' => [99999]]
        );

        $response->assertStatus(422);
        $this->assertNotNull($response->json('error'));
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
        $response->assertJsonPath('activeCount', 1);
    }

    /**
     * The details panel needs the project's COMPLETE schedule, its address,
     * and its tasks - the clicked calendar day must never be what's shown.
     */
    public function test_the_assignment_payload_carries_everything_the_panel_shows(): void
    {
        $lead = $this->leadTechnician('Jose Garcia');
        $ana = $this->technician('Ana Mendoza');

        $project = $this->project('Ducting Installation', [$lead, $ana]);
        $project->forceFill(['address' => 'Skybridge Offices, Pasay City'])->save();
        $this->schedule($project, $this->day(5), $this->day(9));

        Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $ana->technician_id,
            'task_title' => 'Mark hanger locations',
            'task_description' => 'Mark hanger locations and drill supports.',
            'start_date' => $this->day(5),
            'due_date' => $this->day(6),
            'status' => 'completed',
        ]);

        $response = $this->getJson(route('super-admin.technicians.assignment', [
            $ana->technician_id,
            $project->project_id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('project.address', 'Skybridge Offices, Pasay City');

        // Whole range, both endpoints, regardless of which day was clicked.
        $response->assertJsonPath('project.start_date', $this->day(5));
        $response->assertJsonPath('project.end_date', $this->day(9));
        $response->assertJsonPath('project.ranges.0.start', $this->day(5));
        $response->assertJsonPath('project.ranges.0.end', $this->day(9));
        $this->assertNotNull($response->json('project.ranges.0.label'));

        // Lead vs supporting is what the panel splits on.
        $technicians = collect($response->json('project.technicians'));
        $this->assertTrue($technicians->firstWhere('name', 'Jose Garcia')['is_lead']);
        $this->assertFalse($technicians->firstWhere('name', 'Ana Mendoza')['is_lead']);

        $response->assertJsonCount(1, 'project.tasks');
        $response->assertJsonPath('project.tasks.0.title', 'Mark hanger locations');
        $response->assertJsonPath('project.tasks.0.status_label', 'Completed');
        $response->assertJsonPath('project.tasks.0.technician', 'Ana Mendoza');
        $this->assertStringContainsString(' - ', $response->json('project.tasks.0.range_label'));
    }

    /**
     * Cancelled work is out of the table entirely. Completed work stays on the
     * table as history but is not part of the figure beside the calendar -
     * that figure is what the technician is carrying now.
     */
    public function test_the_calendar_figure_counts_only_live_work(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $lead = $this->leadTechnician('Jose Garcia');

        $ongoing = $this->project('Ongoing Project', [$lead, $ana]);
        $this->schedule($ongoing, $this->day(5), $this->day(6));

        $cancelled = $this->project('Cancelled Project', [$lead, $ana], 'cancelled');
        $this->schedule($cancelled, $this->day(10), $this->day(11));

        $completed = $this->project('Completed Project', [$lead, $ana], 'completed');
        $this->schedule($completed, $this->day(-10), $this->day(-9));

        $response = $this->getJson(route('super-admin.technicians.calendar', $ana->technician_id));

        $response->assertOk();

        $names = collect($response->json('projects'))->pluck('name')->all();

        $this->assertNotContains('Cancelled Project', $names);
        $this->assertContains('Ongoing Project', $names);
        // Completed work is history worth keeping on the list.
        $this->assertContains('Completed Project', $names);

        // ...but only the ongoing project is a live assignment.
        $this->assertSame(1, $response->json('activeCount'));
    }

    /**
     * The panel lists the selected technician's own work on the project,
     * not every task on it.
     */
    public function test_the_panel_only_returns_tasks_for_the_selected_technician(): void
    {
        $lead = $this->leadTechnician('Jose Garcia');
        $ana = $this->technician('Ana Mendoza');
        $project = $this->project('Shared Project', [$lead, $ana]);
        $this->schedule($project, $this->day(5), $this->day(6));

        foreach ([[$ana, 'Ana Task'], [$lead, 'Jose Task']] as [$technician, $title]) {
            Task::create([
                'project_id' => $project->project_id,
                'technician_id' => $technician->technician_id,
                'task_title' => $title,
                'task_description' => 'Description',
                'start_date' => $this->day(5),
                'due_date' => $this->day(6),
                'status' => 'pending',
            ]);
        }

        // An unassigned task belongs to nobody, so neither should see it.
        Task::create([
            'project_id' => $project->project_id,
            'technician_id' => null,
            'task_title' => 'Floating Task',
            'task_description' => 'Description',
            'status' => 'unassigned',
        ]);

        $anaResponse = $this->getJson(route('super-admin.technicians.assignment', [
            $ana->technician_id,
            $project->project_id,
        ]));

        $anaResponse->assertOk();
        $anaResponse->assertJsonCount(1, 'project.tasks');
        $anaResponse->assertJsonPath('project.tasks.0.title', 'Ana Task');

        $leadResponse = $this->getJson(route('super-admin.technicians.assignment', [
            $lead->technician_id,
            $project->project_id,
        ]));

        $leadResponse->assertOk();
        $leadResponse->assertJsonCount(1, 'project.tasks');
        $leadResponse->assertJsonPath('project.tasks.0.title', 'Jose Task');
    }

    /**
     * The assignments table lists every project the technician is on,
     * including ones with no schedule that never reach the calendar.
     */
    public function test_the_calendar_endpoint_returns_the_assignments_table_rows(): void
    {
        $lead = $this->leadTechnician('Jose Garcia');
        $ana = $this->technician('Ana Mendoza');

        $leadOf = $this->project('Led Project', [$lead, $ana]);
        $this->schedule($leadOf, $this->day(5), $this->day(6));

        $unscheduled = $this->project('Unscheduled Project', [$lead]);

        $elsewhere = $this->project('Someone Elses Project', [$ana]);
        $this->schedule($elsewhere, $this->day(20), $this->day(21));

        Task::create([
            'project_id' => $leadOf->project_id,
            'technician_id' => $lead->technician_id,
            'task_title' => 'Lead Task',
            'task_description' => 'Description',
            'start_date' => $this->day(5),
            'due_date' => $this->day(6),
            'status' => 'pending',
        ]);

        $response = $this->getJson(route('super-admin.technicians.calendar', $lead->technician_id));

        $response->assertOk();

        $projects = collect($response->json('projects'));

        $this->assertSame(2, $projects->count());
        $this->assertSame(2, $response->json('activeCount'));
        $this->assertNull($projects->firstWhere('name', 'Someone Elses Project'));

        $led = $projects->firstWhere('name', 'Led Project');
        $this->assertTrue($led['is_lead_technician']);
        $this->assertTrue($led['has_schedule']);
        $this->assertSame(1, $led['technician_task_count']);

        // Listed even though it has no schedule, so it has no calendar event:
        // the table shows two projects but the calendar only one.
        $none = $projects->firstWhere('name', 'Unscheduled Project');
        $this->assertFalse($none['has_schedule']);
        $this->assertSame(0, $none['technician_task_count']);
        $response->assertJsonCount(1, 'events');
    }

    /**
     * A project scheduled in two blocks must report both, so the panel can
     * show the complete schedule rather than a single span.
     */
    public function test_the_payload_reports_every_schedule_range(): void
    {
        $lead = $this->leadTechnician('Jose Garcia');
        $ana = $this->technician('Ana Mendoza');
        $project = $this->project('Split Project', [$lead, $ana]);

        $this->schedule($project, $this->day(5), $this->day(6));
        $this->schedule($project, $this->day(20), $this->day(22));

        $response = $this->getJson(route('super-admin.technicians.assignment', [
            $ana->technician_id,
            $project->project_id,
        ]));

        $response->assertOk();
        $response->assertJsonCount(2, 'project.ranges');
        $response->assertJsonPath('project.ranges.0.start', $this->day(5));
        $response->assertJsonPath('project.ranges.1.end', $this->day(22));
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

    /**
     * A project's lead is derived from the account role, so it can only have
     * one. A lead technician is therefore only offered projects that don't
     * already have a lead.
     */
    public function test_a_lead_technician_is_only_offered_projects_without_a_lead(): void
    {
        $incoming = $this->leadTechnician('Carlo Ramirez');
        $existingLead = $this->leadTechnician('Jose Garcia');
        $ana = $this->technician('Ana Mendoza');

        $ledAlready = $this->project('Already Led', [$existingLead, $ana]);
        $this->schedule($ledAlready, $this->day(5), $this->day(6));

        // Only a regular technician on it, so no lead yet.
        $needsLead = $this->project('Needs A Lead', [$ana]);
        $this->schedule($needsLead, $this->day(20), $this->day(21));

        $response = $this->getJson(route('super-admin.technicians.assignable', $incoming->technician_id));

        $response->assertOk();

        $eligible = collect($response->json('projects'))->pluck('name')->all();
        $blocked = collect($response->json('blocked'));

        $this->assertSame(['Needs A Lead'], $eligible);
        $this->assertTrue(
            $blocked->contains(fn (array $row): bool => $row['name'] === 'Already Led'
                && str_contains((string) $row['reason'], 'Jose Garcia')
                && str_contains((string) $row['reason'], 'only have one lead'))
        );
    }

    /**
     * The rule is specific to leads: a supporting technician can still join a
     * project that already has one.
     */
    public function test_a_regular_technician_can_still_join_a_project_that_has_a_lead(): void
    {
        $existingLead = $this->leadTechnician('Jose Garcia');
        $ana = $this->technician('Ana Mendoza');
        $incoming = $this->technician('Kevin Lopez');

        $ledAlready = $this->project('Already Led', [$existingLead, $ana]);
        $this->schedule($ledAlready, $this->day(5), $this->day(6));

        $response = $this->getJson(route('super-admin.technicians.assignable', $incoming->technician_id));

        $response->assertOk();
        $this->assertContains(
            'Already Led',
            collect($response->json('projects'))->pluck('name')->all()
        );
    }

    public function test_assigning_a_second_lead_by_direct_post_is_rejected(): void
    {
        $incoming = $this->leadTechnician('Carlo Ramirez');
        $existingLead = $this->leadTechnician('Jose Garcia');

        $ledAlready = $this->project('Already Led', [$existingLead]);
        $this->schedule($ledAlready, $this->day(5), $this->day(6));

        $response = $this->postJson(
            route('super-admin.technicians.projects.store', $incoming->technician_id),
            ['project_ids' => [$ledAlready->project_id]]
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('only have one lead', $response->json('error'));
        $this->assertDatabaseMissing('tbl_project_technicians', [
            'project_id' => $ledAlready->project_id,
            'technician_id' => $incoming->technician_id,
        ]);
    }

    public function test_a_lead_technician_can_be_assigned_to_a_project_without_a_lead(): void
    {
        $incoming = $this->leadTechnician('Carlo Ramirez');
        $ana = $this->technician('Ana Mendoza');

        $needsLead = $this->project('Needs A Lead', [$ana]);
        $schedule = $this->schedule($needsLead, $this->day(5), $this->day(6));

        $response = $this->postJson(
            route('super-admin.technicians.projects.store', $incoming->technician_id),
            ['project_ids' => [$needsLead->project_id]]
        );

        $response->assertOk();

        $assignment = ProjectTechnician::where('project_id', $needsLead->project_id)
            ->where('technician_id', $incoming->technician_id)
            ->first();

        $this->assertNotNull($assignment);
        $this->assertDatabaseHas('tbl_schedule_technicians', [
            'schedule_id' => $schedule->schedule_id,
            'project_technician_id' => $assignment->project_technician_id,
        ]);
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

    /**
     * A project can be staffed before it is scheduled - restoring an archived
     * project leaves it unscheduled with no team at all.
     */
    public function test_unscheduled_projects_can_be_staffed(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $lead = $this->leadTechnician('Jose Garcia');

        $fresh = $this->project('Restored Project', [$lead], 'unscheduled');

        $response = $this->getJson(route('super-admin.technicians.assignable', $ana->technician_id));

        $response->assertOk();

        $eligible = collect($response->json('projects'));
        $row = $eligible->firstWhere('name', 'Restored Project');

        $this->assertNotNull($row, 'An unscheduled project should be offered.');
        $this->assertFalse($row['has_schedule'] ?? true);
        $this->assertSame('No schedule set', $row['range_label']);

        $save = $this->postJson(
            route('super-admin.technicians.projects.store', $ana->technician_id),
            ['project_ids' => [$fresh->project_id]]
        );

        $save->assertOk();

        $this->assertDatabaseHas('tbl_project_technicians', [
            'project_id' => $fresh->project_id,
            'technician_id' => $ana->technician_id,
        ]);

        // Nothing to link yet, so no schedule rows are created.
        $this->assertSame(0, ScheduleTechnician::count());
    }

    /**
     * Staffing first then scheduling must end up with the technician linked to
     * the new range, otherwise they would be on the team but not the schedule.
     */
    public function test_scheduling_after_staffing_links_the_technician_to_the_new_range(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $lead = $this->leadTechnician('Jose Garcia');

        $fresh = $this->project('Restored Project', [$lead], 'unscheduled');

        $this->postJson(
            route('super-admin.technicians.projects.store', $ana->technician_id),
            ['project_ids' => [$fresh->project_id]]
        )->assertOk();

        // Now book it from the schedules calendar.
        $this->postJson(route('super-admin.schedules.assign'), [
            'start_date' => $this->day(5),
            'end_date' => $this->day(6),
            'project_ids' => [$fresh->project_id],
        ])->assertOk();

        $schedule = Schedule::where('project_id', $fresh->project_id)->firstOrFail();

        // Both the pre-existing lead and the later-added technician are linked.
        $linkedTechnicians = ScheduleTechnician::where('schedule_id', $schedule->schedule_id)
            ->get()
            ->map(fn (ScheduleTechnician $link) => $link->projectTechnician?->technician_id)
            ->filter()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            collect([$lead->technician_id, $ana->technician_id])->sort()->values()->all(),
            $linkedTechnicians
        );
    }

    /**
     * An unscheduled project has no dates, so it can never be the source of an
     * availability clash - it stays offered however busy the technician is.
     */
    public function test_an_unscheduled_project_is_offered_even_to_a_fully_booked_technician(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $lead = $this->leadTechnician('Jose Garcia');

        $busy = $this->project('Busy Project', [$ana]);
        $this->schedule($busy, $this->day(1), $this->day(30));

        $fresh = $this->project('Restored Project', [$lead], 'unscheduled');

        $response = $this->getJson(route('super-admin.technicians.assignable', $ana->technician_id));

        $response->assertOk();
        $this->assertContains(
            'Restored Project',
            collect($response->json('projects'))->pluck('name')->all()
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
