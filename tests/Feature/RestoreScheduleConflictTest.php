<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Technician;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A restore that clashes with the calendar, reported and resolved as what it
 * actually is: a problem with one range of the project's schedule.
 *
 * The refusal itself is old news - ProjectArchiveRestoreTest covers that an
 * archived project's dates stop occupying its crew, that other work may take
 * those days, and that putting the first project back is refused when it would
 * double-book somebody. What that refusal amounted to was one sentence in a
 * toast, and what it now amounts to is the project's schedule, range by range,
 * with a verdict against each.
 *
 * Three rules carry most of this:
 *
 *   - A range is the unit. "Aug 24-26" is available or it is in conflict; it
 *     is never reported as six separate days, and never as a list of
 *     technicians. Who is unavailable is supporting detail hanging off the
 *     range that has the problem.
 *
 *   - A range that has entirely ended is history. It is shown, it is not
 *     screened, it is not editable, and it can never block a restore - the
 *     calendar today has nothing to say about a week that is over.
 *
 *   - The archived project's own range is what moves. The live work it clashed
 *     with is somebody's week already in motion, and a restore is no reason to
 *     rewrite it.
 */
class RestoreScheduleConflictTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function technician(string $name): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => 'technician'])->save();

        return Technician::create(['account_id' => $user->id, 'role' => 'technician']);
    }

    /**
     * @param  array<int, Technician>  $technicians
     */
    private function project(array $technicians, string $status, string $reference): Project
    {
        $project = Project::create([
            'name' => 'Conflict Project',
            'reference_no' => $reference,
            'status' => $status,
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 250000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Commercial',
            'company_name' => 'Some Holdings',
            'firstname' => 'Client',
            'surname' => 'One',
            'fullname' => 'Client One',
            'email_address' => 'client'.$project->project_id.'@example.test',
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

    private function book(Project $project, int $startOffset, int $endOffset): Schedule
    {
        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $this->day($startOffset)->startOfDay(),
            'end_datetime' => $this->day($endOffset)->endOfDay(),
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

    private function day(int $offset): CarbonImmutable
    {
        return Schedule::businessToday()->addDays($offset);
    }

    private function archive(Project $project): Project
    {
        $this->post(route('super-admin.projects.archive', $project->project_id))
            ->assertSessionHasNoErrors();

        return $project->fresh();
    }

    /**
     * The restore as the Archived Projects page sends it: JSON, because a
     * refusal has to come back with enough in it to draw the dialog.
     */
    private function restoreAsJson(Project $project)
    {
        return $this->putJson(route('super-admin.projects.restore', $project->project_id));
    }

    private function report(Project $project): array
    {
        return $this->getJson(route('super-admin.projects.restore-conflicts', $project->project_id))
            ->assertOk()
            ->json();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function ranges(Project $project): array
    {
        return $this->report($project)['ranges'];
    }

    /**
     * The standing arrangement behind most of these: an archived project with
     * two ranges, and other work that took the crew's days over the second one
     * while the project was away.
     *
     * @return array{0: Project, 1: Project, 2: Technician}
     */
    private function clashingPair(): array
    {
        $john = $this->technician('John Doe');

        $archived = $this->project([$john], 'ongoing', 'PRJ-20260716-00013');
        $this->book($archived, 10, 12);
        $this->book($archived, 20, 22);
        $archived = $this->archive($archived);

        // Allowed, and the entire point of archiving: those days really are
        // free now.
        $live = $this->project([$john], 'ongoing', 'PRJ-20260801-00005');
        $this->book($live, 21, 25);

        return [$archived, $live, $john];
    }

    // ==================================================================
    // The schedule is reported as ranges
    // ==================================================================

    public function test_the_schedule_is_reported_range_by_range(): void
    {
        $this->actingAsSuperAdmin();

        [$archived] = $this->clashingPair();

        $ranges = $this->ranges($archived);

        // Two ranges, because the project holds two - not six days.
        $this->assertCount(2, $ranges);

        $this->assertSame(
            $this->day(10)->format('M j, Y').' - '.$this->day(12)->format('M j, Y'),
            $ranges[0]['label']
        );
        $this->assertSame(
            $this->day(20)->format('M j, Y').' - '.$this->day(22)->format('M j, Y'),
            $ranges[1]['label']
        );
    }

    /**
     * Only the range that clashes is marked, and the whole of it is - the
     * other range is untouched and stays available.
     */
    public function test_only_the_conflicting_range_carries_the_conflict(): void
    {
        $this->actingAsSuperAdmin();

        [$archived] = $this->clashingPair();

        $ranges = $this->ranges($archived);

        $this->assertSame('available', $ranges[0]['state']);
        $this->assertNull($ranges[0]['conflict']);

        $this->assertSame('conflict', $ranges[1]['state']);
        $this->assertSame('Schedule Conflict', $ranges[1]['state_label']);

        // Who and what, as the answer to "why" rather than as the subject.
        $this->assertSame(['John Doe'], $ranges[1]['conflict']['technicians']);
        $this->assertSame(['PRJ-20260801-00005'], $ranges[1]['conflict']['projects']);
    }

    public function test_a_blocked_restore_answers_with_the_whole_schedule(): void
    {
        $this->actingAsSuperAdmin();

        [$archived] = $this->clashingPair();

        $report = $this->restoreAsJson($archived)->assertStatus(409)->json('conflicts');

        $this->assertTrue($report['blocked']);
        $this->assertCount(2, $report['ranges']);
        $this->assertSame('PRJ-20260716-00013', $report['project']['reference_no']);
    }

    public function test_a_blocked_restore_changes_nothing(): void
    {
        $this->actingAsSuperAdmin();

        [$archived] = $this->clashingPair();

        $this->restoreAsJson($archived)->assertStatus(409);

        $stillArchived = $archived->fresh();

        $this->assertTrue($stillArchived->is_archived);
        $this->assertSame('archived', $stillArchived->status);
        $this->assertSame('ongoing', $stillArchived->pre_archive_status);
        $this->assertSame(2, Schedule::where('project_id', $archived->project_id)->count());
    }

    // ==================================================================
    // Ranges that have already ended
    // ==================================================================

    /**
     * The specification's own example: a range that finished before today is
     * shown as history, is never screened, and cannot hold a restore up -
     * even when other work is booked squarely over it.
     */
    public function test_a_past_range_is_history_and_never_blocks_the_restore(): void
    {
        $this->actingAsSuperAdmin();

        $john = $this->technician('John Doe');

        $archived = $this->project([$john], 'ongoing', 'PRJ-20260716-00013');
        $this->book($archived, -15, -13);
        $this->book($archived, 20, 22);
        $archived = $this->archive($archived);

        // Booked over the days the archived project already worked. Nothing
        // about that is a reason to refuse a restore.
        $live = $this->project([$john], 'ongoing', 'PRJ-20260801-00005');
        $this->book($live, -15, -13);

        $report = $this->report($archived);

        $this->assertFalse($report['blocked']);

        $past = $report['ranges'][0];

        $this->assertSame('past', $past['state']);
        $this->assertSame('Past', $past['state_label']);
        $this->assertTrue($past['past']);
        $this->assertNull($past['conflict']);

        // Visible, and read-only.
        $this->assertFalse($past['editable']);
        $this->assertFalse($past['removable']);

        $this->restoreAsJson($archived)->assertOk();
    }

    public function test_a_past_range_cannot_be_edited(): void
    {
        $this->actingAsSuperAdmin();

        $john = $this->technician('John Doe');

        $archived = $this->project([$john], 'ongoing', 'PRJ-PAST-1');
        $past = $this->book($archived, -15, -13);
        $this->book($archived, 20, 22);
        $archived = $this->archive($archived);

        $this->putJson(route('super-admin.projects.restore-schedule', $archived->project_id), [
            'schedule_id' => $past->schedule_id,
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => $this->day(40)->toDateString(),
            'end_date' => $this->day(42)->toDateString(),
        ])->assertStatus(422);

        $kept = $past->fresh();

        $this->assertSame($this->day(-15)->toDateString(), $kept->startsOn()->toDateString());
        $this->assertSame($this->day(-13)->toDateString(), $kept->endsOn()->toDateString());
    }

    public function test_a_past_range_cannot_be_removed(): void
    {
        $this->actingAsSuperAdmin();

        $john = $this->technician('John Doe');

        $archived = $this->project([$john], 'ongoing', 'PRJ-PAST-2');
        $past = $this->book($archived, -15, -13);
        $archived = $this->archive($archived);

        $this->putJson(route('super-admin.projects.restore-schedule', $archived->project_id), [
            'schedule_id' => $past->schedule_id,
            'action' => 'remove',
        ])->assertStatus(422);

        $this->assertNotNull(Schedule::find($past->schedule_id));
    }

    // ==================================================================
    // Resolving, by moving this project's own range
    // ==================================================================

    /**
     * The specification's worked example: the conflicting range is moved to
     * days the team is free for, the schedule comes back clean, and the
     * restore goes through - with the other project untouched.
     */
    public function test_moving_the_conflicting_range_resolves_the_restore(): void
    {
        $this->actingAsSuperAdmin();

        [$archived, $live] = $this->clashingPair();

        $ranges = $this->restoreAsJson($archived)->assertStatus(409)->json('conflicts.ranges');
        $conflicting = $ranges[1];

        $updated = $this->putJson(route('super-admin.projects.restore-schedule', $archived->project_id), [
            'schedule_id' => $conflicting['schedule_id'],
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => $this->day(30)->toDateString(),
            'end_date' => $this->day(32)->toDateString(),
        ])->assertOk()->json();

        // The whole schedule comes back, rescreened, with both ranges intact.
        $this->assertFalse($updated['blocked']);
        $this->assertCount(2, $updated['ranges']);
        $this->assertSame('available', $updated['ranges'][0]['state']);
        $this->assertSame('available', $updated['ranges'][1]['state']);

        // The untouched range kept its dates: resolving one range never moves
        // another.
        $this->assertSame(
            $this->day(10)->toDateString(),
            $updated['ranges'][0]['start_date']
        );

        $this->restoreAsJson($archived)->assertOk();
        $this->assertFalse((bool) $archived->fresh()->is_archived);

        // And the live project it clashed with was never touched.
        $other = Schedule::where('project_id', $live->project_id)->first();
        $this->assertSame($this->day(21)->toDateString(), $other->startsOn()->toDateString());
        $this->assertSame(1, ProjectTechnician::where('project_id', $live->project_id)->count());
    }

    public function test_removing_the_conflicting_range_resolves_the_restore(): void
    {
        $this->actingAsSuperAdmin();

        [$archived] = $this->clashingPair();

        $conflicting = $this->ranges($archived)[1];

        $updated = $this->putJson(route('super-admin.projects.restore-schedule', $archived->project_id), [
            'schedule_id' => $conflicting['schedule_id'],
            'action' => 'remove',
        ])->assertOk()->json();

        $this->assertFalse($updated['blocked']);
        $this->assertCount(1, $updated['ranges']);

        $this->restoreAsJson($archived)->assertOk();
    }

    /**
     * The editor is not a way around availability: the same service that found
     * the clash is asked again about whatever is submitted.
     */
    public function test_the_editor_refuses_a_range_the_team_is_not_free_for(): void
    {
        $this->actingAsSuperAdmin();

        [$archived] = $this->clashingPair();

        $conflicting = $this->ranges($archived)[1];

        // Straight onto the days the other project holds.
        $this->putJson(route('super-admin.projects.restore-schedule', $archived->project_id), [
            'schedule_id' => $conflicting['schedule_id'],
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => $this->day(23)->toDateString(),
            'end_date' => $this->day(24)->toDateString(),
        ])->assertStatus(422);

        $this->assertTrue($archived->fresh()->is_archived);
    }

    public function test_the_editor_refuses_past_dates(): void
    {
        $this->actingAsSuperAdmin();

        [$archived] = $this->clashingPair();

        $conflicting = $this->ranges($archived)[1];

        $this->putJson(route('super-admin.projects.restore-schedule', $archived->project_id), [
            'schedule_id' => $conflicting['schedule_id'],
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => $this->day(-5)->toDateString(),
            'end_date' => $this->day(-3)->toDateString(),
        ])->assertStatus(422);
    }

    /**
     * And it may not be moved onto the days the project's own other range
     * holds - the same self-overlap rule every other scheduling screen keeps.
     */
    public function test_the_editor_refuses_a_range_that_overlaps_the_projects_own_dates(): void
    {
        $this->actingAsSuperAdmin();

        [$archived] = $this->clashingPair();

        $conflicting = $this->ranges($archived)[1];

        $this->putJson(route('super-admin.projects.restore-schedule', $archived->project_id), [
            'schedule_id' => $conflicting['schedule_id'],
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => $this->day(11)->toDateString(),
            'end_date' => $this->day(13)->toDateString(),
        ])->assertStatus(422);
    }

    // ==================================================================
    // The date picker's rules
    // ==================================================================

    /**
     * A day a picker greys out has to be a day the save refuses, so the two
     * are read from the same place: the team's other bookings, the project's
     * own other ranges, and nothing before today.
     */
    public function test_the_picker_greys_out_what_the_save_would_refuse(): void
    {
        $this->actingAsSuperAdmin();

        [$archived] = $this->clashingPair();

        $ranges = $this->ranges($archived);
        $blocked = $ranges[1]['blocked_dates']['whole_day'];

        // The other project's days.
        $this->assertContains($this->day(23)->toDateString(), $blocked);

        // This project's own other range.
        $this->assertContains($this->day(10)->toDateString(), $blocked);

        // Its own days stay open, so the range can be shortened or nudged.
        $this->assertNotContains($this->day(20)->toDateString(), $blocked);

        // A day nobody has a claim on stays open.
        $this->assertNotContains($this->day(40)->toDateString(), $blocked);

        // And the floor the picker uses is today, never earlier.
        $this->assertSame(Schedule::businessToday()->toDateString(), $ranges[1]['earliest_start']);
        $this->assertSame(Schedule::businessToday()->toDateString(), $this->report($archived)['earliest_date']);
    }

    // ==================================================================
    // The server has the last word
    // ==================================================================

    public function test_a_conflict_that_appears_after_the_report_still_blocks_the_restore(): void
    {
        $this->actingAsSuperAdmin();

        $john = $this->technician('John Doe');

        $archived = $this->project([$john], 'ongoing', 'PRJ-20260716-00013');
        $this->book($archived, 10, 20);
        $archived = $this->archive($archived);

        $this->getJson(route('super-admin.projects.restore-conflicts', $archived->project_id))
            ->assertOk()
            ->assertJsonPath('blocked', false);

        // Somebody else books the same person while the dialog sits open.
        $late = $this->project([$john], 'ongoing', 'PRJ-LATE-1');
        $this->book($late, 12, 14);

        $this->restoreAsJson($archived)
            ->assertStatus(409)
            ->assertJsonPath('conflicts.blocked', true);

        $this->assertTrue($archived->fresh()->is_archived);
    }

    /**
     * A completed or cancelled record holds nobody either way, so there is
     * nothing for it to collide with and nothing to fix.
     */
    public function test_a_record_that_claims_no_dates_is_never_blocked(): void
    {
        $this->actingAsSuperAdmin();

        $john = $this->technician('John Doe');

        $archived = $this->project([$john], 'completed', 'PRJ-DONE-9');
        $this->book($archived, 10, 20);
        $archived = $this->archive($archived);

        $live = $this->project([$john], 'ongoing', 'PRJ-LIVE-9');
        $this->book($live, 15, 25);

        $report = $this->report($archived);

        $this->assertFalse($report['blocked']);
        $this->assertSame('available', $report['ranges'][0]['state']);

        $this->restoreAsJson($archived)->assertOk();
    }

    // ==================================================================
    // The pages
    // ==================================================================

    /**
     * The confirmation asks the question and stops. What used to follow it -
     * a paragraph about what archiving keeps, and a warning that a restore may
     * be refused - was answering questions nobody had asked yet, and the one
     * that mattered is answered properly by the dialog instead.
     */
    public function test_the_archive_page_asks_a_short_question_and_carries_the_conflict_dialog(): void
    {
        $this->actingAsSuperAdmin();

        [$archived] = $this->clashingPair();

        $response = $this->get(route('super-admin.projects.archived'))->assertOk();

        $response->assertSee('Restore PRJ-20260716-00013?', false);
        $response->assertSee('Schedule Conflict', false);
        $response->assertSee('Recheck availability', false);
        // The dialog is shared with Resume now, so what it calls itself and
        // what its button says arrive with the report rather than being
        // rendered here - see ProjectScheduleRecovery::flowPayload(). What the
        // page still has to carry is the empty dialog and the two endpoints
        // that fill it.
        $response->assertSee('data-conflict-modal', false);
        $response->assertSee('data-conflict-commit', false);
        $response->assertSee(
            route('super-admin.projects.restore-conflicts', $archived->project_id),
            false
        );

        $this->assertStringContainsString(
            'Review the affected schedule ranges before restoring the project.',
            $this->report($archived)['flow']['blocked_summary']
        );
    }

    public function test_the_projects_page_asks_a_short_archive_question(): void
    {
        $this->actingAsSuperAdmin();

        $this->project([$this->technician('Ana Mendoza')], 'ongoing', 'PRJ-20260731-00016');

        $this->get(route('super-admin.projects'))
            ->assertOk()
            ->assertSee('Archive PRJ-20260731-00016?', false);
    }

    // ==================================================================
    // Authorization
    // ==================================================================

    public function test_the_conflict_report_is_the_super_admins_alone(): void
    {
        $admin = User::factory()->create(['email' => 'the.admin@example.test']);
        $admin->forceFill(['role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE])->save();

        $this->actingAsSuperAdmin();

        [$archived] = $this->clashingPair();

        $this->actingAs($admin)
            ->getJson(route('super-admin.projects.restore-conflicts', $archived->project_id))
            ->assertStatus(403);
    }

    public function test_the_schedule_editor_is_the_super_admins_alone(): void
    {
        $admin = User::factory()->create(['email' => 'the.admin@example.test']);
        $admin->forceFill(['role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE])->save();

        $this->actingAsSuperAdmin();

        [$archived] = $this->clashingPair();
        $conflicting = $this->ranges($archived)[1];

        $this->actingAs($admin)
            ->putJson(route('super-admin.projects.restore-schedule', $archived->project_id), [
                'schedule_id' => $conflicting['schedule_id'],
                'action' => 'remove',
            ])
            ->assertStatus(403);

        $this->assertNotNull(Schedule::find($conflicting['schedule_id']));
    }

    /**
     * And the check cannot be walked around by posting the restore directly:
     * the endpoint runs it itself, whatever any dialog did or did not show.
     */
    public function test_the_restore_endpoint_refuses_a_direct_request_that_would_double_book(): void
    {
        $this->actingAsSuperAdmin();

        [$archived] = $this->clashingPair();

        $this->put(route('super-admin.projects.restore', $archived->project_id))
            ->assertRedirect(route('super-admin.projects.archived'))
            ->assertSessionHas('error');

        $this->assertTrue($archived->fresh()->is_archived);
    }
}
