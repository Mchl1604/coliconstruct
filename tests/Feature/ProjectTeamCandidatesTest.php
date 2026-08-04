<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\ProjectType;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Skill;
use App\Models\Technician;
use App\Models\User;
use App\Services\ProjectTeamCandidates;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Who the assigned-team picker offers, and in what order.
 */
class ProjectTeamCandidatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
    }

    private function createTechnician(string $name, string $role = 'technician'): Technician
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

    private function giveSkill(Technician $technician, string $skillName): void
    {
        $skill = Skill::firstOrCreate(['skill_name' => $skillName]);

        $technician->skills()->syncWithoutDetaching([$skill->skill_id]);
    }

    private function createProject(string $status = 'ongoing'): Project
    {
        return Project::create([
            'name' => 'Project '.uniqid(),
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
        ]);
    }

    /**
     * Book a technician onto a project for one date range.
     */
    private function book(Project $project, Technician $technician, string $startDate, string $endDate): Schedule
    {
        $projectTechnician = ProjectTechnician::firstOrCreate([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);

        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $startDate.' 00:00:00',
            'end_datetime' => $endDate.' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        ScheduleTechnician::create([
            'schedule_id' => $schedule->schedule_id,
            'project_technician_id' => $projectTechnician->project_technician_id,
        ]);

        return $schedule;
    }

    /**
     * A date range on a project that nobody is booked for yet.
     */
    private function addRange(Project $project, string $startDate, string $endDate): Schedule
    {
        return Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $startDate.' 00:00:00',
            'end_datetime' => $endDate.' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function candidatesFor(Project $project): Collection
    {
        return app(ProjectTeamCandidates::class)->forProject($project->fresh([
            'schedules',
            'projectTypes',
            'projectTechnicians',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function candidate(Collection $candidates, Technician $technician): array
    {
        return $candidates->firstWhere('id', (int) $technician->technician_id);
    }

    // ------------------------------------------------------------------
    // Availability across every date range
    // ------------------------------------------------------------------

    /**
     * The bug this replaces: only the FIRST date range was screened, so a
     * technician booked during a later range was still offered.
     */
    public function test_a_technician_booked_during_a_later_range_is_not_offered(): void
    {
        $technician = $this->createTechnician('Jose Garcia');

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();
        $day11 = CarbonImmutable::today()->addDays(11)->toDateString();
        $day20 = CarbonImmutable::today()->addDays(20)->toDateString();
        $day21 = CarbonImmutable::today()->addDays(21)->toDateString();

        $project = $this->createProject();
        $this->addRange($project, $day10, $day11);
        $this->addRange($project, $day20, $day21);

        // Free during the first range, booked elsewhere during the second.
        $this->book($this->createProject(), $technician, $day20, $day21);

        $candidate = $this->candidate($this->candidatesFor($project), $technician);

        $this->assertFalse($candidate['available']);
        $this->assertFalse($candidate['selectable']);
        $this->assertStringContainsString('Booked on', $candidate['reason']);
    }

    public function test_a_technician_free_across_every_range_is_offered(): void
    {
        $technician = $this->createTechnician('Ana Cruz');

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();
        $day20 = CarbonImmutable::today()->addDays(20)->toDateString();

        $project = $this->createProject();
        $this->addRange($project, $day10, $day10);
        $this->addRange($project, $day20, $day20);

        $candidate = $this->candidate($this->candidatesFor($project), $technician);

        $this->assertTrue($candidate['available']);
        $this->assertSame('', $candidate['reason']);
    }

    /**
     * A technician's booking for THIS project must not read as a clash with it.
     */
    public function test_the_projects_own_booking_is_not_a_conflict(): void
    {
        $technician = $this->createTechnician('Mark Reyes');

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();

        $project = $this->createProject();
        $this->book($project, $technician, $day10, $day10);

        $candidate = $this->candidate($this->candidatesFor($project), $technician);

        $this->assertTrue($candidate['available']);
        $this->assertTrue($candidate['assigned']);
    }

    /**
     * Somebody already on the team can always be kept on it, otherwise the
     * form could not be submitted at all.
     */
    public function test_an_assigned_technician_stays_selectable_even_when_double_booked(): void
    {
        $technician = $this->createTechnician('Lito Santos');

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();

        $project = $this->createProject();
        $this->book($project, $technician, $day10, $day10);
        // A clash that slipped in through another route.
        $this->book($this->createProject(), $technician, $day10, $day10);

        $candidate = $this->candidate($this->candidatesFor($project), $technician);

        $this->assertFalse($candidate['available']);
        $this->assertTrue($candidate['selectable']);
    }

    public function test_nobody_is_blocked_when_the_project_has_no_dates_yet(): void
    {
        $technician = $this->createTechnician('Rey Bautista');

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();
        $this->book($this->createProject(), $technician, $day10, $day10);

        // This project has no schedule at all, so there is nothing to clash with.
        $candidate = $this->candidate($this->candidatesFor($this->createProject()), $technician);

        $this->assertTrue($candidate['available']);
    }

    // ------------------------------------------------------------------
    // Suggestions
    // ------------------------------------------------------------------

    public function test_a_matching_skill_makes_a_free_technician_suggested(): void
    {
        $matching = $this->createTechnician('Zeny Aquino');
        $this->giveSkill($matching, 'Aircon Repair');

        $other = $this->createTechnician('Al Domingo');
        $this->giveSkill($other, 'Ducting Fabrication');

        $project = $this->createProject();
        $type = ProjectType::firstOrCreate(['type_name' => 'Aircon Repair']);
        $project->projectTypes()->sync([$type->type_id]);

        $candidates = $this->candidatesFor($project);

        $this->assertTrue($this->candidate($candidates, $matching)['suggested']);
        $this->assertSame(['Aircon Repair'], $this->candidate($candidates, $matching)['matched_skills']);
        $this->assertFalse($this->candidate($candidates, $other)['suggested']);
        $this->assertSame([], $this->candidate($candidates, $other)['matched_skills']);
    }

    /**
     * A perfect skill match is worthless if the person cannot work the dates.
     */
    public function test_a_booked_technician_is_never_suggested(): void
    {
        $technician = $this->createTechnician('Nina Flores');
        $this->giveSkill($technician, 'Aircon Repair');

        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();

        $project = $this->createProject();
        $this->addRange($project, $day10, $day10);
        $project->projectTypes()->sync([ProjectType::firstOrCreate(['type_name' => 'Aircon Repair'])->type_id]);

        $this->book($this->createProject(), $technician, $day10, $day10);

        $candidate = $this->candidate($this->candidatesFor($project), $technician);

        $this->assertFalse($candidate['suggested']);
        $this->assertSame(['Aircon Repair'], $candidate['matched_skills']);
    }

    public function test_the_order_is_suggested_then_free_then_booked(): void
    {
        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();

        $project = $this->createProject();
        $this->addRange($project, $day10, $day10);
        $project->projectTypes()->sync([ProjectType::firstOrCreate(['type_name' => 'Aircon Repair'])->type_id]);

        // Named so that alphabetical order alone would produce the reverse.
        $booked = $this->createTechnician('Aaron Booked');
        $this->book($this->createProject(), $booked, $day10, $day10);

        $free = $this->createTechnician('Bea Free');

        $suggested = $this->createTechnician('Cora Skilled');
        $this->giveSkill($suggested, 'Aircon Repair');

        $order = $this->candidatesFor($project)->pluck('name')->all();

        $this->assertSame(['Cora Skilled', 'Bea Free', 'Aaron Booked'], $order);
        $this->assertSame($suggested->technician_id, $this->candidatesFor($project)->first()['id']);
        $this->assertSame($free->technician_id, $this->candidatesFor($project)[1]['id']);
    }

    // ------------------------------------------------------------------
    // What the page hands to the picker
    // ------------------------------------------------------------------

    public function test_the_project_page_ships_the_screened_list(): void
    {
        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();
        $day20 = CarbonImmutable::today()->addDays(20)->toDateString();

        $project = $this->createProject();
        $this->addRange($project, $day10, $day10);
        $this->addRange($project, $day20, $day20);

        $free = $this->createTechnician('Bea Free');
        $booked = $this->createTechnician('Aaron Booked');
        // Busy during the SECOND range only - the case the old screen missed.
        $this->book($this->createProject(), $booked, $day20, $day20);

        $response = $this->get(route('super-admin.projects.show', $project->project_id));

        $response->assertOk();

        $lookup = collect($response->viewData('assignedTeamLookup'));

        $this->assertTrue($lookup->firstWhere('id', (int) $free->technician_id)['available']);
        $this->assertFalse($lookup->firstWhere('id', (int) $booked->technician_id)['available']);
    }

    /**
     * The lead select and the technician picker must agree, so they are built
     * from the same screened list.
     */
    public function test_the_lead_options_carry_the_same_verdict(): void
    {
        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();
        $day20 = CarbonImmutable::today()->addDays(20)->toDateString();

        $project = $this->createProject();
        $this->addRange($project, $day10, $day10);
        $this->addRange($project, $day20, $day20);

        $lead = $this->createTechnician('Lead Person', 'lead_technician');
        $this->book($this->createProject(), $lead, $day20, $day20);

        $response = $this->get(route('super-admin.projects.show', $project->project_id));

        $options = collect($response->viewData('leadTechnicianOptions'));

        $this->assertCount(1, $options);
        $this->assertFalse($options->first()['available']);
        $this->assertFalse($options->first()['selectable']);
    }

    /**
     * The picker only offers people the save would accept - the two screens
     * must never disagree.
     */
    public function test_saving_a_technician_the_picker_blocked_is_still_rejected(): void
    {
        $day10 = CarbonImmutable::today()->addDays(10)->toDateString();
        $day20 = CarbonImmutable::today()->addDays(20)->toDateString();

        $project = $this->createProject();
        $this->addRange($project, $day10, $day10);
        $this->addRange($project, $day20, $day20);

        $lead = $this->createTechnician('Lead Person', 'lead_technician');
        $booked = $this->createTechnician('Aaron Booked');
        $this->book($this->createProject(), $booked, $day20, $day20);

        $response = $this->put(route('super-admin.projects.team.update', $project->project_id), [
            'lead_tech' => $lead->technician_id,
            'technicians' => [$booked->technician_id],
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('tbl_project_technicians', [
            'project_id' => $project->project_id,
            'technician_id' => $booked->technician_id,
        ]);
    }
}
