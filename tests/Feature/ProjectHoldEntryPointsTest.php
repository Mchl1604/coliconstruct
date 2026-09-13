<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
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
 * Three ways in, one action.
 *
 * Putting a project on hold is offered from the Projects list, from the
 * Schedules page and from a project's own page. There is one implementation of
 * it - ProjectController::putOnHold() - and these tests are what say so: the
 * same endpoint, the same conditions, the same status transition, the same
 * schedule cutoff and the same activity record, whichever button was pressed.
 *
 * The one thing the new buttons add is `origin`, which decides nothing about
 * what the hold does and everything about where the reader is afterwards: the
 * page they pressed it on rather than the Projects list. What the hold itself
 * does is ProjectOnHoldTest's subject and is not restated here.
 */
class ProjectHoldEntryPointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
    }

    private function technician(string $name): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill(['role' => 'technician'])->save();

        return Technician::create(['account_id' => $user->id, 'role' => 'technician']);
    }

    private function day(int $offset): CarbonImmutable
    {
        return Schedule::businessToday()->addDays($offset);
    }

    /**
     * A project the Schedules table lists and Project Details can draw: a
     * crew, a client and dates that reach past today, which is what makes it
     * holdable in the first place.
     */
    private function scheduledProject(string $reference = 'REF-0001'): Project
    {
        $project = Project::create([
            'name' => 'Some Project',
            'reference_no' => $reference,
            'status' => 'ongoing',
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
            'email_address' => 'client@example.test',
            'contact_number' => '09123456789',
        ]);

        $assignment = ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $this->technician('Ana '.$reference)->technician_id,
        ]);

        $schedule = Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => $this->day(0)->toDateString().' 00:00:00',
            'end_datetime' => $this->day(4)->toDateString().' 23:59:59',
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'status' => 'scheduled',
        ]);

        ScheduleTechnician::create([
            'schedule_id' => $schedule->schedule_id,
            'project_technician_id' => $assignment->project_technician_id,
        ]);

        return $project;
    }

    /**
     * Everything the hold is supposed to have done, as one comparable value.
     *
     * Read the same way whichever button was pressed, so three entry points
     * can be asserted equal rather than asserted correct three times over.
     *
     * @return array<string, mixed>
     */
    private function outcome(Project $project): array
    {
        $project->refresh();

        return [
            'on_hold' => (bool) $project->on_hold,
            'status' => $project->status,
            'label' => $project->statusLabel(),
            'ranges' => Schedule::where('project_id', $project->project_id)
                ->get()
                ->map(fn (Schedule $schedule): string => $schedule->startsOn()->toDateString()
                    .'|'.$schedule->endsOn()->toDateString())
                ->sort()
                ->values()
                ->all(),
            // The crew is kept, and is released by the status rather than by
            // being taken off the project - so both halves are worth reading.
            'team' => ProjectTechnician::where('project_id', $project->project_id)
                ->pluck('technician_id')
                ->count(),
            'booked' => ScheduleTechnician::whereIn(
                'schedule_id',
                Schedule::where('project_id', $project->project_id)->pluck('schedule_id')
            )->count(),
            // Read through the scope the project's own activity panel reads
            // through - the trail is addressed by record type and record id,
            // not by a project column.
            'activity' => ActivityLog::forProject((int) $project->project_id)
                ->where('action', ActivityLog::PROJECT_PUT_ON_HOLD)
                ->pluck('description')
                ->all(),
        ];
    }

    // ------------------------------------------------------------------
    // The buttons are there, and they point at the existing endpoint
    // ------------------------------------------------------------------

    public function test_all_three_pages_offer_the_hold_through_the_one_endpoint(): void
    {
        $project = $this->scheduledProject();
        $id = $project->project_id;

        // The Projects page, unchanged: it posts to the bare route, which is
        // the default this endpoint has always answered.
        $this->get(route('super-admin.projects'))
            ->assertOk()
            ->assertSee(route('super-admin.projects.hold', $id), false);

        // The Schedules page and the project's own page post to the same
        // route, saying where they were pressed.
        $this->get(route('super-admin.schedules.index'))
            ->assertOk()
            ->assertSee(
                route('super-admin.projects.hold', ['id' => $id, 'origin' => 'schedules']),
                false
            );

        $this->get(route('super-admin.projects.show', $id))
            ->assertOk()
            ->assertSee(
                route('super-admin.projects.hold', ['id' => $id, 'origin' => 'project']),
                false
            );
    }

    /**
     * One sentence, the same one, wherever the question is asked.
     */
    public function test_every_hold_dialog_asks_the_same_question(): void
    {
        $project = $this->scheduledProject();

        foreach ([
            route('super-admin.projects'),
            route('super-admin.schedules.index'),
            route('super-admin.projects.show', $project->project_id),
        ] as $page) {
            $this->get($page)
                ->assertOk()
                ->assertSee('Dates from tomorrow are released.', false)
                ->assertSee('Put on Hold', false);
        }
    }

    // ------------------------------------------------------------------
    // The same action, whichever button was pressed
    // ------------------------------------------------------------------

    /**
     * The whole point of the change: three buttons, one outcome.
     *
     * Three identical projects, one hold each through a different entry point,
     * and the results compared to one another rather than to a transcription
     * of what the hold is meant to do. A second implementation behind any one
     * of the buttons could not pass this.
     */
    public function test_the_three_entry_points_produce_the_same_outcome(): void
    {
        $outcomes = [];

        foreach (['projects' => null, 'schedules' => 'schedules', 'project' => 'project'] as $entry => $origin) {
            $project = $this->scheduledProject('REF-000'.count($outcomes));

            $this->put($origin === null
                ? route('super-admin.projects.hold', $project->project_id)
                : route('super-admin.projects.hold', ['id' => $project->project_id, 'origin' => $origin]))
                ->assertSessionHasNoErrors();

            $outcome = $this->outcome($project);

            // The reference number is in the activity description, and is the
            // one thing that legitimately differs between the three projects.
            $outcome['activity'] = array_map(
                fn (string $line): string => str_replace($project->reference_no, 'REF', $line),
                $outcome['activity']
            );

            $outcomes[$entry] = $outcome;
        }

        $this->assertTrue($outcomes['projects']['on_hold'], 'The hold did not take effect at all.');

        // Said out loud so the comparison below cannot quietly become a
        // comparison of three empty lists: an activity record that stopped
        // being written would otherwise still read as "all three agree".
        $this->assertCount(
            1,
            $outcomes['projects']['activity'],
            'A hold no longer writes exactly one activity record.'
        );
        $this->assertNotEmpty($outcomes['projects']['ranges'], 'The hold kept no dates at all.');

        $this->assertSame(
            $outcomes['projects'],
            $outcomes['schedules'],
            'A hold placed from the Schedules page did something different to one placed from the Projects page.'
        );

        $this->assertSame(
            $outcomes['projects'],
            $outcomes['project'],
            'A hold placed from Project Details did something different to one placed from the Projects page.'
        );
    }

    /**
     * Where each button leaves the reader.
     *
     * The only thing `origin` decides. Project Details lands back on its own
     * path on purpose: projectWorkspace.js answers a redirect to the page it
     * is already on by redrawing the workspace alone, which is what keeps the
     * open tab open instead of resetting to Project Information.
     */
    public function test_each_button_returns_the_reader_to_its_own_page(): void
    {
        $project = $this->scheduledProject();

        $this->put(route('super-admin.projects.hold', ['id' => $project->project_id, 'origin' => 'schedules']))
            ->assertRedirect(route('super-admin.schedules.index'))
            ->assertSessionHas('success', 'Project put on hold.');

        $project = $this->scheduledProject('REF-0002');

        $this->put(route('super-admin.projects.hold', ['id' => $project->project_id, 'origin' => 'project']))
            ->assertRedirect(route('super-admin.projects.show', $project->project_id))
            ->assertSessionHas('success', 'Project put on hold.');

        // Unchanged: no origin is the Projects list, exactly as before.
        $project = $this->scheduledProject('REF-0003');

        $this->put(route('super-admin.projects.hold', $project->project_id))
            ->assertRedirect(route('super-admin.projects', $project->project_id))
            ->assertSessionHas('success', 'Project put on hold.');
    }

    /**
     * An unrecognised origin is not a redirect target.
     *
     * The page is named, never the URL: a redirect read off the request is one
     * anybody can aim somewhere else.
     */
    public function test_an_unknown_origin_falls_back_to_the_projects_list(): void
    {
        $project = $this->scheduledProject();

        $this->put(route('super-admin.projects.hold', [
            'id' => $project->project_id,
            'origin' => 'https://elsewhere.test/',
        ]))->assertRedirect(route('super-admin.projects', $project->project_id));

        $this->assertTrue((bool) $project->fresh()->on_hold);
    }

    // ------------------------------------------------------------------
    // The conditions are the endpoint's, not the button's
    // ------------------------------------------------------------------

    /**
     * A project with no dates has nothing to release, and is refused - from
     * every entry point, with the same sentence, back on the page it was asked
     * from.
     */
    public function test_an_unscheduled_project_is_refused_from_every_entry_point(): void
    {
        foreach (['schedules', 'project', ''] as $index => $origin) {
            $project = $this->scheduledProject('REF-100'.$index);
            $project->forceFill(['status' => 'unscheduled'])->save();

            $this->put($origin === ''
                ? route('super-admin.projects.hold', $project->project_id)
                : route('super-admin.projects.hold', ['id' => $project->project_id, 'origin' => $origin]))
                ->assertSessionHas('error', 'This project has no schedule yet.');

            $this->assertFalse(
                (bool) $project->fresh()->on_hold,
                'An unscheduled project was put on hold from the '.($origin ?: 'projects').' page.'
            );
        }
    }

    /**
     * A refusal comes back to the page it was asked from as well, so nobody is
     * shown an error on a page they were not on.
     */
    public function test_a_refusal_returns_to_the_page_it_was_asked_from(): void
    {
        $project = $this->scheduledProject();
        $project->forceFill(['status' => 'unscheduled'])->save();

        $this->put(route('super-admin.projects.hold', ['id' => $project->project_id, 'origin' => 'schedules']))
            ->assertRedirect(route('super-admin.schedules.index'));

        $this->put(route('super-admin.projects.hold', ['id' => $project->project_id, 'origin' => 'project']))
            ->assertRedirect(route('super-admin.projects.show', $project->project_id));
    }

    /**
     * A locked project is refused whatever the origin says - the endpoint asks
     * the model, and the origin has no say in it.
     */
    public function test_a_completed_project_cannot_be_held_from_any_entry_point(): void
    {
        $project = $this->scheduledProject();
        $project->forceFill(['status' => 'completed'])->save();

        foreach (['schedules', 'project'] as $origin) {
            $this->put(route('super-admin.projects.hold', [
                'id' => $project->project_id,
                'origin' => $origin,
            ]))->assertSessionHas('error');

            $this->assertFalse((bool) $project->fresh()->on_hold);
        }
    }

    /**
     * Permissions are the route's and the portal's, so an origin cannot buy
     * anybody access they did not have.
     */
    public function test_a_technician_cannot_hold_a_project_from_any_entry_point(): void
    {
        $project = $this->scheduledProject();

        $user = User::factory()->create(['email' => 'tech.only@example.test']);
        $user->forceFill(['role' => 'technician'])->save();
        Technician::create(['account_id' => $user->id, 'role' => 'technician']);

        $this->actingAs($user);

        foreach (['schedules', 'project', ''] as $origin) {
            $response = $this->put($origin === ''
                ? route('super-admin.projects.hold', $project->project_id)
                : route('super-admin.projects.hold', ['id' => $project->project_id, 'origin' => $origin]));

            $this->assertTrue(
                $response->isRedirect() || $response->status() === 403,
                'A technician was allowed into the hold endpoint.'
            );

            $this->assertFalse(
                (bool) $project->fresh()->on_hold,
                'A technician put a project on hold through the '.($origin ?: 'projects').' entry point.'
            );
        }
    }

    // ------------------------------------------------------------------
    // Resuming, from the same places
    // ------------------------------------------------------------------

    /**
     * A project held from the Schedules page can be resumed from it, through
     * the endpoint the Projects page already used.
     */
    public function test_the_schedules_page_offers_resume_on_a_held_project(): void
    {
        $project = $this->scheduledProject();

        $this->put(route('super-admin.projects.hold', ['id' => $project->project_id, 'origin' => 'schedules']));

        $this->assertTrue((bool) $project->fresh()->on_hold);

        $this->get(route('super-admin.schedules.index'))
            ->assertOk()
            ->assertSee(
                route('super-admin.projects.resume', ['id' => $project->project_id, 'origin' => 'schedules']),
                false
            )
            // The one dialog a refused resume opens, on this page too.
            ->assertSee('data-conflict-modal', false);
    }

    /**
     * Resuming from the Schedules page is the same resume, and comes back to
     * the Schedules page.
     */
    public function test_resuming_from_the_schedules_page_returns_there(): void
    {
        $project = $this->scheduledProject();

        $this->put(route('super-admin.projects.hold', ['id' => $project->project_id, 'origin' => 'schedules']));

        $this->put(route('super-admin.projects.resume', [
            'id' => $project->project_id,
            'origin' => 'schedules',
        ]))->assertRedirect(route('super-admin.schedules.index'));

        $this->assertFalse((bool) $project->fresh()->on_hold);

        $this->assertSame(
            1,
            ActivityLog::forProject((int) $project->project_id)
                ->where('action', ActivityLog::PROJECT_RESUMED)
                ->count(),
            'A resume from the Schedules page did not write the one activity record a resume writes.'
        );
    }

    /**
     * The resume a script sends is told where to land as well, which is what
     * lets the Schedules page redraw itself instead of navigating away.
     */
    public function test_a_scripted_resume_is_sent_back_to_the_page_it_came_from(): void
    {
        $project = $this->scheduledProject();

        $this->put(route('super-admin.projects.hold', ['id' => $project->project_id, 'origin' => 'schedules']));

        $this->putJson(route('super-admin.projects.resume', [
            'id' => $project->project_id,
            'origin' => 'schedules',
        ]))
            ->assertOk()
            ->assertJsonPath('redirect', route('super-admin.schedules.index'));
    }

    // ------------------------------------------------------------------
    // The schedule panels, which a calendar bar opens as well as a row
    // ------------------------------------------------------------------

    /**
     * The editable schedule panel offers the hold.
     *
     * The same panel a calendar bar opens - schedule.js sends an editable
     * project's bar to scheduleEditModal and every other bar to
     * scheduleViewModal - so covering the two panels covers the calendar.
     */
    public function test_the_schedule_panel_offers_the_hold(): void
    {
        $project = $this->scheduledProject();
        $id = $project->project_id;

        $page = $this->get(route('super-admin.schedules.index'))->assertOk();

        // The panel the bar and the row both open...
        $page->assertSee('id="scheduleEditModal'.$id.'"', false);
        // ...carries a button that hands over to the project's own hold dialog.
        $page->assertSee('data-open-modal="#onHoldModal'.$id.'"', false);
    }

    /**
     * A held project's bar opens the view-only panel, and that panel is where
     * the resume is offered - the one thing still open to its schedule.
     */
    public function test_the_view_only_panel_offers_resume_on_a_held_project(): void
    {
        $project = $this->scheduledProject();
        $id = $project->project_id;

        $this->put(route('super-admin.projects.hold', $id));

        $page = $this->get(route('super-admin.schedules.index'))->assertOk();

        $page->assertSee('id="scheduleViewModal'.$id.'"', false);
        $page->assertSee('data-open-modal="#resumeModal'.$id.'"', false);
        // And not the hold, which is not on offer for a project already held.
        $page->assertDontSee('data-open-modal="#onHoldModal'.$id.'"', false);
    }

    /**
     * A read-only project gets no action in its panel at all.
     *
     * The view-only panel serves three reasons a schedule cannot be changed,
     * and only one of them - paused - has a way out. Completed and cancelled
     * are final.
     */
    public function test_a_completed_project_panel_offers_neither(): void
    {
        $project = $this->scheduledProject();
        $id = $project->project_id;

        $project->forceFill(['status' => 'completed'])->save();

        $this->get(route('super-admin.schedules.index'))
            ->assertOk()
            ->assertSee('id="scheduleViewModal'.$id.'"', false)
            ->assertDontSee('data-open-modal="#resumeModal'.$id.'"', false)
            ->assertDontSee('data-open-modal="#onHoldModal'.$id.'"', false);
    }

    /**
     * Every handoff lands somewhere.
     *
     * The panels do not post the hold themselves - they open the project's
     * confirmation dialog, which is written from a different collection than
     * the panels are. A button naming a dialog that was never rendered is a
     * button that does nothing at all, and it would look exactly like a
     * working one. So the page is read back and the two are matched.
     */
    public function test_every_panel_button_names_a_dialog_the_page_rendered(): void
    {
        // One of each kind the page can draw, so the check is made against a
        // page holding panels of both sorts at once.
        $held = $this->scheduledProject('REF-2001');
        $this->put(route('super-admin.projects.hold', $held->project_id));

        $this->scheduledProject('REF-2002');

        $done = $this->scheduledProject('REF-2003');
        $done->forceFill(['status' => 'completed'])->save();

        $html = $this->get(route('super-admin.schedules.index'))->assertOk()->getContent();

        preg_match_all('/data-open-modal="#([A-Za-z0-9_-]+)"/', $html, $matches);

        $this->assertNotEmpty($matches[1], 'No panel offered an action at all, so this proved nothing.');

        foreach (array_unique($matches[1]) as $target) {
            $this->assertStringContainsString(
                'id="'.$target.'"',
                $html,
                'A schedule panel offers a button opening #'.$target.', which the page never rendered.'
            );
        }
    }

    /**
     * The row of actions is icons, and says what each one is without them.
     *
     * Four buttons with words in them is a column wide enough to push the
     * dates out of the row, so the label moves to the attributes - where a
     * hover and a screen reader both still find it.
     */
    public function test_the_actions_column_is_icon_only(): void
    {
        // Two rows, because the cell draws a different pair for each: an
        // editable project gets Edit Schedule and Put on Hold, a held one gets
        // View Schedule and Resume Project. One row could only ever prove half
        // of this.
        $editable = $this->scheduledProject('REF-3001');

        $held = $this->scheduledProject('REF-3002');
        $this->put(route('super-admin.projects.hold', $held->project_id));

        $page = $this->get(route('super-admin.schedules.index'))->assertOk();

        // Every one of the four says what it is, for a hover and for a reader.
        foreach (['Edit Schedule', 'Put on Hold', 'View Schedule', 'Resume Project'] as $label) {
            $page->assertSee('aria-label="'.$label.'"', false);
        }

        // And each one's content is the icon and nothing else. Written out in
        // full rather than looked for loosely: a label creeping back in
        // between the tags is exactly what this is here to catch.
        $page->assertSee('<i class="bi bi-calendar2-week" aria-hidden="true"></i>', false);
        $page->assertSee('<i class="bi bi-pause" aria-hidden="true"></i>', false);
        $page->assertSee('<i class="bi bi-eye" aria-hidden="true"></i>', false);
        $page->assertSee('<i class="bi bi-play" aria-hidden="true"></i>', false);

        // The row still opens what it always opened.
        $page->assertSee('data-bs-target="#scheduleEditModal'.$editable->project_id.'"', false);
        $page->assertSee('data-bs-target="#onHoldModal'.$editable->project_id.'"', false);
        $page->assertSee('data-bs-target="#scheduleViewModal'.$held->project_id.'"', false);
        $page->assertSee('data-bs-target="#resumeModal'.$held->project_id.'"', false);
    }

    /**
     * A held project is not offered the hold again, and neither page offers
     * both halves of the pair at once.
     */
    public function test_a_held_project_is_offered_resume_rather_than_hold(): void
    {
        $project = $this->scheduledProject();
        $id = $project->project_id;

        $this->put(route('super-admin.projects.hold', $id));

        $this->get(route('super-admin.schedules.index'))
            ->assertOk()
            ->assertDontSee(route('super-admin.projects.hold', ['id' => $id, 'origin' => 'schedules']), false);

        $this->get(route('super-admin.projects.show', $id))
            ->assertOk()
            ->assertDontSee(route('super-admin.projects.hold', ['id' => $id, 'origin' => 'project']), false)
            // The banner's Resume, which the project page already had.
            ->assertSee(route('super-admin.projects.resume', $id), false);
    }
}
