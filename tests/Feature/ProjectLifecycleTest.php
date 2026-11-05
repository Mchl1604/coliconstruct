<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\Material;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectType;
use App\Models\Schedule;
use App\Models\Skill;
use App\Models\StockMovement;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\User;
use App\Services\StockAvailability;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/**
 * One project from creation to the client's confirmation, every step taken over
 * HTTP by the role that takes it now, with the roles that may not take it
 * turned away along the way.
 */
class ProjectLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    private User $hr;

    private User $buyer;

    private User $lead;

    private User $mate;

    private Technician $leadRecord;

    private Technician $mateRecord;

    private Material $copper;

    protected function setUp(): void
    {
        parent::setUp();

        // A step that fails should say why, not merely that it did not succeed.
        TestResponse::macro('assertStepWorked', function () {
            $error = session('error') ?? session('errors')?->first();
            Assert::assertNull($error, 'Refused: '.$error);

            return $this;
        });

        $this->officer = $this->account(User::ROLE_ADMIN_OFFICER);
        $this->hr = $this->account(User::ROLE_ADMIN);
        $this->buyer = $this->account(User::ROLE_PURCHASING_ADMIN);
        $this->lead = $this->account(User::ROLE_LEAD_TECHNICIAN);
        $this->mate = $this->account(User::ROLE_TECHNICIAN);

        $this->leadRecord = Technician::create(['account_id' => $this->lead->id, 'role' => $this->lead->role]);
        $this->mateRecord = Technician::create(['account_id' => $this->mate->id, 'role' => $this->mate->role]);

        ProjectType::query()->firstOrCreate(['type_name' => 'Aircon Installation']);
        $skill = Skill::query()->firstOrCreate(['skill_name' => 'Aircon Installation']);

        DB::table('tbl_skill_map')->insert(['technician_id' => $this->mateRecord->technician_id, 'skill_id' => $skill->skill_id]);

        $this->copper = Material::factory()->measured()->create(['name' => 'Copper Tube 1/4']);
    }

    public function test_a_project_runs_from_creation_to_the_clients_confirmation(): void
    {
        $firstDay = CarbonImmutable::parse(BusinessTime::today()->toDateString())->addDays(10);

        // --- Stock comes in: Purchasing.
        $this->actingAs($this->buyer)->postJson(route('inventory.stock.receive-many'), [
            'items' => [['material_id' => $this->copper->material_id, 'quantity' => 30]],
            'movement_type' => StockMovement::TYPE_STOCK_IN,
            'movement_date' => BusinessTime::today()->toDateString(),
        ])->assertOk();

        // --- Creating the project: not HR, not Purchasing; an Admin Officer.
        $payload = $this->wizardPayload($firstDay);

        $this->assertRefused($this->actingAs($this->hr)->post(route('super-admin.projects.create.store'), $payload));
        $this->assertRefused($this->actingAs($this->buyer)->post(route('super-admin.projects.create.store'), $payload));
        $this->assertSame(0, Project::count());

        $this->actingAs($this->officer)
            ->post(route('super-admin.projects.create.store'), $this->wizardPayload($firstDay))
            ->assertRedirect(route('super-admin.projects'))
            ->assertStepWorked()->assertSessionHasNoErrors();

        $project = Project::query()->firstOrFail();

        // The plan reserves stock from the start.
        $this->assertSame(
            ['on_hand' => 30.0, 'reserved' => 10.0, 'available' => 20.0],
            app(StockAvailability::class)->forMaterial($this->copper)
        );

        // --- HR adds a schedule to the project.
        $extraDay = $firstDay->addDays(5)->toDateString();

        $this->actingAs($this->hr)->postJson(route('super-admin.schedules.assign'), [
            'scheduling_mode' => Schedule::MODE_DATE_BASED,
            'start_date' => $extraDay,
            'end_date' => $extraDay,
            'project_ids' => [$project->project_id],
        ])->assertOk();

        $this->assertSame(2, Schedule::query()->where('project_id', $project->project_id)->count());

        // --- Work day one.
        $this->travelTo($firstDay->setTime(9, 0));

        // --- The lead sets the phases up.
        $this->actingAs($this->lead)
            ->post(route('technician.projects.phases.finalize', $project->project_id), [
                'phases' => [
                    ['title' => 'Survey', 'description' => 'Check the site.'],
                    ['title' => 'Install', 'description' => 'Fit the units.'],
                ],
            ])->assertStepWorked()->assertSessionHas('success');

        $project->refresh();
        $this->assertTrue($project->phasesAreFinalized());
        $phases = $project->phases()->orderBy('sequence')->get();

        // --- Tasks: the lead assigns them, the office may not.
        $today = $firstDay->toDateString();
        $taskFields = fn (string $title, int $phase): array => [
            'task_title' => $title,
            'task_description' => $title.' work.',
            'phase_id' => $phases[$phase]->phase_id,
            'technician_id' => $this->mateRecord->technician_id,
            'start_date' => $firstDay->addDay()->toDateString(),
            'due_date' => $firstDay->addDays(2)->toDateString(),
        ];

        foreach ([$this->officer, $this->hr] as $office) {
            $this->assertRefused($this->actingAs($office)->post(route('super-admin.task.store', $project->project_id), $taskFields('Refused', 0)));
        }

        $this->assertSame(0, Task::count());

        $this->actingAs($this->lead)
            ->post(route('technician.tasks.store', $project->project_id), $taskFields('Survey the site', 0))
            ->assertStepWorked()->assertSessionHasNoErrors();
        $this->actingAs($this->lead)
            ->post(route('technician.tasks.store', $project->project_id), $taskFields('Fit the unit', 1))
            ->assertStepWorked()->assertSessionHasNoErrors();

        $this->assertSame(2, Task::count());
        $tasks = Task::query()->orderBy('task_id')->get();

        // --- The next day the work begins.
        $workDay = $firstDay->addDay();
        $this->travelTo($workDay->setTime(9, 0));

        // --- Purchasing releases the plan: the reservation ends.
        $this->actingAs($this->buyer)->postJson(route('inventory.stock.issue-many'), [
            'items' => [['material_id' => $this->copper->material_id, 'quantity' => 10]],
            'movement_date' => $workDay->toDateString(),
            'project_id' => $project->project_id,
        ])->assertOk();

        $this->assertSame(
            ['on_hand' => 20.0, 'reserved' => 0.0, 'available' => 20.0],
            app(StockAvailability::class)->forMaterial($this->copper)
        );

        // --- The technician posts today's update and finishes their tasks.
        $this->actingAs($this->mate)
            ->post(route('technician.tasks.updates.store', $tasks[0]->task_id), ['notes' => 'Survey under way.'])
            ->assertStepWorked()->assertSessionHas('success');
        $this->assertSame(1, TaskUpdate::count());

        foreach ([$this->officer, $this->hr] as $office) {
            $this->assertRefused($this->actingAs($office)->patch(route('super-admin.tasks.complete', $tasks[0]->task_id), []));
        }

        $this->assertNotSame('completed', $tasks[0]->fresh()->status);

        foreach ($tasks as $task) {
            $this->actingAs($this->mate)
                ->post(route('technician.tasks.complete', $task->task_id), [
                    'completion_notes' => 'Done.',
                    'images' => [UploadedFile::fake()->image('done.jpg')],
                ])->assertStepWorked()->assertSessionHasNoErrors();

            $this->assertSame('completed', $task->fresh()->status);
        }

        // --- Materials: the lead may only ask.
        $line = ProjectMaterial::query()->where('project_id', $project->project_id)->firstOrFail();

        $this->actingAs($this->lead)
            ->post(route('technician.projects.materials.record', $project->project_id), [
                'project_material_id' => $line->project_material_id,
                'entry_type' => 'used',
                'quantity' => 7,
                'entry_date' => $workDay->toDateString(),
            ])->assertStepWorked()->assertSessionHas('success');

        $this->actingAs($this->lead)
            ->post(route('technician.projects.materials.requests.store', $project->project_id), [
                'material_id' => $this->copper->material_id,
                'quantity' => 4,
            ])->assertStepWorked()->assertSessionHas('success');

        $materialRequest = MaterialRequest::query()->firstOrFail();

        $this->assertSame(20.0, $this->copper->fresh()->onHand());

        foreach ([$this->hr, $this->lead] as $refused) {
            $this->actingAs($refused)->postJson(route('inventory.material-requests.approve', $materialRequest))->assertForbidden();
        }

        $this->actingAs($this->buyer)->postJson(route('inventory.material-requests.approve', $materialRequest))->assertOk();

        $this->assertSame(16.0, $this->copper->fresh()->onHand());

        // --- Purchasing takes the spare back.
        $this->actingAs($this->buyer)
            ->post(route('inventory.projects.materials.record', $project->project_id), [
                'project_material_id' => $line->project_material_id,
                'entry_type' => 'returned',
                'quantity' => 2,
                'entry_date' => $workDay->toDateString(),
            ])->assertStepWorked()->assertSessionHas('success');

        $this->assertSame(18.0, $this->copper->fresh()->onHand());

        // --- Reports: the office reads, the lead files.
        $this->actingAs($this->lead)
            ->post(route('technician.progress-reports.store'), [
                'project_id' => $project->project_id,
                'remarks' => 'A good day.',
            ])->assertStepWorked()->assertSessionHas('success');

        $report = TechnicianReport::query()->where('report_type', 'progress')->firstOrFail();
        $this->assertTrue($report->isGenerated());

        $this->actingAs($this->lead)
            ->post(route('technician.incident-reports.store'), [
                'project_id' => $project->project_id,
                'incident_type' => 'near_miss',
                'occurred_date' => $workDay->toDateString(),
                'occurred_time' => '08:30',
                'location' => 'indoor_unit',
                'description' => 'A ladder slipped.',
            ])->assertStepWorked()->assertSessionHas('success');

        $incident = IncidentReport::query()->firstOrFail();

        foreach ([$this->officer, $this->hr] as $office) {
            $this->actingAs($office);
            $this->get(route('reports.document', $report->id))->assertOk();
            $this->get(route('super-admin.progress-reports.create'))->assertRedirect();
        }

        $this->actingAs($this->officer)
            ->put(route('super-admin.incident-reports.status', $incident->incident_report_id), ['status' => IncidentReport::STATUS_CLOSED, 'resolution_notes' => 'Ladder replaced.'])
            ->assertStepWorked()->assertSessionHas('success');
        $this->assertSame(IncidentReport::STATUS_CLOSED, $incident->fresh()->status);

        // --- The lead closes the phases and asks to complete.
        foreach ($phases as $phase) {
            $this->actingAs($this->lead)
                ->post(route('technician.projects.phases.complete', [$project->project_id, $phase->phase_id]))
                ->assertStepWorked()->assertSessionHas('success');
        }

        $this->actingAs($this->lead)
            ->post(route('technician.projects.complete', $project->project_id), [
                'completion_summary' => 'All done.',
                'completion_photos' => [UploadedFile::fake()->image('finished.jpg')],
            ])->assertStepWorked()->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertTrue($project->isAwaitingClientConfirmation());

        // Waiting on the client, it takes no more materials.
        $this->assertRefused($this->actingAs($this->lead)->post(route('technician.projects.materials.requests.store', $project->project_id), [
            'material_id' => $this->copper->material_id,
            'quantity' => 1,
        ]));
        $this->assertSame(1, MaterialRequest::count());

        // --- The client confirms.
        $client = $this->account(User::ROLE_CLIENT, ['email' => 'juan.dela.cruz@example.test'] + $this->acceptedTerms());

        $this->actingAs($client)
            ->post(route('public.projects.confirm', $project->project_id))
            ->assertStepWorked()->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertTrue($project->isCompleted(), $project->status);

        // --- Closing out put what was left on the shelf, and no further.
        // Planned 10 + approved 4 went out; 7 used, 2 returned, 5 left over.
        $this->assertSame(23.0, $this->copper->fresh()->onHand());
        $this->assertSame(
            ['on_hand' => 23.0, 'reserved' => 0.0, 'available' => 23.0],
            app(StockAvailability::class)->forMaterial($this->copper)
        );

        // --- A closed record reads for everybody and changes for nobody.
        $this->actingAs($this->officer)->get(route('super-admin.projects.show', $project->project_id))->assertOk();
        $this->actingAs($this->hr)->get(route('super-admin.projects.show', $project->project_id))->assertOk();
        $this->actingAs($this->buyer)->get(route('inventory.projects.show', $project->project_id))->assertOk();
        $this->actingAs($this->lead)->get(route('technician.projects.show', $project->project_id))->assertOk();
        $this->actingAs($this->mate)->get(route('technician.projects.show', $project->project_id))->assertOk();

        $this->assertRefused($this->actingAs($this->buyer)->post(route('inventory.projects.materials.add', $project->project_id), [
            'material_id' => $this->copper->material_id,
            'quantity' => 1,
        ]));
    }

    private function assertRefused($response): void
    {
        $this->assertContains($response->getStatusCode(), [302, 403]);
        $this->assertFalse(session()->has('success'), 'A refused request reported success.');
    }

    /**
     * @return array<string, mixed>
     */
    private function wizardPayload(CarbonImmutable $firstDay): array
    {
        return [
            'client_type' => 'Residential',
            'surname' => 'Dela Cruz',
            'firstname' => 'Juan',
            'middle_name' => 'S',
            'client_email' => 'juan.dela.cruz@example.test',
            'client_phone' => '09123456789',
            ...$this->luzonAddress(),
            'quotation_items' => [['kind' => 'other', 'description' => 'Aircon works', 'quantity' => 1, 'unit_price' => '1250.00']],
            'project_types' => ['Aircon Installation'],
            'assessment_report' => [UploadedFile::fake()->create('assessment.pdf', 12, 'application/pdf')],
            'approved_quotation' => [UploadedFile::fake()->create('quotation.jpg', 12, 'image/jpeg')],
            'target_end_date' => $firstDay->addDays(30)->toDateString(),
            'lead_tech' => $this->leadRecord->technician_id,
            'technicians' => [$this->mateRecord->technician_id],
            'start_date' => $firstDay->toDateString(),
            'end_date' => $firstDay->addDays(2)->toDateString(),
            'materials' => [['material_id' => $this->copper->material_id, 'quantity' => 10]],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function account(string $role, array $attributes = []): User
    {
        $sequence = User::count() + 1;

        return User::create($attributes + [
            'user_code' => sprintf('EMP-%04d', $sequence),
            'name' => 'Person '.$sequence,
            'first_name' => 'Person',
            'last_name' => (string) $sequence,
            'email' => 'person'.$sequence.'@example.test',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'password' => 'correct-password',
        ]);
    }
}
