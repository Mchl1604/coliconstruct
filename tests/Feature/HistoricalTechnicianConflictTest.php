<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleCorrection;
use App\Models\ScheduleTechnician;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Naming somebody for a day the record already has them working elsewhere.
 *
 * A clash in the FUTURE is a booking that cannot be made, and the existing
 * availability rules refuse it outright. A clash in the PAST is two records
 * disagreeing about a day that has gone, and only a Super Admin can say which
 * is right - so this one is detected, shown, confirmed, audited and then
 * allowed, in that order. Never silently refused, and never silently accepted.
 *
 * The line these pin is that the two behaviours stay apart: nothing here
 * loosens a future check, and nothing in the future checks reaches back to
 * block a correction.
 */
class HistoricalTechnicianConflictTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = $this->account('super_admin', 'super@example.test', 'Super Admin');
        $this->actingAs($this->superAdmin);
    }

    // ------------------------------------------------------------------
    // Detection
    // ------------------------------------------------------------------

    public function test_a_newly_added_past_day_that_clashes_is_refused_until_it_is_confirmed(): void
    {
        $lead = $this->leadTechnician('John Smith');

        // The other project already has John on the day in question.
        $elsewhere = $this->project([$lead], 'Elsewhere');
        $this->book($elsewhere, -6, -4);

        $project = $this->project([$lead], 'Correction');
        $schedule = $this->book($project, -3, -1);

        // Pull the start back over days John is already recorded on.
        $response = $this->save($project, [$this->range($schedule, -6, -1)], [$lead->technician_id]);

        $conflicts = session('historicalConflicts');

        $this->assertNotNull($conflicts, 'The save should have come back asking about the clash.');
        $this->assertSame((int) $project->project_id, $conflicts['project_id']);
        $this->assertSame('John Smith', $conflicts['conflicts'][0]['technician']);

        // Nothing was written while the question was outstanding.
        $this->assertSame(
            [['start' => $this->day(-3), 'end' => $this->day(-1)]],
            $this->rangesOf($project),
            'The schedule must be untouched while the clash is unconfirmed.'
        );
        $this->assertDatabaseCount('tbl_schedule_corrections', 0);

        $response->assertRedirect(route('super-admin.schedules.index'));
    }

    public function test_the_same_save_goes_through_once_the_clash_is_confirmed(): void
    {
        $lead = $this->leadTechnician('John Smith');

        $elsewhere = $this->project([$lead], 'Elsewhere');
        $this->book($elsewhere, -6, -4);

        $project = $this->project([$lead], 'Correction');
        $schedule = $this->book($project, -3, -1);

        $this->save($project, [$this->range($schedule, -6, -1)], [$lead->technician_id], confirmed: true)
            ->assertRedirect(route('super-admin.schedules.index'))
            ->assertSessionHas('success');

        $this->assertSame(
            [['start' => $this->day(-6), 'end' => $this->day(-1)]],
            $this->rangesOf($project)
        );
    }

    public function test_only_the_newly_added_days_are_checked(): void
    {
        $lead = $this->leadTechnician('John Smith');

        // John is recorded elsewhere on a day this project ALREADY holds. That
        // is history as it stands, not something this save is claiming, so it
        // must not be raised.
        $elsewhere = $this->project([$lead], 'Elsewhere');
        $this->book($elsewhere, -2, -2);

        $project = $this->project([$lead], 'Correction');
        $schedule = $this->book($project, -3, -1);

        // Resubmitted exactly as stored: nothing newly claimed.
        $this->save($project, [$this->range($schedule, -3, -1)])
            ->assertSessionHas('success');

        $this->assertNull(session('historicalConflicts'));
    }

    public function test_a_future_clash_is_still_refused_by_the_existing_rules(): void
    {
        // The guarantee that this feature changed nothing about tomorrow: a
        // future double-booking is still an outright refusal, not a question.
        $lead = $this->leadTechnician('John Smith');

        $elsewhere = $this->project([$lead], 'Elsewhere');
        $this->book($elsewhere, 4, 6);

        $project = $this->project([$lead], 'Correction');
        $schedule = $this->book($project, 10, 12);

        $this->save($project, [$this->range($schedule, 4, 12)], [$lead->technician_id], confirmed: true)
            ->assertSessionHas('error');

        $this->assertNull(session('historicalConflicts'));
        $this->assertSame(
            [['start' => $this->day(10), 'end' => $this->day(12)]],
            $this->rangesOf($project),
            'A future clash must not be savable by confirming a historical one.'
        );
    }

    public function test_a_finished_project_still_counts_as_a_conflicting_record(): void
    {
        // The case the ordinary availability rules cannot see: past work is
        // usually recorded against a project that has since been completed,
        // and those are screened out of every future check.
        $lead = $this->leadTechnician('John Smith');

        $elsewhere = $this->project([$lead], 'Finished', 'completed');
        $this->book($elsewhere, -6, -4);

        $project = $this->project([$lead], 'Correction');
        $schedule = $this->book($project, -3, -1);

        $this->save($project, [$this->range($schedule, -6, -1)], [$lead->technician_id]);

        $this->assertNotNull(
            session('historicalConflicts'),
            'A completed project holds the record of who worked those days and must be consulted.'
        );
    }

    public function test_a_projects_own_days_are_never_a_conflict_with_itself(): void
    {
        $lead = $this->leadTechnician('John Smith');

        $project = $this->project([$lead], 'Correction');
        $first = $this->book($project, -8, -6);
        $second = $this->book($project, -3, -1);

        // Stretching one range back over days the OTHER range of the same
        // project holds is the project meeting itself, which is not a clash.
        $this->save($project, [
            $this->range($first, -8, -6),
            $this->range($second, -5, -1),
        ], [$lead->technician_id])->assertSessionHas('success');

        $this->assertNull(session('historicalConflicts'));
    }

    // ------------------------------------------------------------------
    // Several technicians at once
    // ------------------------------------------------------------------

    public function test_each_technician_is_judged_separately(): void
    {
        $john = $this->leadTechnician('John Smith');
        $maria = $this->technician('Maria Santos');
        $pedro = $this->technician('Pedro Cruz');

        // John and Pedro are recorded elsewhere; Maria is free.
        $elsewhere = $this->project([$john, $pedro], 'Elsewhere');
        $this->book($elsewhere, -6, -4);

        $project = $this->project([$john, $maria, $pedro], 'Correction');
        $schedule = $this->book($project, -3, -1);

        $this->save(
            $project,
            [$this->range($schedule, -6, -1)],
            [$john->technician_id, $maria->technician_id, $pedro->technician_id]
        );

        $conflicts = session('historicalConflicts');

        $this->assertNotNull($conflicts);

        $flagged = array_column($conflicts['conflicts'], 'technician');
        sort($flagged);

        $this->assertSame(['John Smith', 'Pedro Cruz'], $flagged);
        $this->assertNotContains('Maria Santos', $flagged, 'A technician who is free must not be flagged.');
    }

    // ------------------------------------------------------------------
    // Partial days
    // ------------------------------------------------------------------

    public function test_partial_day_hours_that_do_not_overlap_are_not_a_conflict(): void
    {
        $lead = $this->leadTechnician('John Smith');

        // Booked elsewhere in the morning only.
        $elsewhere = $this->project([$lead], 'Elsewhere', 'ongoing', 'Residential');
        $this->bookPartialDay($elsewhere, -5, '08:00', '10:00');

        $project = $this->project([$lead], 'Correction', 'ongoing', 'Residential');

        // Recording an afternoon on the same day: the hours do not meet.
        $this->save($project, [
            $this->partialRange(null, -5, '13:00', '15:00'),
        ], [$lead->technician_id])->assertSessionHas('success');

        $this->assertNull(session('historicalConflicts'));
    }

    public function test_partial_day_hours_that_overlap_are_a_conflict(): void
    {
        $lead = $this->leadTechnician('John Smith');

        $elsewhere = $this->project([$lead], 'Elsewhere', 'ongoing', 'Residential');
        $this->bookPartialDay($elsewhere, -5, '08:00', '12:00');

        $project = $this->project([$lead], 'Correction', 'ongoing', 'Residential');

        $this->save($project, [
            $this->partialRange(null, -5, '10:00', '14:00'),
        ], [$lead->technician_id]);

        $this->assertNotNull(session('historicalConflicts'));
    }

    // ------------------------------------------------------------------
    // The audit
    // ------------------------------------------------------------------

    public function test_a_confirmed_clash_is_written_into_the_correction(): void
    {
        $lead = $this->leadTechnician('John Smith');

        $elsewhere = $this->project([$lead], 'Elsewhere');
        $otherSchedule = $this->book($elsewhere, -6, -4);

        $project = $this->project([$lead], 'Correction');
        $schedule = $this->book($project, -3, -1);

        $this->save($project, [$this->range($schedule, -6, -1)], [$lead->technician_id], confirmed: true)
            ->assertSessionHas('success');

        $correction = ScheduleCorrection::query()
            ->where('project_id', $project->project_id)
            ->whereNotNull('conflicts')
            ->first();

        $this->assertNotNull($correction, 'A confirmed clash must leave an audit row.');

        $row = $correction->conflicts[0];

        // Everything an auditor needs to reconstruct the decision.
        $this->assertSame('John Smith', $row['technician']);
        $this->assertSame((int) $lead->technician_id, $row['technician_id']);
        $this->assertSame((int) $elsewhere->project_id, $row['conflicting_project_id']);
        $this->assertSame((int) $otherSchedule->schedule_id, $row['conflicting_schedule_id']);
        $this->assertNotEmpty($row['conflicting_schedule']);
        $this->assertContains($row['date'], [$this->day(-6), $this->day(-5), $this->day(-4)]);
        $this->assertTrue($row['confirmed']);
        $this->assertSame($this->superAdmin->id, $row['confirmed_by_id']);
        $this->assertNotEmpty($row['confirmed_at']);

        // And the Super Admin who made it is on the correction itself.
        $this->assertSame($this->superAdmin->id, $correction->actor_id);
        $this->assertSame((int) $project->project_id, (int) $correction->project_id);
    }

    public function test_a_correction_with_no_clash_records_no_conflicts(): void
    {
        $lead = $this->leadTechnician('John Smith');

        $project = $this->project([$lead], 'Correction');
        $schedule = $this->book($project, -3, -1);

        $this->save($project, [$this->range($schedule, -6, -1)], [$lead->technician_id])
            ->assertSessionHas('success');

        $correction = ScheduleCorrection::query()
            ->where('project_id', $project->project_id)
            ->first();

        $this->assertNotNull($correction);
        $this->assertNull($correction->conflicts, 'Nothing clashed, so there is nothing to answer for.');
    }

    // ------------------------------------------------------------------
    // The pre-flight the editor asks
    // ------------------------------------------------------------------

    public function test_the_check_endpoint_reports_the_clash_before_the_save_is_attempted(): void
    {
        $lead = $this->leadTechnician('John Smith');

        $elsewhere = $this->project([$lead], 'Elsewhere');
        $this->book($elsewhere, -6, -4);

        $project = $this->project([$lead], 'Correction');
        $schedule = $this->book($project, -3, -1);

        // First pass: which days, and who could have worked them.
        $first = $this->postJson(
            route('super-admin.schedules.historical-check', $project->project_id),
            ['ranges' => [$this->range($schedule, -6, -1)]]
        )->assertOk();

        $this->assertTrue($first->json('required'));
        $this->assertSame([], $first->json('conflicts'), 'Nobody is named yet, so nothing can clash.');

        // Second pass: with a name, the clash comes back.
        $second = $this->postJson(
            route('super-admin.schedules.historical-check', $project->project_id),
            [
                'ranges' => [$this->range($schedule, -6, -1)],
                'historical_technicians' => [$lead->technician_id],
            ]
        )->assertOk();

        $conflicts = $second->json('conflicts');

        $this->assertNotEmpty($conflicts);
        $this->assertSame('John Smith', $conflicts[0]['technician']);
        $this->assertSame($elsewhere->name, $conflicts[0]['entries'][0]['project']);
        $this->assertNotEmpty($conflicts[0]['entries'][0]['schedule']);
        $this->assertNotEmpty($conflicts[0]['entries'][0]['date_label']);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function account(string $role, string $email, string $name): User
    {
        $user = User::factory()->create(['email' => $email, 'name' => $name]);

        $user->forceFill(['role' => $role, 'status' => User::STATUS_ACTIVE, 'name' => $name])->save();

        return $user;
    }

    private function technician(string $name, string $role = 'technician'): Technician
    {
        return Technician::create([
            'account_id' => $this->account(
                $role,
                strtolower(str_replace(' ', '.', $name)).'@example.test',
                $name
            )->id,
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
    private function project(
        array $technicians,
        string $name,
        string $status = 'ongoing',
        string $clientType = 'Commercial'
    ): Project {
        $project = Project::create([
            'name' => $name,
            'reference_no' => 'PRJ-'.strtoupper(substr(md5($name.microtime()), 0, 8)),
            'status' => $status,
            'address' => '1 Test Street',
            'description' => 'Work',
            'quotation' => 1000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => $clientType,
            'firstname' => 'Juan',
            'surname' => 'Dela Cruz',
            'fullname' => 'Juan Dela Cruz',
            'company_name' => 'Acme',
            'email_address' => 'client'.$project->project_id.'@example.test',
            'contact_number' => '09171234567',
        ]);

        foreach ($technicians as $technician) {
            ProjectTechnician::create([
                'project_id' => $project->project_id,
                'technician_id' => $technician->technician_id,
                'joined_at' => Schedule::businessToday()->subDays(120),
            ]);
        }

        return $project->refresh();
    }

    private function book(Project $project, int $startOffset, int $endOffset): Schedule
    {
        return $this->attachCrew($project, Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $this->day($startOffset).' 00:00:00',
            'end_datetime' => $this->day($endOffset).' 23:59:59',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]));
    }

    private function bookPartialDay(Project $project, int $offset, string $from, string $to): Schedule
    {
        return $this->attachCrew($project, Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $this->day($offset).' '.$from.':00',
            'end_datetime' => $this->day($offset).' '.$to.':00',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]));
    }

    private function attachCrew(Project $project, Schedule $schedule): Schedule
    {
        foreach ($project->projectTechnicians()->get() as $assignment) {
            ScheduleTechnician::create([
                'schedule_id' => $schedule->schedule_id,
                'project_technician_id' => $assignment->project_technician_id,
            ]);
        }

        return $schedule;
    }

    private function day(int $offset): string
    {
        return Schedule::businessToday()->addDays($offset)->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    private function range(?Schedule $schedule, int $startOffset, int $endOffset): array
    {
        return array_filter([
            'schedule_id' => $schedule?->schedule_id,
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => $this->day($startOffset),
            'end_date' => $this->day($endOffset),
        ], fn ($value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function partialRange(?Schedule $schedule, int $offset, string $from, string $to): array
    {
        return array_filter([
            'schedule_id' => $schedule?->schedule_id,
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day($offset),
            'start_time' => $from,
            'end_time' => $to,
        ], fn ($value): bool => $value !== null);
    }

    /**
     * @param  array<int, array<string, mixed>>  $ranges
     * @param  array<int, int>  $technicianIds
     */
    private function save(
        Project $project,
        array $ranges,
        array $technicianIds = [],
        bool $confirmed = false
    ) {
        $payload = [
            'ranges' => $ranges,
            // Every save in this suite is a Super Admin correcting the past,
            // which is what puts the historical step in play at all.
            'override_past_lock' => '1',
            'historical_add_technicians' => '1',
        ];

        if ($technicianIds !== []) {
            $payload['historical_technicians'] = $technicianIds;
        }

        if ($confirmed) {
            $payload['historical_conflicts_confirmed'] = '1';
        }

        return $this->put(route('super-admin.schedules.update', $project->project_id), $payload);
    }

    /**
     * @return array<int, array{start: string, end: string}>
     */
    private function rangesOf(Project $project): array
    {
        return Schedule::query()
            ->where('project_id', $project->project_id)
            ->orderBy('start_datetime')
            ->get()
            ->map(fn (Schedule $schedule): array => [
                'start' => $schedule->startsOn()->toDateString(),
                'end' => $schedule->endsOn()->toDateString(),
            ])
            ->all();
    }
}
