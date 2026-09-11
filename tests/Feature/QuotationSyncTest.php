<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\QuotationHistory;
use App\Models\User;
use App\Support\UploadStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * The quotation amount and the quotation file, changed together or apart.
 *
 * They are separate pieces of project data, so either may change on its own
 * - but a save that changes only one of them is asked about the other first,
 * and saves nothing until that is answered. The asking is quotationSync.js on
 * the edit dialog; its answer arrives as `quotation_change`, and what these
 * tests hold the server to is that the answer is obeyed exactly:
 *
 *   - a new amount with "keep the file" keeps the file;
 *   - a new file with "keep the amount" keeps the amount;
 *   - a change nobody confirmed - Cancel sends nothing, and anything else
 *     that arrives without an answer is treated the same way - saves nothing;
 *   - an amount change writes one history row, a file change writes none,
 *     and a save that fails writes none either.
 */
class QuotationSyncTest extends TestCase
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
        // Written to the real uploads disk rather than a faked one, so the
        // files a test made are cleared up behind it - superseded ones
        // included, which is every row rather than only the current ones.
        foreach (Document::query()->get() as $document) {
            UploadStore::remove($document->document_path);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function project(string $status = 'ongoing'): Project
    {
        $project = Project::create([
            'name' => 'Quotation Project',
            'reference_no' => 'REF-QUOTE',
            'status' => $status,
            'address' => '123 Sample Street',
            'description' => 'Description',
            'quotation' => 1000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Residential',
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
     * The quotation file a project already has on record, written to the
     * disk the way an upload would be.
     */
    private function existingQuotation(Project $project, string $name = 'original-quotation.pdf'): Document
    {
        return Document::create([
            'project_id' => $project->project_id,
            'document_type' => 'quotation',
            'document_name' => $name,
            'document_path' => UploadStore::put(
                UploadedFile::fake()->create($name, 12, 'application/pdf'),
                'documents'
            ),
            'uploaded_at' => now()->subWeek(),
        ]);
    }

    private function pdf(string $name = 'revised-quotation.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 12, 'application/pdf');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Project $project, array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'address' => $project->address,
            'contact_number' => '09123456789',
            'email_address' => 'juan@example.test',
            'quotation' => '1000',
            'quotation_change' => 'none',
            'project_description' => $project->description,
            'project_types' => $project->projectTypes->pluck('type_id')->all(),
        ], $overrides);
    }

    private function save(Project $project, array $overrides = [])
    {
        return $this->put(
            route('super-admin.projects.update', $project->project_id),
            $this->payload($project, $overrides)
        );
    }

    /** @return array<int, string> */
    private function currentQuotationNames(Project $project): array
    {
        return $project->documents()
            ->where('document_type', 'quotation')
            ->orderBy('document_id')
            ->pluck('document_name')
            ->all();
    }

    private function account(string $role): User
    {
        return User::create([
            'user_code' => strtoupper(substr($role, 0, 3)).'-'.random_int(1000, 9999),
            'name' => ucfirst($role).' Person',
            'first_name' => ucfirst($role),
            'last_name' => 'Person',
            'email' => $role.'@example.test',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'is_archived' => false,
            'must_change_password' => false,
            'password' => 'password',
        ] + ($role === User::ROLE_CLIENT ? $this->acceptedTerms() : []));
    }

    /**
     * Nothing about the quotation moved: the amount, the file on record, and
     * the history are all exactly as the fixture left them.
     */
    private function assertQuotationUntouched(Project $project, Document $original): void
    {
        $this->assertSame('1000.00', $project->refresh()->quotation);
        $this->assertSame(['original-quotation.pdf'], $this->currentQuotationNames($project));
        $this->assertNull($original->refresh()->superseded_at);
        $this->assertSame(1, Document::query()->where('project_id', $project->project_id)->count());
        $this->assertSame(0, QuotationHistory::query()->count());
    }

    // ------------------------------------------------------------------
    // The amount changed
    // ------------------------------------------------------------------

    public function test_changing_the_amount_and_keeping_the_existing_file(): void
    {
        $project = $this->project();
        $original = $this->existingQuotation($project);

        $response = $this->save($project, [
            'quotation' => '1500',
            'quotation_change' => 'amount',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('super-admin.projects.show', $project->project_id));

        // Said in so many words: the person chose to keep the file, and the
        // confirmation says it was kept.
        $response->assertSessionHas('success', function (string $message): bool {
            return str_contains($message, '₱1,500.00')
                && str_contains($message, 'The uploaded quotation file was not replaced.');
        });

        $this->assertSame('1500.00', $project->refresh()->quotation);

        // The very same file, still current, untouched.
        $this->assertSame(['original-quotation.pdf'], $this->currentQuotationNames($project));
        $this->assertNull($original->refresh()->superseded_at);
        $this->assertTrue(UploadStore::exists($original->document_path));
    }

    public function test_changing_the_amount_and_replacing_the_file(): void
    {
        $project = $this->project();
        $original = $this->existingQuotation($project);

        $response = $this->save($project, [
            'quotation' => '1500',
            'quotation_change' => 'both',
            'quotationDocument' => [$this->pdf()],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'the quotation file was replaced'));

        $this->assertSame('1500.00', $project->refresh()->quotation);
        $this->assertSame(['revised-quotation.pdf'], $this->currentQuotationNames($project));

        // Replaced, not deleted: the row and the bytes are both kept, and the
        // row says who replaced it.
        $original->refresh();
        $this->assertNotNull($original->superseded_at);
        $this->assertSame($this->admin->id, (int) $original->superseded_by);
        $this->assertTrue(UploadStore::exists($original->document_path));
        $this->assertSame(
            ['original-quotation.pdf'],
            $project->supersededQuotations()->pluck('document_name')->all()
        );
    }

    /**
     * Cancel is the dialog going back to the form: it sends nothing, so the
     * project cannot change. What the server adds is that nothing reaches
     * the database WITHOUT an answer either - a changed amount that arrives
     * unconfirmed is refused whole, the rest of the edit with it, rather
     * than saved on the assumption that the file should stay.
     */
    public function test_cancelling_after_changing_the_amount_saves_nothing(): void
    {
        $project = $this->project();
        $original = $this->existingQuotation($project);

        // The question and its Cancel are on the page, ready to be asked.
        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('data-quotation-sync', false)
            ->assertSee('data-quotation-sync-cancel', false)
            ->assertSee('data-quotation-change', false);

        $response = $this->save($project, [
            'quotation' => '1500',
            'address' => 'Somewhere Else',
            'quotation_change' => 'none',
        ]);

        $response->assertSessionHasErrors('quotation_change');

        $this->assertQuotationUntouched($project, $original);
        $this->assertSame('123 Sample Street', $project->refresh()->address);
    }

    // ------------------------------------------------------------------
    // The file changed
    // ------------------------------------------------------------------

    public function test_replacing_the_file_and_keeping_the_existing_amount(): void
    {
        $project = $this->project();
        $original = $this->existingQuotation($project);

        $response = $this->save($project, [
            'quotation' => '1000',
            'quotation_change' => 'file',
            'quotationDocument' => [$this->pdf()],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'The quotation amount stayed at ₱1,000.00.'));

        $this->assertSame('1000.00', $project->refresh()->quotation);
        $this->assertSame(['revised-quotation.pdf'], $this->currentQuotationNames($project));
        $this->assertNotNull($original->refresh()->superseded_at);
    }

    public function test_replacing_the_file_and_changing_the_amount(): void
    {
        $project = $this->project();
        $original = $this->existingQuotation($project);

        // "Yes, update quotation amount", and a new figure entered.
        $response = $this->save($project, [
            'quotation' => '1250.50',
            'quotation_change' => 'both',
            'quotationDocument' => [$this->pdf()],
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertSame('1250.50', $project->refresh()->quotation);
        $this->assertSame(['revised-quotation.pdf'], $this->currentQuotationNames($project));
        $this->assertNotNull($original->refresh()->superseded_at);

        $history = QuotationHistory::query()->sole();
        $this->assertSame('1000.00', $history->previous_amount);
        $this->assertSame('1250.50', $history->new_amount);
        $this->assertTrue($history->file_replaced);
    }

    /**
     * "Yes, update quotation amount" may simply confirm the figure that is
     * already there. That replaces the file and is not an amount change.
     */
    public function test_confirming_the_same_amount_with_a_new_file_writes_no_history(): void
    {
        $project = $this->project();
        $this->existingQuotation($project);

        $this->save($project, [
            'quotation' => '1000.00',
            'quotation_change' => 'both',
            'quotationDocument' => [$this->pdf()],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['revised-quotation.pdf'], $this->currentQuotationNames($project));
        $this->assertSame(0, QuotationHistory::query()->count());
    }

    public function test_cancelling_after_choosing_a_new_file_saves_nothing(): void
    {
        $project = $this->project();
        $original = $this->existingQuotation($project);

        $response = $this->save($project, [
            'quotation' => '1000',
            'quotation_change' => 'none',
            'quotationDocument' => [$this->pdf()],
        ]);

        $response->assertSessionHasErrors('quotation_change');

        $this->assertQuotationUntouched($project, $original);
    }

    /**
     * "Replace the file" was the answer, but the file never came - PHP drops
     * one over its own upload limit before Laravel sees it. Saving the new
     * amount alone would be exactly the half-done change the person refused.
     */
    public function test_a_confirmed_replacement_whose_file_never_arrived_saves_nothing(): void
    {
        $project = $this->project();
        $original = $this->existingQuotation($project);

        $response = $this->save($project, [
            'quotation' => '1500',
            'quotation_change' => 'both',
        ]);

        $response->assertSessionHasErrors('quotation_change');

        $this->assertQuotationUntouched($project, $original);
    }

    /**
     * An answer that contradicts what was sent is refused rather than
     * half-obeyed: "keep the file" with a file attached, "keep the amount"
     * with a different amount.
     */
    public function test_an_answer_that_contradicts_the_changes_saves_nothing(): void
    {
        $project = $this->project();
        $original = $this->existingQuotation($project);

        $this->save($project, [
            'quotation' => '1500',
            'quotation_change' => 'amount',
            'quotationDocument' => [$this->pdf()],
        ])->assertSessionHasErrors('quotation_change');

        $this->save($project, [
            'quotation' => '1500',
            'quotation_change' => 'file',
            'quotationDocument' => [$this->pdf()],
        ])->assertSessionHasErrors('quotation_change');

        $this->assertQuotationUntouched($project, $original);
    }

    // ------------------------------------------------------------------
    // History
    // ------------------------------------------------------------------

    public function test_changing_only_the_amount_creates_quotation_history(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-11 10:30:00'));

        $project = $this->project();
        $this->existingQuotation($project);

        $this->save($project, [
            'quotation' => '1500',
            'quotation_change' => 'amount',
        ])->assertSessionHasNoErrors();

        $history = QuotationHistory::query()->sole();

        $this->assertSame($project->project_id, (int) $history->project_id);
        $this->assertSame('1000.00', $history->previous_amount);
        $this->assertSame('1500.00', $history->new_amount);
        $this->assertFalse($history->file_replaced);
        $this->assertSame($this->admin->id, (int) $history->actor_id);
        $this->assertSame('Test Administrator', $history->actor_name);
        $this->assertSame(User::ROLE_SUPER_ADMIN, $history->actor_role);
        $this->assertSame('2026-09-11 10:30:00', $history->created_at->format('Y-m-d H:i:s'));

        // The audit trail says the same, in a sentence.
        $log = ActivityLog::query()->where('action', ActivityLog::PROJECT_UPDATED)->latest('activity_log_id')->firstOrFail();
        $this->assertStringContainsString('from ₱1,000.00 to ₱1,500.00', $log->description);
        $this->assertStringContainsString('kept the existing quotation file', $log->description);
    }

    public function test_changing_only_the_file_does_not_create_amount_history(): void
    {
        $project = $this->project();
        $this->existingQuotation($project);

        $this->save($project, [
            'quotation' => '1000',
            'quotation_change' => 'file',
            'quotationDocument' => [$this->pdf()],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, QuotationHistory::query()->count());

        // The replacement is still on the record - in the audit trail, and on
        // the superseded row - just not as an amount change.
        $log = ActivityLog::query()->where('action', ActivityLog::PROJECT_UPDATED)->latest('activity_log_id')->firstOrFail();
        $this->assertStringContainsString('previously original-quotation.pdf', $log->description);
        $this->assertStringContainsString('kept the quotation amount at ₱1,000.00', $log->description);
    }

    public function test_changing_both_creates_the_correct_history(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-11 14:05:00'));

        $project = $this->project();
        $original = $this->existingQuotation($project);

        $this->save($project, [
            'quotation' => '2750',
            'quotation_change' => 'both',
            'quotationDocument' => [$this->pdf()],
        ])->assertSessionHasNoErrors();

        // One row, for the one amount change - not one for the amount and
        // another for the file.
        $history = QuotationHistory::query()->sole();

        $this->assertSame('1000.00', $history->previous_amount);
        $this->assertSame('2750.00', $history->new_amount);
        $this->assertTrue($history->file_replaced);
        $this->assertSame($this->admin->id, (int) $history->actor_id);
        $this->assertSame('2026-09-11 14:05:00', $history->created_at->format('Y-m-d H:i:s'));

        // The file it replaced was replaced by the same person at the same
        // moment.
        $original->refresh();
        $this->assertSame($this->admin->id, (int) $original->superseded_by);
        $this->assertSame('2026-09-11 14:05:00', $original->superseded_at->format('Y-m-d H:i:s'));

        // Both show in the Quotation History dialog.
        $this->get(route('super-admin.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('Quotation History')
            ->assertSee('₱2,750.00')
            ->assertSee('Replaced in the same save')
            ->assertSee('original-quotation.pdf');
    }

    public function test_saving_the_same_amount_does_not_create_duplicate_history(): void
    {
        $project = $this->project();
        $this->existingQuotation($project);

        // The stored figure, written the ways the form might write it.
        foreach (['1000', '1000.00', '1000.0'] as $sameAmount) {
            $this->save($project, ['quotation' => $sameAmount])->assertSessionHasNoErrors();
        }

        $this->assertSame(0, QuotationHistory::query()->count());

        $this->save($project, [
            'quotation' => '1500',
            'quotation_change' => 'amount',
        ])->assertSessionHasNoErrors();

        // Saving the new figure again is not a second change.
        $this->save($project, ['quotation' => '1500.00'])->assertSessionHasNoErrors();
        $this->save($project, ['quotation' => '1500'])->assertSessionHasNoErrors();

        $this->assertSame(1, QuotationHistory::query()->count());
        $this->assertSame('1500.00', $project->refresh()->quotation);
    }

    // ------------------------------------------------------------------
    // Who may change it
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function unauthorizedRoles(): array
    {
        return [
            'technician' => [User::ROLE_TECHNICIAN],
            'lead technician' => [User::ROLE_LEAD_TECHNICIAN],
            'client' => [User::ROLE_CLIENT],
        ];
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_unauthorized_users_cannot_change_the_quotation_amount_or_file(string $role): void
    {
        $project = $this->project();
        $original = $this->existingQuotation($project);

        $this->actingAs($this->account($role));

        // Each half on its own, and both together.
        foreach ([
            ['quotation' => '1500', 'quotation_change' => 'amount'],
            ['quotation' => '1000', 'quotation_change' => 'file', 'quotationDocument' => [$this->pdf()]],
            ['quotation' => '1500', 'quotation_change' => 'both', 'quotationDocument' => [$this->pdf()]],
        ] as $attempt) {
            $this->save($project, $attempt)->assertRedirect();
        }

        $this->assertQuotationUntouched($project, $original);
    }

    public function test_a_guest_cannot_change_the_quotation(): void
    {
        $project = $this->project();
        $original = $this->existingQuotation($project);

        $this->app['auth']->forgetGuards();

        $this->save($project, [
            'quotation' => '1500',
            'quotation_change' => 'both',
            'quotationDocument' => [$this->pdf()],
        ])->assertRedirect(route('auth.login'));

        $this->assertQuotationUntouched($project, $original);
    }

    /**
     * The rules that were there before are the rules that are there now: an
     * Admin may change the quotation, and nobody may change it on a project
     * that has been closed.
     */
    public function test_the_existing_authorization_rules_are_unchanged(): void
    {
        $project = $this->project();
        $this->existingQuotation($project);

        $this->actingAs($this->account(User::ROLE_ADMIN));

        $this->save($project, [
            'quotation' => '1500',
            'quotation_change' => 'amount',
        ])->assertSessionHasNoErrors();

        $this->assertSame('1500.00', $project->refresh()->quotation);

        $closed = Project::create([
            'name' => 'Closed Project',
            'reference_no' => 'REF-CLOSED',
            'status' => 'completed',
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 1000,
        ]);

        $this->save($closed, [
            'quotation' => '1500',
            'quotation_change' => 'amount',
        ])->assertSessionHas('error');

        $this->assertSame('1000.00', $closed->refresh()->quotation);
        $this->assertSame(0, QuotationHistory::query()->where('project_id', $closed->project_id)->count());
    }

    // ------------------------------------------------------------------
    // Failure
    // ------------------------------------------------------------------

    /**
     * The replacement file cannot be recorded, after the amount and its
     * history row have already been written in the same transaction. All of
     * it goes back: the amount, the history row, the old file's supersession
     * - and the bytes of the new file, which no row names any more.
     */
    public function test_a_failed_update_does_not_create_quotation_history(): void
    {
        $project = $this->project();
        $original = $this->existingQuotation($project);

        $pathsBefore = UploadStore::disk()->allFiles(UploadStore::folder('documents'));

        Document::creating(function (): void {
            throw new RuntimeException('The quotation file could not be recorded.');
        });

        $response = $this->save($project, [
            'quotation' => '1500',
            'quotation_change' => 'both',
            'quotationDocument' => [$this->pdf()],
        ]);

        $response->assertSessionHas('error', 'The quotation file could not be recorded.');

        $this->assertQuotationUntouched($project, $original);
        $this->assertSame(
            0,
            ActivityLog::query()->where('action', ActivityLog::PROJECT_UPDATED)->count(),
            'A save that rolled back must not be in the audit trail either.'
        );
        $this->assertEqualsCanonicalizing(
            $pathsBefore,
            UploadStore::disk()->allFiles(UploadStore::folder('documents'))
        );
    }

    /**
     * The same guarantee when it is the amount alone: the client row has
     * gone from under the edit, so the save fails after the history row was
     * written - and takes it back out.
     */
    public function test_a_failed_amount_only_update_does_not_create_quotation_history(): void
    {
        $project = $this->project();
        $original = $this->existingQuotation($project);

        Client::query()->where('project_id', $project->project_id)->delete();

        $this->save($project, [
            'quotation' => '1500',
            'quotation_change' => 'amount',
        ])->assertSessionHas('error');

        $this->assertQuotationUntouched($project, $original);
    }

    // ------------------------------------------------------------------
    // Where a replaced file is read
    // ------------------------------------------------------------------

    /**
     * A replaced quotation is history. It drops out of everything that lists
     * a project's documents - the client's page and the crew's read the same
     * relation - and is still reachable from the Quotation History dialog.
     */
    public function test_a_replaced_quotation_leaves_the_current_documents(): void
    {
        $project = $this->project();
        $original = $this->existingQuotation($project);

        $this->save($project, [
            'quotation' => '1000',
            'quotation_change' => 'file',
            'quotationDocument' => [$this->pdf()],
        ])->assertSessionHasNoErrors();

        $project->refresh()->load('documents');

        $this->assertSame(['revised-quotation.pdf'], $project->documents->pluck('document_name')->all());
        $this->assertTrue($project->supersededQuotations->contains('document_id', $original->document_id));

        // Still opens for somebody who may read the project.
        $this->get($original->url())->assertOk();
    }
}
