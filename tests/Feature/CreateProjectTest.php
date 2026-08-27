<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\ProjectType;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Skill;
use App\Models\Technician;
use App\Models\User;
use App\Services\ProjectEmails;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreateProjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every administrative route is behind `auth` and a role check.
        $this->actingAsSuperAdmin();
    }

    private function createWizardTechnician(string $role, string $name): Technician
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
        ]);

        $user->forceFill([
            'role' => $role,
        ])->save();

        return Technician::create([
            'account_id' => $user->id,
            'role' => $role,
        ]);
    }

    /**
     * Dates are relative to today so the suite keeps working as time passes -
     * start_date is validated with after_or_equal:today.
     */
    private function scheduleStart(): string
    {
        return CarbonImmutable::today()->addDays(10)->toDateString();
    }

    private function scheduleEnd(): string
    {
        return CarbonImmutable::today()->addDays(12)->toDateString();
    }

    private function baseProjectPayload(Technician $leadTechnician, Technician $technician, bool $includeContract = false): array
    {
        $payload = [
            'client_type' => 'Residential',
            'surname' => 'Dela Cruz',
            'firstname' => 'Juan',
            'middle_name' => 'S',
            'client_email' => 'juan.dela.cruz@example.test',
            'client_phone' => '09123456789',
            'project_address' => '123 Sample Street, Sample City',
            'quotation_amount' => '1250.00',
            'project_types' => ['Aircon Installation'],
            // Each document type takes an array of files, which is what the
            // wizard's inputs now post.
            'assessment_report' => [UploadedFile::fake()->create('assessment.pdf', 12, 'application/pdf')],
            'approved_quotation' => [UploadedFile::fake()->create('quotation.jpg', 12, 'image/jpeg')],
            'project_description' => 'Test project description',
            'lead_tech' => $leadTechnician->technician_id,
            'technicians' => [$technician->technician_id],
            'start_date' => $this->scheduleStart(),
            'end_date' => $this->scheduleEnd(),
        ];

        if ($includeContract) {
            $payload['client_type'] = 'Commercial';
            $payload['company_name'] = 'Acme Corp';
            // A PDF, not a .docx: project documents are PDFs and images only.
            $payload['contract'] = [UploadedFile::fake()->create('contract.pdf', 12, 'application/pdf')];
        }

        return $payload;
    }

    /**
     * The wizard asks for dates once, which reads like the whole of a
     * project's schedule - so the step says in as many words that it is not.
     *
     * Copy only: the assertions below this one cover the behaviour, and none
     * of it changes.
     */
    public function test_the_schedule_step_says_the_dates_are_only_the_initial_schedule(): void
    {
        $page = $this->get(route('super-admin.projects.create'))->assertOk();

        $page->assertSee('Input Project Initial Schedule');
        $page->assertSee('This is the initial schedule for the project.');
        $page->assertSee('More schedule dates can be added later.');

        // The note has to be read BEFORE the dates are filled in, so it must
        // come above the date inputs in the document rather than sitting under
        // them as a footnote.
        $body = $page->getContent();

        $this->assertLessThan(
            strpos($body, 'id="startDate"'),
            strpos($body, 'More schedule dates can be added later.'),
            'The explanatory note must appear before the date inputs.'
        );

        // And the wizard must not promise a fuller schedule anywhere else on
        // the way through: not on the progress rail, and not on the review
        // panel that is the last thing read before Create.
        $page->assertSee('Initial Schedule &amp; Team', false);
        $page->assertDontSee('>Schedule &amp; Team<', false);
    }

    public function test_the_schedule_step_still_offers_every_scheduling_control(): void
    {
        // The copy change must leave the controls themselves alone: the date
        // range, the partial-day mode, the lead and the crew.
        $page = $this->get(route('super-admin.projects.create'))->assertOk();

        foreach ([
            'id="startDate"',
            'id="endDate"',
            // The lead is picked from the same dropdown the technicians use,
            // so what has to be present is the button, its menu and the hidden
            // input that carries the choice.
            'data-lead-tech-button',
            'data-lead-tech-menu',
            'name="lead_tech"',
            'data-scheduling-mode-radio',
            'data-schedule-date-input',
            'data-technician-selected-list',
        ] as $control) {
            $page->assertSee($control, false);
        }
    }

    /**
     * The quotation is typed with thousands separators, and submitted without
     * them: the visible field carries the commas and the hidden one carries
     * the number, so `numeric` validation is handed what it always was.
     */
    public function test_the_quotation_field_is_paired_for_grouped_input(): void
    {
        $page = $this->get(route('super-admin.projects.create'))->assertOk();

        $page->assertSee('data-money-field', false);
        $page->assertSee('data-money-input', false);
        $page->assertSee('data-money-value', false);
        $page->assertSee('/js/moneyInput.js', false);

        // The name is on the hidden half, and the visible half has none - or
        // the browser would submit the comma-formatted text instead.
        $page->assertSee('<input type="hidden" name="quotation_amount"', false);
        $page->assertDontSee('type="number" name="quotation_amount"', false);
    }

    /**
     * The promise the new copy makes, kept.
     *
     * "More schedule dates can be added later" is only worth printing if it is
     * true, so this walks the whole way: create the project through the wizard
     * with its single initial range, then add a second one through the
     * Schedules page the way an administrator would. Both ranges survive - the
     * initial one is not replaced.
     */
    public function test_a_second_schedule_range_can_be_added_after_the_project_is_created(): void
    {
        ProjectType::create(['type_name' => 'Aircon Installation']);

        $leadTechnician = $this->createWizardTechnician('lead_technician', 'Lead Technician');
        $technician = $this->createWizardTechnician('technician', 'Juan Technician');

        $this->post(
            route('super-admin.projects.create.store'),
            $this->baseProjectPayload($leadTechnician, $technician)
        )->assertRedirect(route('super-admin.projects'));

        $project = Project::firstOrFail();
        $initial = $project->schedules()->firstOrFail();

        $this->assertDatabaseCount('tbl_schedule', 1);

        // The existing range is resubmitted alongside a new one, which is what
        // the Schedules editor posts when a range is added to a project.
        $this->put(route('super-admin.schedules.update', $project->project_id), [
            'ranges' => [
                [
                    'schedule_id' => $initial->schedule_id,
                    'scheduling_mode' => Schedule::MODE_DATE_BASED,
                    'start_date' => $this->scheduleStart(),
                    'end_date' => $this->scheduleEnd(),
                ],
                [
                    'scheduling_mode' => Schedule::MODE_DATE_BASED,
                    'start_date' => CarbonImmutable::today()->addDays(20)->toDateString(),
                    'end_date' => CarbonImmutable::today()->addDays(22)->toDateString(),
                ],
            ],
        ])->assertSessionHas('success');

        $this->assertDatabaseCount('tbl_schedule', 2);

        // The initial range is still there, unchanged - the second was added
        // to it rather than replacing it.
        $this->assertDatabaseHas('tbl_schedule', [
            'schedule_id' => $initial->schedule_id,
            'project_id' => $project->project_id,
        ]);

        $this->assertSame(
            [$this->scheduleStart(), CarbonImmutable::today()->addDays(20)->toDateString()],
            $project->fresh()->schedules
                ->map(fn (Schedule $schedule): string => $schedule->startsOn()->toDateString())
                ->sort()
                ->values()
                ->all()
        );
    }

    public function test_it_stores_a_residential_project_and_omits_the_contract_document(): void
    {
        ProjectType::create(['type_name' => 'Aircon Installation']);

        $skill = Skill::create(['skill_name' => 'Aircon Installation']);

        $leadTechnician = $this->createWizardTechnician('lead_technician', 'Lead Technician');
        $technician = $this->createWizardTechnician('technician', 'Juan Technician');

        DB::table('tbl_skill_map')->insert([
            'technician_id' => $technician->technician_id,
            'skill_id' => $skill->skill_id,
        ]);

        $response = $this->post(route('super-admin.projects.create.store'), $this->baseProjectPayload($leadTechnician, $technician));

        $response->assertRedirect(route('super-admin.projects'));

        $this->assertDatabaseCount('tbl_projects', 1);
        $this->assertDatabaseHas('tbl_projects', [
            'name' => 'Juan S Dela Cruz',
            'status' => 'pending',
            'address' => '123 Sample Street, Sample City',
            'quotation' => '1250.00',
        ]);

        $this->assertDatabaseHas('tbl_clients', [
            'client_type' => 'Residential',
            'firstname' => 'Juan',
            'surname' => 'Dela Cruz',
            'email_address' => 'juan.dela.cruz@example.test',
        ]);

        $this->assertDatabaseCount('tbl_project_technicians', 2);
        $this->assertDatabaseCount('tbl_schedule', 1);
        $this->assertDatabaseCount('tbl_schedule_technicians', 2);
        $this->assertDatabaseCount('tbl_documents', 2);
        $this->assertDatabaseMissing('tbl_documents', [
            'document_type' => 'contract',
        ]);
    }

    public function test_it_rejects_overlapping_schedules_for_selected_technicians(): void
    {
        ProjectType::create(['type_name' => 'Aircon Installation']);

        $skill = Skill::create(['skill_name' => 'Aircon Installation']);

        $leadTechnician = $this->createWizardTechnician('lead_technician', 'Lead Technician');
        $technician = $this->createWizardTechnician('technician', 'Juan Technician');

        DB::table('tbl_skill_map')->insert([
            'technician_id' => $technician->technician_id,
            'skill_id' => $skill->skill_id,
        ]);

        $this->bookTechnician($technician, $this->scheduleStart(), $this->scheduleEnd());

        $response = $this->post(route('super-admin.projects.create.store'), $this->baseProjectPayload($leadTechnician, $technician));

        $response->assertSessionHasErrors(['start_date', 'end_date']);
        $this->assertDatabaseCount('tbl_projects', 1);
    }

    /**
     * The bug this guards against: the range's first and last day are both
     * free, but a day in the MIDDLE is already booked. Endpoint-only or
     * naive checks let this through; continuous availability must not.
     */
    public function test_it_rejects_a_range_whose_middle_days_are_unavailable(): void
    {
        ProjectType::create(['type_name' => 'Aircon Installation']);

        $skill = Skill::create(['skill_name' => 'Aircon Installation']);
        $leadTechnician = $this->createWizardTechnician('lead_technician', 'Lead Technician');
        $technician = $this->createWizardTechnician('technician', 'Juan Technician');

        DB::table('tbl_skill_map')->insert([
            'technician_id' => $technician->technician_id,
            'skill_id' => $skill->skill_id,
        ]);

        // Wizard asks for day 10 -> day 12. Day 10 and day 12 are free, but
        // the technician is already booked on day 11.
        $middleDay = CarbonImmutable::today()->addDays(11)->toDateString();
        $this->bookTechnician($technician, $middleDay, $middleDay);

        $response = $this->post(
            route('super-admin.projects.create.store'),
            $this->baseProjectPayload($leadTechnician, $technician)
        );

        $response->assertSessionHasErrors(['start_date', 'end_date']);
        $this->assertStringContainsString(
            'is unavailable on',
            session('errors')->first('start_date')
        );
        $this->assertDatabaseCount('tbl_projects', 1);
    }

    public function test_it_ignores_bookings_on_completed_and_cancelled_projects(): void
    {
        ProjectType::create(['type_name' => 'Aircon Installation']);

        $skill = Skill::create(['skill_name' => 'Aircon Installation']);
        $leadTechnician = $this->createWizardTechnician('lead_technician', 'Lead Technician');
        $technician = $this->createWizardTechnician('technician', 'Juan Technician');

        DB::table('tbl_skill_map')->insert([
            'technician_id' => $technician->technician_id,
            'skill_id' => $skill->skill_id,
        ]);

        // Same dates the wizard is about to request, but on projects whose
        // status no longer blocks a technician.
        $this->bookTechnician($technician, $this->scheduleStart(), $this->scheduleEnd(), 'completed');
        $this->bookTechnician($technician, $this->scheduleStart(), $this->scheduleEnd(), 'cancelled');

        $response = $this->post(
            route('super-admin.projects.create.store'),
            $this->baseProjectPayload($leadTechnician, $technician)
        );

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('tbl_projects', 3);
    }

    /**
     * Book a technician on another project for the given inclusive date range.
     */
    private function bookTechnician(
        Technician $technician,
        string $startDate,
        string $endDate,
        string $projectStatus = 'ongoing'
    ): void {
        $existingProject = Project::create([
            'name' => 'Existing Project',
            'status' => $projectStatus,
            'address' => 'Existing Address',
            'description' => 'Existing description',
        ]);

        $projectTechnician = ProjectTechnician::create([
            'project_id' => $existingProject->project_id,
            'technician_id' => $technician->technician_id,
        ]);

        $existingSchedule = Schedule::create([
            'project_id' => $existingProject->project_id,
            'start_datetime' => $startDate.' 00:00:00',
            'end_datetime' => $endDate.' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Existing booking',
        ]);

        ScheduleTechnician::create([
            'schedule_id' => $existingSchedule->schedule_id,
            'project_technician_id' => $projectTechnician->project_technician_id,
        ]);
    }

    public function test_it_rejects_a_negative_quotation_amount(): void
    {
        ProjectType::create(['type_name' => 'Aircon Installation']);

        $skill = Skill::create(['skill_name' => 'Aircon Installation']);

        $leadTechnician = $this->createWizardTechnician('lead_technician', 'Lead Technician');
        $technician = $this->createWizardTechnician('technician', 'Juan Technician');

        DB::table('tbl_skill_map')->insert([
            'technician_id' => $technician->technician_id,
            'skill_id' => $skill->skill_id,
        ]);

        $payload = $this->baseProjectPayload($leadTechnician, $technician);
        $payload['quotation_amount'] = '-10';

        $response = $this->post(route('super-admin.projects.create.store'), $payload);

        $response->assertSessionHasErrors(['quotation_amount']);
        $this->assertDatabaseCount('tbl_projects', 0);
    }

    /**
     * A document past the size limit is a field error on the wizard, not a
     * blunt "content too large" page - provided it is inside PHP's own
     * post_max_size, which is what keeps the request reaching Laravel at all.
     */
    public function test_an_oversized_document_is_refused_by_name(): void
    {
        ProjectType::create(['type_name' => 'Aircon Installation']);

        $leadTechnician = $this->createWizardTechnician('lead_technician', 'Lead Technician');
        $technician = $this->createWizardTechnician('technician', 'Juan Technician');

        $payload = $this->baseProjectPayload($leadTechnician, $technician);
        $payload['assessment_report'] = [UploadedFile::fake()->create(
            'assessment.pdf',
            Document::MAX_KILOBYTES + 1,
            'application/pdf'
        )];

        // Named per file now, since a type may carry several of them.
        $this->post(route('super-admin.projects.create.store'), $payload)
            ->assertSessionHasErrors(['assessment_report.0']);

        $this->assertDatabaseCount('tbl_projects', 0);
    }

    /**
     * Anything that goes wrong while saving comes back as a toast on the
     * wizard with the typed details still in place - never a stack trace, and
     * never a half-made project.
     */
    public function test_a_failure_while_saving_returns_a_toast_and_leaves_nothing_behind(): void
    {
        ProjectType::create(['type_name' => 'Aircon Installation']);

        $leadTechnician = $this->createWizardTechnician('lead_technician', 'Lead Technician');
        $technician = $this->createWizardTechnician('technician', 'Juan Technician');

        // Stand in for anything that can fail mid-save. A missing table makes
        // the insert throw a QueryException, which is the realistic shape of a
        // database fault - and the case that must not put SQL in a toast.
        //
        // Documents are written inside the transaction and read nowhere during
        // validation, so removing this table fails the save and only the save.
        Schema::drop('tbl_documents');

        $response = $this->from(route('super-admin.projects.create'))
            ->post(route('super-admin.projects.create.store'), $this->baseProjectPayload($leadTechnician, $technician));

        // Back to the wizard, with a message a person can read.
        $response->assertRedirect(route('super-admin.projects.create'));
        $response->assertSessionHas('error');

        $message = (string) session('error');

        $this->assertStringContainsString('Unable to create project', $message);
        // Debug mode appends the underlying fault, which is what debug mode is
        // for - the test environment runs with it on.
        $this->assertTrue(config('app.debug'));
        $this->assertStringContainsString('SQLSTATE', $message);

        // The project and its client rolled back with the failure.
        $this->assertDatabaseCount('tbl_projects', 0);
        $this->assertDatabaseCount('tbl_clients', 0);
    }

    /**
     * With debug off - which is how this runs in production - the toast says
     * what happened and nothing else. A raw query, the table names and the
     * driver all stay in the log where they belong.
     */
    public function test_a_database_fault_never_leaks_sql_into_the_toast(): void
    {
        config(['app.debug' => false]);

        ProjectType::create(['type_name' => 'Aircon Installation']);

        $leadTechnician = $this->createWizardTechnician('lead_technician', 'Lead Technician');
        $technician = $this->createWizardTechnician('technician', 'Juan Technician');

        Schema::drop('tbl_documents');

        $this->from(route('super-admin.projects.create'))
            ->post(route('super-admin.projects.create.store'), $this->baseProjectPayload($leadTechnician, $technician))
            ->assertRedirect(route('super-admin.projects.create'));

        $message = (string) session('error');

        $this->assertStringContainsString('Unable to create project', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('insert into', $message);
        $this->assertStringNotContainsString('tbl_documents', $message);
    }

    /**
     * The reverse case: the project saved, but a follow-up did not. The
     * administrator must still be told it worked, because it did.
     */
    public function test_a_failing_follow_up_does_not_report_a_saved_project_as_failed(): void
    {
        ProjectType::create(['type_name' => 'Aircon Installation']);

        $leadTechnician = $this->createWizardTechnician('lead_technician', 'Lead Technician');
        $technician = $this->createWizardTechnician('technician', 'Juan Technician');

        // The client's welcome email blows up after the project is committed.
        $this->mock(ProjectEmails::class, function ($mock): void {
            $mock->shouldReceive('projectCreated')
                ->andThrow(new \RuntimeException('The mail server refused the connection.'));
            $mock->shouldReceive('documentUploaded')->andReturnNull();
        });

        $response = $this->post(
            route('super-admin.projects.create.store'),
            $this->baseProjectPayload($leadTechnician, $technician)
        );

        $response->assertRedirect(route('super-admin.projects'));
        $response->assertSessionHas('success', 'Project created successfully.');

        $this->assertDatabaseCount('tbl_projects', 1);
    }
}
