<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use App\Services\TechnicianAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Archiving a project puts it to one side. It does not take it apart.
 *
 * It used to: archiving deleted the schedule rows, the schedule-technician
 * links and the project-technician assignments, and blanked the dates on every
 * unfinished task. A project came back from the archive as an empty shell, and
 * "restore" could only ever mean "start again" - which is why it dropped
 * everything to Unscheduled whatever it had been.
 *
 * Two facts have to hold at once now, and they pull in opposite directions:
 *
 *   - The project keeps everything. Its dates, its crew and its tasks are the
 *     record of what it was, and an archive missing them records half a thing.
 *
 *   - Its technicians are free. Keeping the dates must not keep the people:
 *     the archive is not work anybody still owes, so those days have to read
 *     as open for other projects.
 *
 * The system already had the second one for free, and had it long before this:
 * availability counts Pending and Ongoing work only, so a cancelled project's
 * schedule has never blocked anybody and cancel() has never deleted a row.
 * Archive now works the same way.
 *
 * The cost of keeping the dates is paid at the other end. While a project sits
 * in the archive its days are genuinely free, so somebody may book its crew
 * over them - and putting the project back into force would then double-book a
 * person with nobody told. So a restore that would claim dates again asks the
 * same question Resume asks, of the same service, before it writes anything.
 */
