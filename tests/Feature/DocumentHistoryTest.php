<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentHistory;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use App\Support\UploadStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Tests\TestCase;

/**
 * Every change to a project's files has a history - every document type,
 * and every kind of change: a file uploaded, a quotation replaced, a file
 * removed. Each entry names the file, what happened, who and when, and is
 * written in the same transaction as the change, so a save that fails leaves
 * no entry behind.
 */
class DocumentHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->actingAsSuperAdmin();
    }

    protected function tearDown(): void
    {
        foreach (Document::query()->get() as $document) {
            UploadStore::remove($document->document_path);
        }

        parent::tearDown();
    }

    private function project(string $clientType = 'Commercial', string $status = 'ongoing'): Project
    {
        $project = Project::create([
            'name' => 'History Project',
            'reference_no' => 'REF-HISTORY',
            'status' => $status,
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
     */
    private function save(Project $project, array $overrides = [])
    {
        return $this->put(route('super-admin.projects.update', $project->project_id), array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'company_name' => 'Acme Corp',
            'address' => $project->address,
            'contact_number' => '09123456789',
            'email_address' => 'juan@example.test',
            'quotation' => '1000',
            'quotation_change' => 'none',
            'project_description' => $project->description,
            'project_types' => $project->projectTypes->pluck('type_id')->all(),
        ], $overrides));
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 12, 'application/pdf');
    }

    private function remove(Project $project, Document $document)
    {
        return $this->deleteJson(route('super-admin.projects.documents.destroy', [
            'id' => $project->project_id,
            'document' => $document->document_id,
        ]));
    }

    /** @return array<int, array{string, string, string}> type, name, event - oldest first */
    private function history(Project $project): array
    {
        return DocumentHistory::query()
            ->where('project_id', $project->project_id)
            ->orderBy('document_history_id')
            ->get()
            ->map(fn (DocumentHistory $entry): array => [$entry->document_type, $entry->document_name, $entry->event])
            ->all();
    }

    // ------------------------------------------------------------------
    // Every kind of change, every type
    // ------------------------------------------------------------------

    public function test_uploading_a_file_of_any_type_is_recorded(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-11 09:15:00'));

        $project = $this->project();

        $this->save($project, [
            'assessmentDocument' => [$this->pdf('site-assessment.pdf')],
            'contractDocument' => [$this->pdf('signed-contract.pdf')],
            'quotation_change' => 'file',
            'quotationDocument' => [$this->pdf('quotation.pdf')],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([
            ['assessment', 'site-assessment.pdf', DocumentHistory::EVENT_UPLOADED],
            ['quotation', 'quotation.pdf', DocumentHistory::EVENT_UPLOADED],
            ['contract', 'signed-contract.pdf', DocumentHistory::EVENT_UPLOADED],
        ], $this->history($project));

        $entry = DocumentHistory::query()->where('document_type', 'assessment')->sole();
        $document = $project->documents()->where('document_type', 'assessment')->sole();

        $this->assertSame($document->document_id, (int) $entry->document_id);
        $this->assertSame($this->admin->id, (int) $entry->actor_id);
        $this->assertSame('Test Administrator', $entry->actor_name);
        $this->assertSame(User::ROLE_SUPER_ADMIN, $entry->actor_role);
        $this->assertSame('2026-09-11 09:15:00', $entry->created_at->format('Y-m-d H:i:s'));
    }

    public function test_replacing_a_quotation_records_both_files(): void
    {
        $project = $this->project();

        $this->save($project, [
            'quotation_change' => 'file',
            'quotationDocument' => [$this->pdf('quotation-v1.pdf')],
        ])->assertSessionHasNoErrors();

        $this->save($project, [
            'quotation' => '1800',
            'quotation_change' => 'both',
            'quotationDocument' => [$this->pdf('quotation-v2.pdf')],
        ])->assertSessionHasNoErrors();

        $this->assertSame([
            ['quotation', 'quotation-v1.pdf', DocumentHistory::EVENT_UPLOADED],
            ['quotation', 'quotation-v1.pdf', DocumentHistory::EVENT_REPLACED],
            ['quotation', 'quotation-v2.pdf', DocumentHistory::EVENT_UPLOADED],
        ], $this->history($project));

        // The replaced file is kept, so its entry still opens it.
        $replaced = DocumentHistory::query()->where('event', DocumentHistory::EVENT_REPLACED)->sole();
        $this->assertNotNull($replaced->document);
        $this->assertTrue($replaced->document->isSuperseded());
    }

    public function test_removing_a_file_is_recorded_after_the_file_is_gone(): void
    {
        $project = $this->project();

        $this->save($project, [
            'assessmentDocument' => [$this->pdf('keep.pdf'), $this->pdf('wrong-site.pdf')],
        ])->assertSessionHasNoErrors();

        $doomed = $project->documents()->where('document_name', 'wrong-site.pdf')->sole();
        $path = $doomed->document_path;

        $this->remove($project, $doomed)->assertOk();

        $this->assertNull(Document::find($doomed->document_id));
        $this->assertFalse(UploadStore::exists($path));

        $entry = DocumentHistory::query()->where('event', DocumentHistory::EVENT_REMOVED)->sole();

        // Named and typed from the snapshot, since the row it describes has
        // gone - and it no longer links anywhere.
        $this->assertSame('wrong-site.pdf', $entry->document_name);
        $this->assertSame('assessment', $entry->document_type);
        $this->assertSame($doomed->document_id, (int) $entry->document_id);
        $this->assertSame($this->admin->id, (int) $entry->actor_id);
        $this->assertNull($entry->document);
    }

    // ------------------------------------------------------------------
    // Nothing is recorded for a change that did not happen
    // ------------------------------------------------------------------

    public function test_a_failed_save_records_no_file_history(): void
    {
        $project = $this->project();

        Document::creating(function (): void {
            throw new RuntimeException('The file could not be recorded.');
        });

        $this->save($project, [
            'assessmentDocument' => [$this->pdf('assessment.pdf')],
        ])->assertSessionHas('error');

        $this->assertSame([], $this->history($project));
    }

    public function test_a_refused_save_records_no_file_history(): void
    {
        $project = $this->project();

        // A quotation upload nobody confirmed is refused whole.
        $this->save($project, [
            'assessmentDocument' => [$this->pdf('assessment.pdf')],
            'quotationDocument' => [$this->pdf('quotation.pdf')],
        ])->assertSessionHasErrors('quotation_change');

        $this->assertSame([], $this->history($project));
    }

    public function test_a_refused_removal_records_no_file_history(): void
    {
        $project = $this->project();

        $this->save($project, [
            'assessmentDocument' => [$this->pdf('locked.pdf')],
        ])->assertSessionHasNoErrors();

        $document = $project->documents()->sole();
        $project->update(['status' => 'completed']);

        $this->remove($project, $document)->assertStatus(422);

        $this->assertSame([
            ['assessment', 'locked.pdf', DocumentHistory::EVENT_UPLOADED],
        ], $this->history($project));
    }

    public function test_someone_outside_the_office_cannot_create_file_history(): void
    {
        $project = $this->project();

        $this->save($project, [
            'assessmentDocument' => [$this->pdf('assessment.pdf')],
        ])->assertSessionHasNoErrors();

        $document = $project->documents()->sole();

        $technician = User::create([
            'user_code' => 'TEC-1234',
            'name' => 'Tech Person',
            'first_name' => 'Tech',
            'last_name' => 'Person',
            'email' => 'tech@example.test',
            'role' => User::ROLE_TECHNICIAN,
            'status' => User::STATUS_ACTIVE,
            'is_archived' => false,
            'must_change_password' => false,
            'password' => 'password',
        ]);

        $this->actingAs($technician);

        $this->save($project, ['assessmentDocument' => [$this->pdf('sneaky.pdf')]])->assertRedirect();
        $this->remove($project, $document)->assertForbidden();

        $this->assertSame([
            ['assessment', 'assessment.pdf', DocumentHistory::EVENT_UPLOADED],
        ], $this->history($project));
    }

    // ------------------------------------------------------------------
    // Where it is read
    // ------------------------------------------------------------------

    public function test_each_document_type_has_its_own_history_button_and_dialog(): void
    {
        $project = $this->project();

        $this->save($project, [
            'assessmentDocument' => [$this->pdf('assessment-a.pdf'), $this->pdf('assessment-b.pdf')],
            'contractDocument' => [$this->pdf('contract.pdf')],
            'quotation_change' => 'file',
            'quotationDocument' => [$this->pdf('quotation.pdf')],
        ])->assertSessionHasNoErrors();

        $this->remove($project, $project->documents()->where('document_name', 'assessment-b.pdf')->sole())
            ->assertOk();

        $page = $this->get(route('super-admin.projects.show', $project->project_id))->assertOk();

        // Icon-only buttons, named for a screen reader and a tooltip.
        foreach (['assessment', 'quotation', 'contract'] as $type) {
            $page->assertSee('aria-label="View '.$type.' history"', false);
        }

        $page->assertSee('id="assessmentHistoryModal"', false);
        $page->assertSee('id="contractHistoryModal"', false);
        $page->assertSee('id="quotationHistoryModal"', false);

        // The removed file is still named in its history, marked deleted.
        $page->assertSee('assessment-b.pdf');
        $page->assertSee('File deleted');
        $page->assertSee('data-event="removed"', false);
    }

    public function test_the_quotation_history_button_is_an_icon_without_a_label(): void
    {
        $project = $this->project();

        $this->save($project, [
            'quotation' => '1500',
            'quotation_change' => 'amount',
        ])->assertSessionHasNoErrors();

        $content = $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('aria-label="View quotation history"', false)
            ->getContent();

        // Only the dialog's own title says "Quotation History" - the button
        // that opens it carries the icon alone.
        preg_match('/<button[^>]*aria-label="View quotation history"[^>]*>(.*?)<\/button>/s', $content, $button);

        $this->assertNotEmpty($button);
        $this->assertStringContainsString('bi-clock-history', $button[1]);
        $this->assertSame('', trim(strip_tags($button[1])));
    }

    public function test_a_project_with_no_file_changes_draws_no_history_buttons(): void
    {
        $project = $this->project();

        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertDontSee('aria-label="View assessment history"', false)
            ->assertDontSee('aria-label="View quotation history"', false)
            ->assertDontSee('id="assessmentHistoryModal"', false);
    }
}
