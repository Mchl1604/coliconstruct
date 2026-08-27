<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Technician;
use App\Models\User;
use App\Services\ProjectScheduleRecovery;
use App\Services\ProjectStatusRules;
use App\Services\TechnicianAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Resume, modelled as a scheduling recovery rather than as a flag being
 * cleared.
 *
 * A hold does not throw the remaining work away. It keeps the project's future
 * ranges as its PLANNED schedule and stops them counting against anybody, so
 * the crew is free for other work while the project is paused. Resume is then
 * the question that has to be asked before those days go back into force:
 *
 *     On Hold -> preserve future ranges -> release technicians
 *             -> Resume -> recheck conflicts -> resolve
 *             -> reactivate schedule -> set the correct status
 *
 * The same sequence, and the same dialog, as restoring an archived project -
 * see ProjectScheduleRecovery, which is the one service both go through.
 *
 * The status sweep is the other half of it. A held project keeps schedule
 * rows now, and a sweep that read those rows as work in progress would put the
 * project back to Ongoing behind the administrator's back - releasing nothing,
 * booking the crew again, and leaving Resume with nothing to check. The guard
 * for that lives in ProjectStatusRules, where every screen reaches it, rather
 * than in whichever page happened to notice.
 */
class ProjectHoldResumeRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private static int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
    }

    // ==================================================================
    // Fixtures
    // ==================================================================

    private function technician(string $name): Technician
    {
        $sequence = ++self::$sequence;

        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'.'.$sequence.'@example.test',
        ]);

        $user->forceFill(['role' => 'technician'])->save();

        return Technician::create(['account_id' => $user->id, 'role' => 'technician']);
    }

    /**
     * @param  array<int, Technician>  $technicians
     */
    private function project(array $technicians, string $clientType = 'Residential'): Project
    {
        $sequence = ++self::$sequence;

        $project = Project::create([
            'name' => 'Hold Project '.$sequence,
            'reference_no' => sprintf('PRJ-HOLD-%05d', $sequence),
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 250000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => $clientType,
            'company_name' => $clientType === 'Commercial' ? 'Some Holdings' : null,
            'firstname' => 'Client',
            'surname' => 'One',
            'fullname' => 'Client One',
            'email_address' => 'hold.client'.$sequence.'@example.test',
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

    private function hold(Project $project): Project
    {
        $this->put(route('super-admin.projects.hold', $project->project_id))
            ->assertSessionHasNoErrors();

        return $project->fresh();
    }

    /**
     * @return array<int, string>
     */
    private function ranges(Project $project): array
    {
        return Schedule::where('project_id', $project->project_id)
            ->get()
            ->map(fn (Schedule $schedule): string => $schedule->startsOn()->toDateString()
                .'|'.$schedule->endsOn()->toDateString())
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Whether the given technicians read as occupied over a window, asked of
     * the one service every booking screen asks.
     *
     * @param  array<int, Technician>  $technicians
     */
    private function isBusy(array $technicians, int $from, int $to): bool
    {
        return app(TechnicianAvailabilityService::class)->findConflicts(
            collect($technicians)->map(fn (Technician $t): int => (int) $t->technician_id),
            [['start' => $this->day($from), 'end' => $this->day($to)]]
        )->isNotEmpty();
    }

    /**
     * The same question, with one project's own bookings left out - so "is
     * THIS project holding them?" can be asked apart from "is anybody?".
     *
     * @param  array<int, Technician>  $technicians
     */
    private function isBusyIgnoring(array $technicians, Project $ignored, int $from, int $to): bool
    {
        return app(TechnicianAvailabilityService::class)->findConflicts(
            collect($technicians)->map(fn (Technician $t): int => (int) $t->technician_id),
            [['start' => $this->day($from), 'end' => $this->day($to)]],
            (int) $ignored->project_id
        )->isNotEmpty();
    }

    /**
     * @return array<string, mixed>
     */
    private function resumeReport(Project $project): array
    {
        return $this->getJson(route('super-admin.projects.resume-conflicts', $project->project_id))
            ->assertOk()
            ->json();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolve(Project $project, array $payload)
    {
        return $this->putJson(route('super-admin.projects.resume-schedule', $project->project_id), $payload);
    }

    // ==================================================================
    // 1-4. Putting a project with past and future dates on hold
    // ==================================================================

    public function test_a_hold_keeps_history_preserves_the_plan_and_frees_the_crew(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $ben = $this->technician('Ben Cruz');

        $project = $this->project([$ana, $ben]);
        $this->book($project, -8, -6);   // history
        $this->book($project, 10, 12);   // planned
        $this->book($project, 20, 22);   // planned

        // Booked, and holding the crew, before the hold.
        $this->assertTrue($this->isBusy([$ana], 10, 12));

        $project = $this->hold($project);

        // 2. The past is still there, untouched.
        // 3. And so is every future range.
        $this->assertSame([
            $this->day(-8)->toDateString().'|'.$this->day(-6)->toDateString(),
            $this->day(10)->toDateString().'|'.$this->day(12)->toDateString(),
            $this->day(20)->toDateString().'|'.$this->day(22)->toDateString(),
        ], $this->ranges($project));

        // The preserved ranges are still attached to the project's crew, which
        // is what makes them checkable on the way back in.
        $this->assertSame(6, ScheduleTechnician::query()->count());

        // 4. And neither technician is occupied by any of it any more.
        $this->assertFalse($this->isBusy([$ana, $ben], 10, 12));
        $this->assertFalse($this->isBusy([$ana, $ben], 20, 22));

        $this->assertTrue((bool) $project->on_hold);
        $this->assertSame('On Hold', $project->statusLabel());
    }

    // ==================================================================
    // 5, 15. The status sweep leaves a held project alone
    // ==================================================================

    /**
     * Both shapes of preserved schedule, because they imply different statuses
     * and the sweep must write neither: a project whose planned dates are
     * still ahead would be Pending, and one whose dates have arrived would be
     * Ongoing.
     */
    public function test_the_status_sweep_never_promotes_a_held_project(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $ben = $this->technician('Ben Cruz');

        $wouldBePending = $this->project([$ana]);
        $this->book($wouldBePending, 10, 12);

        $wouldBeOngoing = $this->project([$ben]);
        $this->book($wouldBeOngoing, -1, 12);

        $this->hold($wouldBePending);
        $this->hold($wouldBeOngoing);

        // Nothing but a page load, which is what runs the sweep.
        $this->get(route('super-admin.projects'))->assertOk();
        $this->get(route('super-admin.projects'))->assertOk();

        foreach ([$wouldBePending, $wouldBeOngoing] as $project) {
            $held = $project->fresh();

            $this->assertTrue((bool) $held->on_hold);
            $this->assertSame('unscheduled', $held->status);
            $this->assertSame('On Hold', $held->statusLabel());
        }

        // Asked of the shared rule directly as well, so the guard is pinned
        // where every screen reaches it rather than on one page.
        $rules = app(ProjectStatusRules::class);

        $this->assertNull($rules->statusFor($wouldBePending->fresh()));
        $this->assertNull($rules->statusFor($wouldBeOngoing->fresh()));
        $this->assertFalse($rules->apply($wouldBeOngoing->fresh()));

        // And the crew is still free, because nothing put the project back
        // into an active status behind the administrator.
        $this->assertFalse($this->isBusy([$ana], 10, 12));
        $this->assertFalse($this->isBusy([$ben], 0, 12));
    }

    // ==================================================================
    // 6, 11, 16. Resuming with nothing in the way
    // ==================================================================

    public function test_resuming_without_conflicts_reactivates_the_schedule(): void
    {
        $ana = $this->technician('Ana Mendoza');

        // Nothing behind it, so the status the dates imply is unambiguous.
        $project = $this->project([$ana]);
        $this->book($project, 10, 12);

        $project = $this->hold($project);

        $this->assertFalse($this->resumeReport($project)['blocked']);
        $this->assertFalse($this->isBusy([$ana], 10, 12), 'Still free while the hold stands.');

        $this->putJson(route('super-admin.projects.resume', $project->project_id))->assertOk();

        $resumed = $project->fresh();

        $this->assertFalse((bool) $resumed->on_hold);
        // 16. Booked, not started.
        $this->assertSame('pending', $resumed->status);

        // 11. And only now is the crew booked again.
        $this->assertTrue($this->isBusy([$ana], 10, 12));
    }

    /**
     * A project with days behind it AND days ahead comes back Ongoing, not
     * Pending: work on it has started, which is exactly what Ongoing means and
     * what Pending does not.
     *
     * The status is read off the earliest day the project holds across its
     * whole schedule, history included - the single answer ProjectStatusRules
     * gives every screen. Resume deliberately does not reach a different one
     * from the future ranges alone, because a project running from last week
     * into next week has no range starting in the future at all and would come
     * back reading as Unscheduled.
     */
    public function test_a_project_with_history_and_a_plan_resumes_as_ongoing(): void
    {
        $ana = $this->technician('Ana Mendoza');

        $project = $this->project([$ana]);
        $this->book($project, -8, -6);
        $this->book($project, 10, 12);

        $project = $this->hold($project);

        $this->putJson(route('super-admin.projects.resume', $project->project_id))->assertOk();

        $resumed = $project->fresh();

        $this->assertSame('ongoing', $resumed->status);
        $this->assertSame('Ongoing', $resumed->statusLabel());
        $this->assertFalse($resumed->isOverdue(), 'It still has days ahead of it.');

        // The planned range is back in force, which is the point of the
        // resume. The ended one keeps its rows too, deliberately - that is the
        // record of a week Ana was on site for, and every availability
        // question the application actually asks is about today or later.
        $this->assertTrue($this->isBusy([$ana], 10, 12));
        $this->assertFalse(
            $this->isBusy([$ana], 0, 9),
            'The days between the history and the plan are free.'
        );
    }

    /**
     * 16. A project left holding nothing ahead of it does not come back as
     * work in progress.
     */
    public function test_resuming_with_no_future_schedule_does_not_read_as_ongoing(): void
    {
        $ana = $this->technician('Ana Mendoza');

        $nothingAtAll = $this->project([$ana]);
        $this->hold($nothingAtAll);
        $this->putJson(route('super-admin.projects.resume', $nothingAtAll->project_id))->assertOk();

        $this->assertSame('unscheduled', $nothingAtAll->fresh()->status);
        $this->assertSame('Unscheduled', $nothingAtAll->fresh()->statusLabel());

        // Only history behind it: Overdue, which is Ongoing with nothing left
        // to reach - see ProjectStatusRules. Not "under way".
        $ben = $this->technician('Ben Cruz');
        $onlyHistory = $this->project([$ben]);
        $this->book($onlyHistory, -8, -6);
        $this->hold($onlyHistory);
        $this->putJson(route('super-admin.projects.resume', $onlyHistory->project_id))->assertOk();

        $this->assertTrue($onlyHistory->fresh()->isOverdue());
        $this->assertSame('Overdue', $onlyHistory->fresh()->statusLabel());
        $this->assertFalse($this->isBusy([$ben], 0, 10), 'Days already worked are not a booking.');
    }

    // ==================================================================
    // 7, 8, 9, 10, 13. Resuming into conflicts
    // ==================================================================

    /**
     * The whole schedule is reported, not just the range in the way, and not
     * the technician's diary: three ranges, one of them highlighted.
     */
    public function test_the_report_shows_every_range_and_marks_the_conflicting_ones(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $ben = $this->technician('Ben Cruz');

        $project = $this->project([$ana, $ben]);
        $history = $this->book($project, -8, -6);
        $clear = $this->book($project, 10, 12);
        $firstClash = $this->book($project, 20, 22);
        $secondClash = $this->book($project, 30, 32);

        $project = $this->hold($project);

        // Two other projects took two of the planned ranges while it paused,
        // one from each technician.
        $this->book($this->project([$ana], 'Commercial'), 20, 22);
        $this->book($this->project([$ben], 'Commercial'), 30, 32);

        $report = $this->resumeReport($project);

        $this->assertTrue($report['blocked']);
        $this->assertCount(4, $report['ranges'], 'Every range is shown, not only the ones in the way.');

        $states = collect($report['ranges'])->pluck('state', 'schedule_id');

        $this->assertSame('past', $states[$history->schedule_id]);
        $this->assertSame('available', $states[$clear->schedule_id]);
        $this->assertSame('conflict', $states[$firstClash->schedule_id]);
        $this->assertSame('conflict', $states[$secondClash->schedule_id]);

        // 8. Each conflicting range names the technician causing it, and the
        // work that has them.
        $byId = collect($report['ranges'])->keyBy('schedule_id');

        $this->assertSame(['Ana Mendoza'], $byId[$firstClash->schedule_id]['conflict']['technicians']);
        $this->assertSame(['Ben Cruz'], $byId[$secondClash->schedule_id]['conflict']['technicians']);
        $this->assertNotEmpty($byId[$firstClash->schedule_id]['conflict']['projects']);

        // 13. The past range is shown as history: no verdict, and nothing on
        // it to change.
        $this->assertFalse($byId[$history->schedule_id]['editable']);
        $this->assertFalse($byId[$history->schedule_id]['removable']);
        $this->assertNull($byId[$history->schedule_id]['conflict']);

        $this->resolve($project, [
            'schedule_id' => $history->schedule_id,
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => $this->day(40)->toDateString(),
            'end_date' => $this->day(41)->toDateString(),
        ])->assertStatus(422);

        $this->assertSame($this->day(-8)->toDateString(), $history->refresh()->startsOn()->toDateString());
    }

    /**
     * 9, 14. Resolving one of two conflicts leaves the other standing, and the
     * resume is refused until both are gone.
     */
    public function test_every_conflict_must_be_resolved_before_the_project_resumes(): void
    {
        $ana = $this->technician('Ana Mendoza');
        $ben = $this->technician('Ben Cruz');

        $project = $this->project([$ana, $ben]);
        $first = $this->book($project, 20, 22);
        $second = $this->book($project, 30, 32);

        $project = $this->hold($project);

        $blockingAna = $this->project([$ana], 'Commercial');
        $this->book($blockingAna, 20, 22);
        $blockingBen = $this->project([$ben], 'Commercial');
        $this->book($blockingBen, 30, 32);

        // Refused, and nothing about the project changed on the way to saying
        // so - the preserved ranges are exactly where they were.
        $this->putJson(route('super-admin.projects.resume', $project->project_id))
            ->assertStatus(409)
            ->assertJsonPath('conflicts.blocked', true);

        $this->assertTrue((bool) $project->fresh()->on_hold);
        $this->assertSame($this->day(20)->toDateString(), $first->refresh()->startsOn()->toDateString());
        $this->assertSame($this->day(30)->toDateString(), $second->refresh()->startsOn()->toDateString());

        // One resolved. The other is still reported, and the resume is still
        // refused.
        $afterFirst = $this->resolve($project, [
            'schedule_id' => $first->schedule_id,
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => $this->day(50)->toDateString(),
            'end_date' => $this->day(52)->toDateString(),
        ])->assertOk()->json();

        $this->assertTrue($afterFirst['blocked']);

        $states = collect($afterFirst['ranges'])->pluck('state', 'schedule_id');

        $this->assertSame('available', $states[$first->schedule_id]);
        $this->assertSame('conflict', $states[$second->schedule_id]);

        $this->putJson(route('super-admin.projects.resume', $project->project_id))->assertStatus(409);
        $this->assertTrue((bool) $project->fresh()->on_hold);

        // Ben is spoken for on those days by the OTHER project, which is the
        // clash. What matters here is that the held one is still not holding
        // him: leave the other project out and nothing is left.
        $this->assertFalse(
            $this->isBusyIgnoring([$ben], $blockingBen, 30, 32),
            'The held project must not reactivate its crew on a conflicting range.'
        );

        // 10. Both resolved: the project resumes.
        $afterSecond = $this->resolve($project, [
            'schedule_id' => $second->schedule_id,
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => $this->day(60)->toDateString(),
            'end_date' => $this->day(62)->toDateString(),
        ])->assertOk()->json();

        $this->assertFalse($afterSecond['blocked']);

        $this->putJson(route('super-admin.projects.resume', $project->project_id))->assertOk();

        $resumed = $project->fresh();

        $this->assertFalse((bool) $resumed->on_hold);
        $this->assertSame('pending', $resumed->status);

        // 15. And the crew is booked again on the ranges that came back.
        $this->assertTrue($this->isBusy([$ana], 50, 52));
        $this->assertTrue($this->isBusy([$ben], 60, 62));
    }

    /**
     * 12. A Residential project may resolve a clash by booking hours instead,
     * using the window from Project Settings.
     */
    public function test_a_residential_project_resolves_a_conflict_with_a_partial_day(): void
    {
        $ana = $this->technician('Ana Mendoza');

        $project = $this->project([$ana], 'Residential');
        $clashing = $this->book($project, 20, 22);

        $project = $this->hold($project);

        // The other work holds the morning of day 20 only.
        $other = $this->project([$ana], 'Commercial');
        Schedule::create([
            'project_id' => $other->project_id,
            'start_datetime' => $this->day(20)->setTime(8, 0),
            'end_datetime' => $this->day(20)->setTime(12, 0),
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'status' => 'scheduled',
        ])->scheduleTechnicians()->create([
            'project_technician_id' => ProjectTechnician::where('project_id', $other->project_id)
                ->value('project_technician_id'),
        ]);

        $report = $this->resumeReport($project);

        $this->assertTrue($report['blocked']);
        $this->assertTrue($report['project']['partial_day_allowed']);
        $this->assertSame('8:00 AM', $report['project']['partial_day_window']['start_label']);
        $this->assertSame('5:00 PM', $report['project']['partial_day_window']['end_label']);

        // The afternoon of the very same date is free, and hours are what make
        // that reachable at all.
        $this->resolve($project, [
            'schedule_id' => $clashing->schedule_id,
            'action' => 'update',
            'scheduling_mode' => Schedule::MODE_PARTIAL_DAY,
            'project_date' => $this->day(20)->toDateString(),
            'start_time' => '13:00',
            'end_time' => '17:00',
        ])->assertOk()->assertJsonPath('blocked', false);

        $this->putJson(route('super-admin.projects.resume', $project->project_id))->assertOk();

        $clashing->refresh();

        $this->assertTrue($clashing->isPartialDay());
        $this->assertSame('1:00 PM - 5:00 PM', $clashing->timeRange());
        $this->assertFalse((bool) $project->fresh()->on_hold);
    }

    // ==================================================================
    // 14. Resume is refused when the project is not on hold
    // ==================================================================

    public function test_resume_is_refused_when_the_project_is_not_on_hold(): void
    {
        $ana = $this->technician('Ana Mendoza');

        $project = $this->project([$ana]);
        $this->book($project, 10, 12);

        $this->put(route('super-admin.projects.resume', $project->project_id))
            ->assertSessionHas('error', 'This project is not on hold.');

        $this->putJson(route('super-admin.projects.resume', $project->project_id))
            ->assertStatus(422)
            ->assertJsonPath('error', 'This project is not on hold.');

        $this->assertSame('ongoing', $project->fresh()->status);

        // And neither screening endpoint will discuss it either.
        $this->getJson(route('super-admin.projects.resume-conflicts', $project->project_id))
            ->assertStatus(422)
            ->assertJsonPath('error', 'This project is not on hold.');
    }

    /**
     * The flow the dialog is told it is in, so it posts at the resume
     * endpoints and calls itself by the resume's names.
     */
    public function test_the_resume_report_is_the_resume_flow(): void
    {
        $ana = $this->technician('Ana Mendoza');

        $project = $this->project([$ana]);
        $this->book($project, 10, 12);
        $project = $this->hold($project);

        $report = $this->resumeReport($project);

        $this->assertSame(ProjectScheduleRecovery::FLOW_RESUME, $report['flow']['key']);
        $this->assertSame('Resume Project', $report['flow']['action_label']);
        $this->assertSame('Resuming Project', $report['project']['heading']);
        $this->assertTrue($report['project']['claims_dates']);
    }
}
