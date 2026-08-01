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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
}
