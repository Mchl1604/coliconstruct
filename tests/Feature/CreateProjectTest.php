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
            'middle_name' => 'Santos',
            'client_email' => 'juan.dela.cruz@example.test',
            'client_phone' => '09123456789',
            'project_address' => '123 Sample Street, Sample City',
            'quotation_amount' => '1250.00',
            'project_types' => ['Aircon Installation'],
            'assessment_report' => UploadedFile::fake()->create('assessment.pdf', 12, 'application/pdf'),
            'approved_quotation' => UploadedFile::fake()->create('quotation.jpg', 12, 'image/jpeg'),
            'project_description' => 'Test project description',
            'lead_tech' => $leadTechnician->technician_id,
            'technicians' => [$technician->technician_id],
            'start_date' => $this->scheduleStart(),
            'end_date' => $this->scheduleEnd(),
        ];

        if ($includeContract) {
            $payload['client_type'] = 'Commercial';
            $payload['company_name'] = 'Acme Corp';
            $payload['contract'] = UploadedFile::fake()->create('contract.docx', 12, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        }

        return $payload;
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
            'name' => 'Juan Santos Dela Cruz',
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
        $payload['assessment_report'] = UploadedFile::fake()->create(
            'assessment.pdf',
            Document::MAX_KILOBYTES + 1,
            'application/pdf'
        );

        $this->post(route('super-admin.projects.create.store'), $payload)
            ->assertSessionHasErrors(['assessment_report']);

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

        $this->assertStringContainsString('could not be created', $message);
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

        $this->assertStringContainsString('could not be created', $message);
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
