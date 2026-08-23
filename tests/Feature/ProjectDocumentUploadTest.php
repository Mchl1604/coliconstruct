<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Support\UploadStore;
use App\Models\Project;
use App\Models\ProjectType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Project documents: several files per type, PDFs and images only.
 *
 * An assessment or a quotation can run to more than one page, so each type
 * holds a list rather than a single file - and uploading adds to that list
 * instead of quietly replacing what was there.
 */
class ProjectDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
    }

    protected function tearDown(): void
    {
        // Written to the real uploads disk rather than a faked one, so the
        // files a test made are cleared up behind it. The suite points that
        // disk's root somewhere disposable - see UPLOADS_ROOT in phpunit.xml.
        foreach (Document::query()->get() as $document) {
            UploadStore::remove($document->document_path);
        }

        parent::tearDown();
    }

    private function project(string $clientType = 'Commercial'): Project
    {
        $project = Project::create([
            'name' => 'Document Project',
            'reference_no' => 'REF-DOCTEST',
            'status' => 'ongoing',
            'address' => '123 Sample Street',
            'description' => 'Description',
            'quotation' => 1000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => $clientType,
            'company_name' => $clientType === 'Commercial' ? 'Acme Corp' : null,
            'firstname' => 'Juan',
            'surname' => 'Dela Cruz',
            'fullname' => 'Juan Dela Cruz',
            'email_address' => 'juan@example.test',
            'contact_number' => '09123456789',
        ]);

        $project->projectTypes()->attach(
            ProjectType::create(['type_name' => 'Aircon Installation'])->type_id
        );

        return $project;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function editPayload(Project $project, array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'company_name' => 'Acme Corp',
            'address' => $project->address,
            'contact_number' => '09123456789',
            'email_address' => 'juan@example.test',
            'quotation' => '1000',
            'project_description' => $project->description,
            'project_types' => $project->projectTypes->pluck('type_id')->all(),
        ], $overrides);
    }

    public function test_the_edit_form_stores_every_file_of_a_type(): void
    {
        $project = $this->project();

        $response = $this->put(
            route('super-admin.projects.update', $project->project_id),
            $this->editPayload($project, [
                'assessmentDocument' => [
                    UploadedFile::fake()->create('page-one.pdf', 12, 'application/pdf'),
                    UploadedFile::fake()->image('page-two.jpg'),
                ],
            ])
        );

        $response->assertSessionHasNoErrors();

        $stored = $project->documents()->where('document_type', 'assessment')->get();

        $this->assertCount(2, $stored);
        // The name it arrived under, so the list reads as the person who
        // uploaded it would expect rather than as a uuid.
        $this->assertEqualsCanonicalizing(
            ['page-one.pdf', 'page-two.jpg'],
            $stored->pluck('document_name')->all()
        );
    }

    public function test_uploading_adds_to_what_the_project_already_holds(): void
    {
        $project = $this->project();

        $this->put(
            route('super-admin.projects.update', $project->project_id),
            $this->editPayload($project, [
                'quotationDocument' => [UploadedFile::fake()->create('first.pdf', 12, 'application/pdf')],
            ])
        )->assertSessionHasNoErrors();

        $this->put(
            route('super-admin.projects.update', $project->project_id),
            $this->editPayload($project, [
                'quotationDocument' => [UploadedFile::fake()->create('second.pdf', 12, 'application/pdf')],
            ])
        )->assertSessionHasNoErrors();

        $names = $project->documents()->where('document_type', 'quotation')->pluck('document_name')->all();

        $this->assertCount(2, $names);
        $this->assertContains('first.pdf', $names);
        $this->assertContains('second.pdf', $names);
    }

    public function test_anything_that_is_not_a_pdf_or_an_image_is_refused(): void
    {
        $project = $this->project();

        $response = $this->put(
            route('super-admin.projects.update', $project->project_id),
            $this->editPayload($project, [
                'assessmentDocument' => [
                    UploadedFile::fake()->create(
                        'notes.docx',
                        12,
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                    ),
                ],
            ])
        );

        $response->assertSessionHasErrors(['assessmentDocument.0']);
        $this->assertSame(0, $project->documents()->count());
    }

    public function test_one_file_can_be_removed_without_touching_the_others(): void
    {
        $project = $this->project();

        $this->put(
            route('super-admin.projects.update', $project->project_id),
            $this->editPayload($project, [
                'assessmentDocument' => [
                    UploadedFile::fake()->create('keep.pdf', 12, 'application/pdf'),
                    UploadedFile::fake()->create('drop.pdf', 12, 'application/pdf'),
                ],
            ])
        )->assertSessionHasNoErrors();

        $doomed = $project->documents()->where('document_name', 'drop.pdf')->firstOrFail();
        $path = $doomed->document_path;

        $this->assertTrue(UploadStore::exists($path));

        $response = $this->deleteJson(route('super-admin.projects.documents.destroy', [
            'id' => $project->project_id,
            'document' => $doomed->document_id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('remaining', 1);

        $this->assertSame(
            ['keep.pdf'],
            $project->documents()->where('document_type', 'assessment')->pluck('document_name')->all()
        );

        // The file on disk goes with the row.
        $this->assertFalse(File::exists($path));
    }

    public function test_a_document_of_another_project_cannot_be_removed(): void
    {
        $project = $this->project();

        $other = Project::create([
            'name' => 'Other Project',
            'reference_no' => 'REF-OTHER',
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
        ]);

        $document = Document::create([
            'project_id' => $other->project_id,
            'document_type' => 'assessment',
            'document_name' => 'other.pdf',
            'document_path' => 'uploads/assessment/other.pdf',
            'uploaded_at' => now(),
        ]);

        $this->deleteJson(route('super-admin.projects.documents.destroy', [
            'id' => $project->project_id,
            'document' => $document->document_id,
        ]))->assertStatus(404);

        $this->assertNotNull($document->fresh());
    }

    public function test_a_completed_projects_documents_cannot_be_removed(): void
    {
        $project = $this->project();

        $document = Document::create([
            'project_id' => $project->project_id,
            'document_type' => 'assessment',
            'document_name' => 'locked.pdf',
            'document_path' => 'uploads/assessment/locked.pdf',
            'uploaded_at' => now(),
        ]);

        $project->update(['status' => 'completed']);

        $response = $this->deleteJson(route('super-admin.projects.documents.destroy', [
            'id' => $project->project_id,
            'document' => $document->document_id,
        ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('can no longer be changed', (string) $response->json('error'));
        $this->assertNotNull($document->fresh());
    }

    /**
     * The screens that read documents group them by type, so every file of a
     * type has to be reachable rather than only the last one uploaded.
     */
    public function test_the_details_page_lists_every_file_of_a_type(): void
    {
        $project = $this->project();

        $this->put(
            route('super-admin.projects.update', $project->project_id),
            $this->editPayload($project, [
                'assessmentDocument' => [
                    UploadedFile::fake()->create('first-page.pdf', 12, 'application/pdf'),
                    UploadedFile::fake()->create('second-page.pdf', 12, 'application/pdf'),
                ],
            ])
        )->assertSessionHasNoErrors();

        $response = $this->get(route('super-admin.projects.show', $project->project_id));

        $response->assertOk();
        $response->assertSee('first-page.pdf');
        $response->assertSee('second-page.pdf');
    }
}
