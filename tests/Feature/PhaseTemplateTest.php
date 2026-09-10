<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\PhaseStage;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\ProjectPhaseDraftTask;
use App\Models\ProjectTechnician;
use App\Models\ProjectType;
use App\Models\ProjectTypeStageTask;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Task;
use App\Models\Technician;
use App\Models\User;
use App\Services\PhaseTemplateMerger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Default phases and tasks, and what happens to them when a project is more
 * than one type at once.
 *
 * That last part is the whole reason the feature is shaped the way it is. A
 * project can be Aircon Installation AND Electrical Works; both of those have
 * a Site Preparation and both mean something different by it; and the person
 * setting the project up has to be shown one structure to edit rather than two
 * to reconcile. So most of what is tested below is the merge:
 *
 *   - stages are a union, not a concatenation, so a shared stage appears once
 *   - order comes from the vocabulary, so two types cannot disagree about it
 *   - tasks under a shared stage are both types' work, deduped by title
 *   - and none of it is written until somebody says so
 */
class PhaseTemplateTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $admin;

    private Technician $lead;

    private User $leadAccount;

    private Project $project;

    private ProjectType $aircon;

    private ProjectType $electrical;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = $this->account('super_admin', 'owner@example.test');
        $this->admin = $this->account('admin', 'admin@example.test');

        $this->leadAccount = $this->account('lead_technician', 'lead@example.test');
        $this->lead = Technician::create([
            'account_id' => $this->leadAccount->id,
            'role' => 'lead_technician',
        ]);

        $this->aircon = ProjectType::create(['type_name' => 'Aircon Installation']);
        $this->electrical = ProjectType::create(['type_name' => 'Electrical Works']);

        $this->project = Project::create([
            'name' => 'Office Retrofit',
            'reference_no' => 'REF-TPL-1',
            'status' => 'ongoing',
            'address' => '1 Test Street',
            'description' => 'Description',
            'quotation' => 100000,
        ]);

        ProjectTechnician::create([
            'project_id' => $this->project->project_id,
            'technician_id' => $this->lead->technician_id,
        ]);

        $this->book(10, 20);
    }

    // ------------------------------------------------------------------
    // The vocabulary
    // ------------------------------------------------------------------

    public function test_the_vocabulary_ships_with_the_stages_this_system_already_suggested(): void
    {
        // The seeding migration puts these there so nothing regresses on the
        // day templates arrive - see its docblock.
        $names = PhaseStage::query()->inOrder()->pluck('name')->all();

        $this->assertSame(
            array_column(PhaseStage::STARTING_VOCABULARY, 'name'),
            $names
        );
    }

    public function test_only_a_super_admin_can_read_or_write_the_templates(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('super-admin.configuration.phase-templates.index'))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->postJson(route('super-admin.configuration.phase-templates.stages.store'), [
                'name' => 'Sneaky Stage',
                'default_description' => 'Should not be written.',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('tbl_phase_stages', ['name' => 'Sneaky Stage']);

        $this->actingAs($this->superAdmin)
            ->getJson(route('super-admin.configuration.phase-templates.index'))
            ->assertOk()
            ->assertJsonStructure(['stages', 'types']);
    }

    public function test_a_stage_in_use_by_a_project_type_cannot_be_removed(): void
    {
        $stage = $this->stage('Installation');

        $this->saveTemplate($this->aircon, [
            ['stage_id' => $stage->stage_id, 'tasks' => []],
        ]);

        $this->actingAs($this->superAdmin)
            ->deleteJson(route('super-admin.configuration.phase-templates.stages.destroy', $stage->stage_id))
            ->assertStatus(422)
            ->assertJsonPath('error', fn (string $error): bool => str_contains($error, 'Aircon Installation'));

        $this->assertDatabaseHas('tbl_phase_stages', ['stage_id' => $stage->stage_id]);
    }

    public function test_reordering_the_vocabulary_reorders_what_projects_are_offered(): void
    {
        $first = $this->stage('Installation', 10);
        $second = $this->stage('Testing', 20);

        $this->saveTemplate($this->aircon, [
            ['stage_id' => $first->stage_id, 'tasks' => []],
            ['stage_id' => $second->stage_id, 'tasks' => []],
        ]);

        $this->project->projectTypes()->sync([$this->aircon->type_id]);

        $this->assertSame(
            ['Installation', 'Testing'],
            array_column($this->merged()['phases'], 'title')
        );

        // The whole vocabulary, with those two swapped. A partial list is
        // refused on purpose - order is a property of the list, and sending
        // half of it is how a stale tab silently reorders the rest.
        $order = PhaseStage::query()->inOrder()->pluck('stage_id')->all();
        $order = array_values(array_diff($order, [$first->stage_id, $second->stage_id]));
        array_unshift($order, $second->stage_id, $first->stage_id);

        $this->actingAs($this->superAdmin)
            ->postJson(route('super-admin.configuration.phase-templates.stages.reorder'), [
                'stage_ids' => $order,
            ])
            ->assertOk();

        $this->assertSame(
            ['Testing', 'Installation'],
            array_column($this->merged()['phases'], 'title')
        );

        $this->assertDatabaseHas('tbl_activity_logs', [
            'action' => ActivityLog::PHASE_STAGES_REORDERED,
        ]);
    }

    // ------------------------------------------------------------------
    // The merge - the reason this feature exists
    // ------------------------------------------------------------------

    public function test_a_stage_two_types_share_becomes_one_phase_carrying_both_their_tasks(): void
    {
        $prep = $this->stage('Site Preparation', 10);
        $install = $this->stage('Installation', 20);
        $roughIn = $this->stage('Rough-In', 15);

        $this->saveTemplate($this->aircon, [
            ['stage_id' => $prep->stage_id, 'tasks' => [
                ['title' => 'Clear and protect work area', 'description' => 'Sheet the floor.'],
                ['title' => 'Mark unit positions', 'description' => 'Chalk the wall.'],
            ]],
            ['stage_id' => $install->stage_id, 'tasks' => [
                ['title' => 'Mount indoor unit', 'description' => 'Bracket and level.'],
            ]],
        ]);

        $this->saveTemplate($this->electrical, [
            ['stage_id' => $prep->stage_id, 'tasks' => [
                // The same job, named the same way by both trades.
                ['title' => 'Clear and protect work area', 'description' => 'Sheet the floor.'],
                ['title' => 'Panel capacity check', 'description' => 'Confirm spare ways.'],
            ]],
            ['stage_id' => $roughIn->stage_id, 'tasks' => [
                ['title' => 'Pull circuits', 'description' => 'Run cable to unit positions.'],
            ]],
        ]);

        $this->project->projectTypes()->sync([$this->aircon->type_id, $this->electrical->type_id]);

        $merged = $this->merged();

        $this->assertTrue($merged['from_templates']);

        // A union in the vocabulary's order: Rough-In sorts between the two
        // even though only one type asked for it, and Site Preparation appears
        // once despite both types using it.
        $this->assertSame(
            ['Site Preparation', 'Rough-In', 'Installation'],
            array_column($merged['phases'], 'title')
        );

        $prepPhase = $merged['phases'][0];

        // Both types are credited, so the person editing can see whose work is
        // whose before they start deleting rows.
        $this->assertSame(['Aircon Installation', 'Electrical Works'], $prepPhase['sources']);

        $this->assertSame(
            ['Clear and protect work area', 'Mark unit positions', 'Panel capacity check'],
            array_column($prepPhase['tasks'], 'title')
        );

        // The shared task collapsed into one row carrying both names.
        $this->assertSame(
            ['Aircon Installation', 'Electrical Works'],
            $prepPhase['tasks'][0]['sources']
        );

        // A task only one type wanted keeps only that type's name.
        $this->assertSame(['Aircon Installation'], $prepPhase['tasks'][1]['sources']);
    }

    public function test_two_types_disagreeing_about_a_shared_task_says_so_rather_than_choosing_quietly(): void
    {
        $prep = $this->stage('Site Preparation');

        $this->saveTemplate($this->aircon, [
            ['stage_id' => $prep->stage_id, 'tasks' => [
                ['title' => 'Isolate supply', 'description' => 'Shut the refrigerant valves.'],
            ]],
        ]);

        $this->saveTemplate($this->electrical, [
            ['stage_id' => $prep->stage_id, 'tasks' => [
                ['title' => 'Isolate supply', 'description' => 'Lock off the breaker.'],
            ]],
        ]);

        $this->project->projectTypes()->sync([$this->aircon->type_id, $this->electrical->type_id]);

        $task = $this->merged()['phases'][0]['tasks'][0];

        // The first type to claim the title keeps its wording, and the
        // disagreement is flagged for a person to settle rather than buried.
        $this->assertSame('Shut the refrigerant valves.', $task['description']);
        $this->assertTrue($task['description_conflict']);
    }

    public function test_a_type_with_no_template_contributes_nothing_and_is_named(): void
    {
        $prep = $this->stage('Site Preparation');

        $this->saveTemplate($this->aircon, [
            ['stage_id' => $prep->stage_id, 'tasks' => [
                ['title' => 'Sheet the floor', 'description' => 'Protect finishes.'],
            ]],
        ]);

        $this->project->projectTypes()->sync([$this->aircon->type_id, $this->electrical->type_id]);

        $merged = $this->merged();

        $this->assertSame(['Site Preparation'], array_column($merged['phases'], 'title'));
        $this->assertSame(['Electrical Works'], $merged['types_without_template']);
    }

    public function test_a_project_no_template_covers_falls_back_to_the_built_in_suggestion(): void
    {
        $this->project->projectTypes()->sync([$this->aircon->type_id]);

        $merged = $this->merged();

        $this->assertFalse($merged['from_templates']);
        $this->assertSame(
            array_column(ProjectPhase::SUGGESTED_PHASES, 'title'),
            array_column($merged['phases'], 'title')
        );

        // Unstamped: these four share their names with the seeded vocabulary,
        // and matching them up by name is the guesswork stage ids exist to
        // avoid.
        $this->assertNull($merged['phases'][0]['stage_id']);
    }

    public function test_editing_a_template_does_not_touch_a_project_already_set_up(): void
    {
        $prep = $this->stage('Site Preparation');

        $this->saveTemplate($this->aircon, [
            ['stage_id' => $prep->stage_id, 'tasks' => [
                ['title' => 'Sheet the floor', 'description' => 'Protect finishes.'],
            ]],
        ]);

        $this->project->projectTypes()->sync([$this->aircon->type_id]);

        $this->finalizeWith([
            [
                'stage_id' => $prep->stage_id,
                'title' => 'Site Preparation',
                'description' => 'Prepare the area.',
                'tasks' => [
                    ['title' => 'Sheet the floor', 'description' => 'Protect finishes.'],
                ],
            ],
        ]);

        // The template is rewritten from underneath the finished project.
        $this->saveTemplate($this->aircon, [
            ['stage_id' => $prep->stage_id, 'tasks' => [
                ['title' => 'Something else entirely', 'description' => 'Different work.'],
            ]],
        ]);

        $this->assertSame(
            ['Sheet the floor'],
            Task::query()
                ->where('project_id', $this->project->project_id)
                ->pluck('task_title')
                ->all()
        );

        $this->assertSame(1, $this->project->fresh()->phase_count);
    }

    // ------------------------------------------------------------------
    // Setting a project up from the merged structure
    // ------------------------------------------------------------------

    public function test_the_setup_screen_offers_the_merged_structure_without_writing_it(): void
    {
        $prep = $this->stage('Site Preparation');

        $this->saveTemplate($this->aircon, [
            ['stage_id' => $prep->stage_id, 'tasks' => [
                ['title' => 'Sheet the floor', 'description' => 'Protect finishes.'],
            ]],
        ]);

        $this->project->projectTypes()->sync([$this->aircon->type_id]);

        $this->actingAs($this->leadAccount)
            ->get(route('technician.projects.phases.setup', $this->project->project_id))
            ->assertOk()
            ->assertSee('Site Preparation')
            ->assertSee('Sheet the floor')
            ->assertSee("Started from this project's default phases", false);

        // A suggestion is a suggestion: nothing has been stored.
        $this->assertSame(0, $this->project->phases()->count());
        $this->assertSame(0, Task::query()->where('project_id', $this->project->project_id)->count());
        $this->assertTrue($this->project->fresh()->needsPhaseSetup());
    }

    public function test_finalizing_turns_the_tasks_into_real_work_and_leaves_no_drafts(): void
    {
        $this->finalizeWith([
            [
                'title' => 'Site Preparation',
                'description' => 'Prepare the area.',
                'tasks' => [
                    // Fully staffed.
                    [
                        'title' => 'Sheet the floor',
                        'description' => 'Protect finishes.',
                        'technician_id' => $this->lead->technician_id,
                        'start_date' => $this->day(11),
                        'due_date' => $this->day(12),
                    ],
                    // Written down, staffed later. The point of the screen.
                    ['title' => 'Mark unit positions', 'description' => 'Chalk the wall.'],
                ],
            ],
        ]);

        $tasks = Task::query()
            ->where('project_id', $this->project->project_id)
            ->orderBy('task_id')
            ->get();

        $this->assertCount(2, $tasks);

        $this->assertSame('pending', $tasks[0]->status);
        $this->assertSame($this->lead->technician_id, $tasks[0]->technician_id);

        // A task nobody has been given is 'unassigned' with no dates, which is
        // a state this system has always drawn - see Task::GAP_LABELS.
        $this->assertSame('unassigned', $tasks[1]->status);
        $this->assertNull($tasks[1]->technician_id);
        $this->assertNull($tasks[1]->start_date);

        $this->assertSame(0, ProjectPhaseDraftTask::query()->count());
    }

    public function test_saving_without_locking_keeps_the_tasks_as_drafts_and_creates_none(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.save', $this->project->project_id), [
                'phases' => [
                    [
                        'title' => 'Site Preparation',
                        'description' => 'Prepare the area.',
                        'tasks' => [
                            ['title' => 'Sheet the floor', 'description' => 'Protect finishes.'],
                        ],
                    ],
                ],
            ])
            ->assertRedirect();

        // The work is kept, and it is not work yet: the task board would list a
        // real row, and a technician would be told about a project still being
        // drawn up.
        $this->assertSame(1, ProjectPhaseDraftTask::query()->count());
        $this->assertSame(0, Task::query()->where('project_id', $this->project->project_id)->count());
        $this->assertTrue($this->project->fresh()->needsPhaseSetup());

        // And it comes back on the screen rather than being quietly dropped.
        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.projects.phases.setup', $this->project->project_id))
            ->assertOk()
            ->assertSee('Sheet the floor');
    }

    public function test_a_task_may_be_left_unstaffed_but_not_half_dated(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.finalize', $this->project->project_id), [
                'phases' => [
                    [
                        'title' => 'Site Preparation',
                        'description' => 'Prepare the area.',
                        'tasks' => [
                            [
                                'title' => 'Sheet the floor',
                                'description' => 'Protect finishes.',
                                'start_date' => $this->day(11),
                                // No end date. A task with a start and no
                                // deadline is not one anybody can be held to.
                            ],
                        ],
                    ],
                ],
            ])
            ->assertSessionHasErrors('phases.0.tasks.0.due_date');

        $this->assertTrue($this->project->fresh()->needsPhaseSetup());
    }

    public function test_dates_outside_the_booked_schedule_are_refused_here_too(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.finalize', $this->project->project_id), [
                'phases' => [
                    [
                        'title' => 'Site Preparation',
                        'description' => 'Prepare the area.',
                        'tasks' => [
                            [
                                'title' => 'Sheet the floor',
                                'description' => 'Protect finishes.',
                                // The project is booked days 10-20.
                                'start_date' => $this->day(40),
                                'due_date' => $this->day(41),
                            ],
                        ],
                    ],
                ],
            ])
            ->assertSessionHasErrors('phases.0.tasks.0.start_date');
    }

    public function test_a_task_cannot_be_given_to_somebody_off_the_project(): void
    {
        $outsiderAccount = $this->account('technician', 'outsider@example.test');
        $outsider = Technician::create([
            'account_id' => $outsiderAccount->id,
            'role' => 'technician',
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.finalize', $this->project->project_id), [
                'phases' => [
                    [
                        'title' => 'Site Preparation',
                        'description' => 'Prepare the area.',
                        'tasks' => [
                            [
                                'title' => 'Sheet the floor',
                                'description' => 'Protect finishes.',
                                'technician_id' => $outsider->technician_id,
                            ],
                        ],
                    ],
                ],
            ])
            ->assertSessionHasErrors('phases.0.tasks.0.technician_id');
    }

    public function test_a_phase_added_by_hand_takes_tasks_like_any_other(): void
    {
        $this->finalizeWith([
            [
                'title' => 'Site Preparation',
                'description' => 'Prepare the area.',
                'tasks' => [],
            ],
            [
                // No stage_id: nothing suggested this one, somebody typed it.
                'title' => 'Client Handover',
                'description' => 'Walk the client through the work.',
                'tasks' => [
                    ['title' => 'Handover pack', 'description' => 'Print the certificates.'],
                ],
            ],
        ]);

        $phase = $this->project->phases()->where('title', 'Client Handover')->first();

        $this->assertNull($phase->stage_id);
        $this->assertSame(
            ['Handover pack'],
            $phase->tasks()->pluck('task_title')->all()
        );
    }

    public function test_re_finalizing_after_an_override_does_not_seed_the_same_tasks_twice(): void
    {
        $this->finalizeWith([
            [
                'title' => 'Site Preparation',
                'description' => 'Prepare the area.',
                'tasks' => [
                    ['title' => 'Sheet the floor', 'description' => 'Protect finishes.'],
                ],
            ],
        ]);

        $this->assertSame(1, Task::query()->where('project_id', $this->project->project_id)->count());

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.override', $this->project->project_id))
            ->assertRedirect();

        $existing = $this->project->phases()->inOrder()->first();

        // The same structure submitted again, its one phase carrying its
        // phase_id. The task on it is real work now, not a draft, so it must
        // not be created a second time.
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.finalize', $this->project->project_id), [
                'phases' => [
                    [
                        'phase_id' => $existing->phase_id,
                        'title' => 'Site Preparation',
                        'description' => 'Prepare the area.',
                        'tasks' => [],
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(1, Task::query()->where('project_id', $this->project->project_id)->count());
    }

    public function test_starting_again_rebuilds_from_the_templates(): void
    {
        $prep = $this->stage('Site Preparation');

        $this->saveTemplate($this->aircon, [
            ['stage_id' => $prep->stage_id, 'tasks' => [
                ['title' => 'Sheet the floor', 'description' => 'Protect finishes.'],
            ]],
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.save', $this->project->project_id), [
                'phases' => [
                    ['title' => 'Something I typed', 'description' => 'By hand.', 'tasks' => []],
                ],
            ])
            ->assertRedirect();

        // The type is added AFTER setup was begun - the case this action is for.
        $this->project->projectTypes()->sync([$this->aircon->type_id]);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.reload', $this->project->project_id))
            ->assertRedirect();

        $this->assertSame(0, $this->project->phases()->count());
        $this->assertSame(0, ProjectPhaseDraftTask::query()->count());

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.projects.phases.setup', $this->project->project_id))
            ->assertOk()
            ->assertSee('Sheet the floor')
            ->assertDontSee('Something I typed');
    }

    public function test_starting_again_is_refused_once_real_work_exists(): void
    {
        $this->finalizeWith([
            [
                'title' => 'Site Preparation',
                'description' => 'Prepare the area.',
                'tasks' => [
                    ['title' => 'Sheet the floor', 'description' => 'Protect finishes.'],
                ],
            ],
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.override', $this->project->project_id))
            ->assertRedirect();

        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.projects.phases.reload', $this->project->project_id))
            ->assertSessionHas('error');

        $this->assertSame(1, Task::query()->where('project_id', $this->project->project_id)->count());
        $this->assertSame(1, $this->project->phases()->count());
    }

    public function test_a_technician_is_told_once_about_a_structure_rather_than_once_per_task(): void
    {
        $this->finalizeWith([
            [
                'title' => 'Site Preparation',
                'description' => 'Prepare the area.',
                'tasks' => [
                    [
                        'title' => 'Sheet the floor',
                        'description' => 'Protect finishes.',
                        'technician_id' => $this->lead->technician_id,
                        'start_date' => $this->day(11),
                        'due_date' => $this->day(12),
                    ],
                    [
                        'title' => 'Mark unit positions',
                        'description' => 'Chalk the wall.',
                        'technician_id' => $this->lead->technician_id,
                        'start_date' => $this->day(11),
                        'due_date' => $this->day(12),
                    ],
                    [
                        'title' => 'Mask the vents',
                        'description' => 'Tape and label.',
                        'technician_id' => $this->lead->technician_id,
                        'start_date' => $this->day(11),
                        'due_date' => $this->day(12),
                    ],
                ],
            ],
        ]);

        $notifications = \App\Models\Notification::query()
            ->where('user_id', $this->leadAccount->id)
            ->where('title', 'New Task Assignments')
            ->get();

        $this->assertCount(1, $notifications);
        $this->assertStringContainsString('3 tasks', $notifications->first()->message);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array{phases: array<int, array<string, mixed>>, from_templates: bool, types_without_template: array<int, string>, dropped_stages: array<int, string>}
     */
    private function merged(): array
    {
        return app(PhaseTemplateMerger::class)->for($this->project->fresh());
    }

    /**
     * A stage by name, created if the seeded vocabulary does not already carry
     * one - "Site Preparation" and "Installation" are shipped by the seeding
     * migration, and names are unique.
     */
    private function stage(string $name, ?int $sortOrder = null): PhaseStage
    {
        $stage = PhaseStage::query()->where('name', $name)->first();

        if ($stage === null) {
            return PhaseStage::create([
                'name' => $name,
                'default_description' => $name.' happens here.',
                'sort_order' => $sortOrder ?? PhaseStage::nextSortOrder(),
            ]);
        }

        if ($sortOrder !== null) {
            $stage->update(['sort_order' => $sortOrder]);
        }

        return $stage->refresh();
    }

    /**
     * @param  array<int, array{stage_id: int, tasks: array<int, array{title: string, description: string}>}>  $stages
     */
    private function saveTemplate(ProjectType $type, array $stages): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson(
                route('super-admin.configuration.phase-templates.types.update', $type->type_id),
                ['stages' => $stages]
            )
            ->assertOk();
    }

    /**
     * @param  array<int, array<string, mixed>>  $phases
     */
    private function finalizeWith(array $phases): void
    {
        $this->actingAs($this->superAdmin)
            ->post(
                route('super-admin.projects.phases.finalize', $this->project->project_id),
                ['phases' => $phases]
            )
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->project = $this->project->fresh();
    }

    private function account(string $role, string $email): User
    {
        $sequence = User::count() + 1;

        return User::create([
            'user_code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => 'Test Person '.$sequence,
            'first_name' => 'Test',
            'last_name' => 'Person',
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);
    }

    private function book(int $from, int $to): Schedule
    {
        $schedule = Schedule::create([
            'project_id' => $this->project->project_id,
            'start_datetime' => $this->day($from).' 08:00:00',
            'end_datetime' => $this->day($to).' 17:00:00',
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
}