class ProjectArchiveRestoreTest extends TestCase
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
    private function project(
        array $technicians = [],
        string $status = 'ongoing',
        ?string $reference = null
    ): Project {
        $project = Project::create([
            'name' => 'Archive Project',
            'reference_no' => $reference ?? 'REF-'.strtoupper(substr(md5(uniqid('', true)), 0, 8)),
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

    /**
     * One booking, with every one of the project's crew linked to it - which
     * is the row availability actually reads.
     */
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

    private function task(Project $project, Technician $technician): Task
    {
        return Task::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
            'task_title' => 'Pull the wiring',
            'task_description' => 'Description',
            'start_date' => $this->day(10)->toDateString(),
            'due_date' => $this->day(12)->toDateString(),
            'status' => 'ongoing',
        ]);
    }

    /**
     * Whether this technician reads as free for the window, asked of the one
     * service every scheduling screen asks.
     */
    private function isFree(Technician $technician, int $startOffset, int $endOffset): bool
    {
        return app(TechnicianAvailabilityService::class)
            ->findConflicts(
                [$technician->technician_id],
                [['start' => $this->day($startOffset), 'end' => $this->day($endOffset)]]
            )
            ->isEmpty();
    }

    private function archive(Project $project): Project
    {
        $this->post(route('super-admin.projects.archive', $project->project_id))
            ->assertSessionHasNoErrors();

        return $project->fresh();
    }

    private function restore(Project $project): TestResponse
    {
        return $this->put(route('super-admin.projects.restore', $project->project_id));
    }

    // ==================================================================
    // Archiving preserves
    // ==================================================================

    /**
     * The headline case from the specification, on a Completed project.
     */
    public function test_archiving_a_completed_project_keeps_its_schedule_team_and_dates(): void
    {
        $this->actingAsSuperAdmin();

        $ana = $this->technician('Ana Mendoza');
        $ben = $this->technician('Ben Okoro');

        $project = $this->project([$ana, $ben], 'completed');
        $schedule = $this->book($project, 10, 15);
        $task = $this->task($project, $ana);

        $archived = $this->archive($project);

        $this->assertSame('archived', $archived->status);
        $this->assertTrue($archived->is_archived);
        $this->assertNotNull($archived->archived_at);
        $this->assertNotNull($archived->archived_by);

        // The booking is still there, to the day.
        $kept = Schedule::find($schedule->schedule_id);
        $this->assertNotNull($kept);
        $this->assertSame($this->day(10)->toDateString(), $kept->startsOn()->toDateString());
        $this->assertSame($this->day(15)->toDateString(), $kept->endsOn()->toDateString());

        // So is the team, and the links that put them on those dates.
        $this->assertSame(2, ProjectTechnician::where('project_id', $project->project_id)->count());
        $this->assertSame(2, ScheduleTechnician::where('schedule_id', $schedule->schedule_id)->count());

        // And the task keeps its technician, its status and its dates - the
        // old flow blanked all three to let go of a technician it had already
        // let go of by deleting the assignment.
        $task->refresh();
        $this->assertSame((int) $ana->technician_id, (int) $task->technician_id);
        $this->assertSame('ongoing', $task->status);
        $this->assertSame($this->day(10)->toDateString(), CarbonImmutable::parse($task->start_date)->toDateString());
    }

    public function test_archiving_a_cancelled_project_keeps_its_schedule_and_team(): void
    {
        $this->actingAsSuperAdmin();

        $ana = $this->technician('Ana Mendoza');
        $project = $this->project([$ana], 'cancelled');
        $this->book($project, 10, 15);

        $archived = $this->archive($project);

        $this->assertSame('archived', $archived->status);
        $this->assertSame('cancelled', $archived->pre_archive_status);
        $this->assertSame(1, Schedule::where('project_id', $project->project_id)->count());
        $this->assertSame(1, ProjectTechnician::where('project_id', $project->project_id)->count());
    }

    /**
     * A hold is somebody's decision about the work, not archive metadata, so
     * archiving does not quietly end it.
     */
    public function test_archiving_leaves_a_hold_alone(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([$this->technician('Ana Mendoza')], 'unscheduled');
        $project->update(['on_hold' => true]);

        $this->assertTrue($this->archive($project)->on_hold);
    }

    // ==================================================================
    // Archived work occupies nobody
    // ==================================================================

    /**
     * Keeping the schedule must not keep the technician.
     */
    public function test_an_archived_projects_dates_stop_occupying_its_technicians(): void
    {
        $this->actingAsSuperAdmin();

        $ana = $this->technician('Ana Mendoza');
        $project = $this->project([$ana], 'ongoing');
        $this->book($project, 10, 15);

        // Booked while it is live...
        $this->assertFalse($this->isFree($ana, 10, 15));

        $this->archive($project);

        // ...and free the moment it is archived, over the very same days.
        $this->assertTrue($this->isFree($ana, 10, 15));
    }

    /**
     * The same fact as seen by a date picker rather than by the validator.
     *
     * The wizard and the schedules page each build their own busy-days payload
     * for the browser, and both now screen by the two conditions the service
     * screens by. Without that, a project archived while it still read Ongoing
     * would go on greying out days from inside the archive - the picker
     * refusing what the server would accept.
     */
    public function test_archived_schedules_are_left_out_of_the_datepicker_payloads(): void
    {
        $this->actingAsSuperAdmin();

        $ana = $this->technician('Ana Mendoza');
        $project = $this->project([$ana], 'ongoing');
        $this->book($project, 10, 15);

        // The payloads carry each booking's endpoints rather than a row per
        // day, so the range's own start is the date to look for.
        $busyDate = $this->day(10)->toDateString();

        $this->assertStringContainsString(
            $busyDate,
            $this->get(route('super-admin.projects.create'))->assertOk()->getContent()
        );
        $this->assertStringContainsString(
            $busyDate,
            $this->get(route('super-admin.schedules.index'))->assertOk()->getContent()
        );

        $this->archive($project);

        $this->assertStringNotContainsString(
            $busyDate,
            $this->get(route('super-admin.projects.create'))->assertOk()->getContent()
        );
        $this->assertStringNotContainsString(
            $busyDate,
            $this->get(route('super-admin.schedules.index'))->assertOk()->getContent()
        );
    }

    /**
     * The whole point of the exercise, end to end: a second project may book
     * the same person over the archived project's days.
     */
    public function test_another_project_can_book_the_team_over_an_archived_projects_dates(): void
    {
        $this->actingAsSuperAdmin();

        $ana = $this->technician('Ana Mendoza');
        $archived = $this->project([$ana], 'ongoing');
        $this->book($archived, 10, 15);
        $this->archive($archived);

        $live = $this->project([$ana], 'ongoing', 'PRJ-LIVE-1');
        $this->book($live, 10, 15);

        // The live booking stands, and the archived one is not a conflict.
        $this->assertTrue(
            app(TechnicianAvailabilityService::class)
                ->findConflicts(
                    [$ana->technician_id],
                    [['start' => $this->day(10), 'end' => $this->day(15)]],
                    (int) $live->project_id
                )
                ->isEmpty()
        );
    }

    // ==================================================================
    // Restoring returns the project
    // ==================================================================

    public function test_restoring_a_completed_project_returns_it_completed_with_its_schedule_and_team(): void
    {
        $this->actingAsSuperAdmin();

        $ana = $this->technician('Ana Mendoza');
        $ben = $this->technician('Ben Okoro');

        $project = $this->project([$ana, $ben], 'completed');
        $schedule = $this->book($project, 10, 15);

        $this->archive($project);

        $this->restore($project)
            ->assertRedirect(route('super-admin.projects'))
            ->assertSessionHas('success');

        $restored = $project->fresh();

        $this->assertSame('completed', $restored->status);
        $this->assertFalse($restored->is_archived);
        $this->assertNull($restored->archived_at);
        $this->assertNull($restored->archived_by);
        // Consumed: it has been returned, so there is nothing left to return.
        $this->assertNull($restored->pre_archive_status);

        $kept = Schedule::find($schedule->schedule_id);
        $this->assertSame($this->day(10)->toDateString(), $kept->startsOn()->toDateString());
        $this->assertSame($this->day(15)->toDateString(), $kept->endsOn()->toDateString());
        $this->assertSame(2, ProjectTechnician::where('project_id', $project->project_id)->count());
    }

    public function test_restoring_a_cancelled_project_returns_it_cancelled(): void
    {
        $this->actingAsSuperAdmin();

        $ana = $this->technician('Ana Mendoza');
        $project = $this->project([$ana], 'cancelled');
        $this->book($project, 10, 15);

        $this->archive($project);
        $this->restore($project)->assertSessionHas('success');

        $restored = $project->fresh();

        $this->assertSame('cancelled', $restored->status);
        $this->assertSame(1, Schedule::where('project_id', $project->project_id)->count());
        $this->assertSame(1, ProjectTechnician::where('project_id', $project->project_id)->count());
    }

    /**
     * Neither of them is nudged back into the pipeline by coming back.
     */
    public function test_a_restored_record_is_never_turned_into_unscheduled(): void
    {
        $this->actingAsSuperAdmin();

        foreach (['completed', 'cancelled'] as $index => $status) {
            $project = $this->project(
                [$this->technician('Crew Member'.$index)],
                $status,
                'PRJ-KEEP-'.$index
            );
            $this->book($project, 10, 15);

            $this->archive($project);
            $this->restore($project);

            $this->assertSame($status, $project->fresh()->status);
        }
    }

    /**
     * Work that was under way comes back under way, on the dates it still
     * holds - and the calendar has the last word on which of the three
     * date-derived statuses that is, exactly as it does everywhere else.
     */
    public function test_restoring_live_work_returns_its_schedule_to_force(): void
    {
        $this->actingAsSuperAdmin();

        $ana = $this->technician('Ana Mendoza');
        $project = $this->project([$ana], 'ongoing');
        $this->book($project, 0, 5);

        $this->archive($project);
        $this->assertTrue($this->isFree($ana, 0, 5));

        $this->restore($project)->assertSessionHas('success');

        $this->assertSame('ongoing', $project->fresh()->status);
        $this->assertFalse($this->isFree($ana, 0, 5));
    }

    /**
     * A project archived before archiving preserved anything has no earlier
     * state to return to - the old flow deleted it - so Unscheduled is still
     * the honest answer for those rows and they still restore that way.
     */
    public function test_a_legacy_archived_row_still_restores_as_unscheduled(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([], 'ongoing');

        // Exactly what the old archive left behind: the flags, no schedule, no
        // team, and nothing recorded about what it had been.
        $project->update([
            'status' => 'archived',
            'is_archived' => true,
            'archived_at' => now(),
            'pre_archive_status' => null,
        ]);

        $this->restore($project)->assertSessionHas('success');

        $this->assertSame('unscheduled', $project->fresh()->status);
    }

    // ==================================================================
    // Restoring is screened against the calendar
    // ==================================================================

    /**
     * The case the whole design turns on.
     *
     * The archived project's days were genuinely free while it sat there, so
     * another project took them. Putting it back into force would double-book
     * the technician on two live projects at once, invisibly - each project
     * only ever shows its own dates.
     */
    public function test_a_restore_that_would_double_book_the_team_is_refused(): void
    {
        $this->actingAsSuperAdmin();

        $john = $this->technician('John Ilagan');

        $archived = $this->project([$john], 'ongoing');
        $schedule = $this->book($archived, 10, 15);
        $this->archive($archived);

        // Allowed, and the point of archiving: those days really were free.
        $live = $this->project([$john], 'ongoing', 'PRJ-LIVE-2');
        $this->book($live, 12, 18);

        $this->restore($archived)
            ->assertRedirect(route('super-admin.projects.archived'))
            ->assertSessionHas('error');

        $message = session('error');

        $this->assertStringContainsString('Unable to restore', $message);
        $this->assertStringContainsString('John Ilagan', $message);
        // Which job is holding them, so the refusal is something to act on.
        $this->assertStringContainsString('PRJ-LIVE-2', $message);

        // Nothing was written: not the flags, and not the preserved schedule.
        $stillArchived = $archived->fresh();
        $this->assertTrue($stillArchived->is_archived);
        $this->assertSame('archived', $stillArchived->status);
        $this->assertSame('ongoing', $stillArchived->pre_archive_status);

        $kept = Schedule::find($schedule->schedule_id);
        $this->assertNotNull($kept);
        $this->assertSame($this->day(10)->toDateString(), $kept->startsOn()->toDateString());
        $this->assertSame($this->day(15)->toDateString(), $kept->endsOn()->toDateString());
        $this->assertSame(1, ProjectTechnician::where('project_id', $archived->project_id)->count());
    }

    /**
     * Once the clash is gone the same restore goes through, untouched.
     */
    public function test_the_refused_restore_succeeds_once_the_other_work_moves(): void
    {
        $this->actingAsSuperAdmin();

        $john = $this->technician('John Ilagan');

        $archived = $this->project([$john], 'ongoing');
        // From today, so Ongoing is what the dates say as well as what the
        // column says - ProjectStatusRules owns those three statuses and would
        // otherwise, quite correctly, call a booking that starts next week
        // Pending.
        $this->book($archived, 0, 5);
        $this->archive($archived);

        $live = $this->project([$john], 'ongoing', 'PRJ-LIVE-3');
        $clashing = $this->book($live, 2, 8);

        $this->restore($archived)->assertSessionHas('error');

        $clashing->delete();

        $this->restore($archived)->assertSessionHas('success');

        $this->assertSame('ongoing', $archived->fresh()->status);
    }

    /**
     * A project must never report itself as its own blocker: every day being
     * screened is one it holds itself.
     */
    public function test_a_project_does_not_conflict_with_its_own_preserved_schedule(): void
    {
        $this->actingAsSuperAdmin();

        $ana = $this->technician('Ana Mendoza');
        $project = $this->project([$ana], 'ongoing');
        $this->book($project, 0, 5);
        // Two ranges, so its own rows are on both sides of the question.
        $this->book($project, 20, 25);

        $this->archive($project);

        $this->restore($project)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame('ongoing', $project->fresh()->status);
        $this->assertSame(2, Schedule::where('project_id', $project->project_id)->count());
    }

    /**
     * A Completed or Cancelled record holds nobody, so restoring one cannot
     * collide with anything - and is not refused for dates that overlap live
     * work. This is the existing rule, not a new exception: availability has
     * always counted Pending and Ongoing work only.
     */
    public function test_restoring_a_completed_record_is_not_blocked_by_overlapping_live_work(): void
    {
        $this->actingAsSuperAdmin();

        $ana = $this->technician('Ana Mendoza');

        $archived = $this->project([$ana], 'completed');
        $this->book($archived, 10, 15);
        $this->archive($archived);

        $live = $this->project([$ana], 'ongoing', 'PRJ-LIVE-4');
        $this->book($live, 10, 15);

        $this->restore($archived)->assertSessionHas('success');

        $this->assertSame('completed', $archived->fresh()->status);
        // And the live project is still the only thing holding those days.
        $this->assertFalse($this->isFree($ana, 10, 15));
    }

    // ==================================================================
    // What the pages say
    // ==================================================================

    /**
     * The archive listing says what a restore will bring each project back as,
     * so nobody has to press the button to find out.
     *
     * It used to promise every one of them would return as Unscheduled, which
     * was true then and would be a lie now.
     */
    public function test_the_archive_listing_says_what_a_restore_will_bring_back(): void
    {
        $this->actingAsSuperAdmin();

        $completed = $this->project([$this->technician('Ana Mendoza')], 'completed', 'PRJ-DONE-1');
        $this->book($completed, 10, 15);
        $this->archive($completed);

        $legacy = $this->project([], 'ongoing', 'PRJ-OLD-1');
        $legacy->update(['status' => 'archived', 'is_archived' => true, 'archived_at' => now()]);

        $response = $this->get(route('super-admin.projects.archived'))->assertOk();

        $response->assertSee('with its original schedule and team', false);
        // And the honest answer for a row archived before any of this existed.
        $response->assertSee('archived before archiving kept schedules', false);
    }

    /**
     * The refusal reaches the person, in the toast the rest of the portal
     * raises its refusals in.
     */
    public function test_the_conflict_message_is_shown_on_the_archive_page(): void
    {
        $this->actingAsSuperAdmin();

        $john = $this->technician('John Ilagan');

        $archived = $this->project([$john], 'ongoing');
        $this->book($archived, 0, 5);
        $this->archive($archived);

        $live = $this->project([$john], 'ongoing', 'PRJ-LIVE-5');
        $this->book($live, 2, 8);

        $this->followingRedirects()
            ->put(route('super-admin.projects.restore', $archived->project_id))
            ->assertOk()
            ->assertSee('Unable to restore')
            ->assertSee('John Ilagan')
            ->assertSee('PRJ-LIVE-5');
    }

    // ==================================================================
    // Boundaries
    // ==================================================================

    public function test_restore_is_refused_for_a_project_that_is_not_archived(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([], 'ongoing');

        $this->restore($project)->assertSessionHas('error');

        $this->assertSame('ongoing', $project->fresh()->status);
    }

    public function test_archiving_twice_is_refused(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([$this->technician('Ana Mendoza')], 'completed');
        $this->archive($project);

        $this->post(route('super-admin.projects.archive', $project->project_id))
            ->assertSessionHas('error');

        // The first archive's record of what it was is not overwritten by the
        // second attempt - which would have recorded 'archived'.
        $this->assertSame('completed', $project->fresh()->pre_archive_status);
    }

    /**
     * Taking a record out of the active system, and putting it back, belongs
     * to the system's owner. Unchanged, and asserted here because preserving
     * the project makes the archive worth more than it was.
     */
    public function test_archiving_and_restoring_stay_the_super_admins(): void
    {
        $admin = User::factory()->create(['email' => 'the.admin@example.test']);
        $admin->forceFill(['role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE])->save();

        $project = $this->project([], 'completed');

        $this->actingAs($admin)
            ->post(route('super-admin.projects.archive', $project->project_id))
            ->assertRedirect();

        $this->assertSame('completed', $project->fresh()->status);
        $this->assertFalse($project->fresh()->is_archived);
    }

    public function test_the_restore_is_recorded_in_the_activity_log(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([$this->technician('Ana Mendoza')], 'completed');
        $this->book($project, 10, 15);

        $this->archive($project);
        $this->restore($project)->assertSessionHas('success');

        $this->assertDatabaseHas('tbl_activity_logs', ['action' => ActivityLog::PROJECT_RESTORED]);
    }

    /**
     * Restore is not Reopen and must not borrow its behaviour: reopening books
     * NEW dates onto a project whose old ones were given away, and it is the
     * only action a project awaiting its client accepts. Restoring writes no
     * schedule at all.
     */
    public function test_restoring_never_creates_a_new_schedule(): void
    {
        $this->actingAsSuperAdmin();

        $project = $this->project([$this->technician('Ana Mendoza')], 'completed');
        $this->book($project, 10, 15);

        $this->archive($project);
        $this->restore($project);

        $this->assertSame(1, Schedule::where('project_id', $project->project_id)->count());
        $this->assertNull($project->fresh()->reopened_at);
    }
}
