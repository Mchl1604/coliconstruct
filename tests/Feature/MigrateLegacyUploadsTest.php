<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use App\Support\UploadStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Carrying forward the uploads that predate the uploads disk.
 *
 * Every installation that ran this application before uploads moved onto one
 * private disk holds rows naming the two old places - the public disk, and
 * public/uploads. Those rows are not wrong so much as stale, and every page
 * showing one of the files shows a broken image until they are brought over.
 *
 * The properties worth holding: the file arrives, the row follows it, the
 * original is not left behind, and running the command twice is not a way to
 * lose anything.
 */
class MigrateLegacyUploadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('uploads');
    }

    private function project(): Project
    {
        return Project::create([
            'name' => 'Legacy Project',
            'reference_no' => 'REF-LEGACY',
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 1000,
        ]);
    }

    public function test_a_picture_on_the_public_disk_is_carried_over(): void
    {
        Storage::disk('public')->put('profile-photos/face.jpg', 'the picture');

        $user = User::create([
            'user_code' => 'EMP-9001',
            'name' => 'Legacy Person',
            'first_name' => 'Legacy',
            'last_name' => 'Person',
            'email' => 'legacy.person@example.test',
            'role' => 'admin',
            'status' => User::STATUS_ACTIVE,
            'password' => 'a-password',
            'profile_photo_path' => 'profile-photos/face.jpg',
        ]);

        $this->artisan('uploads:migrate-legacy')->assertSuccessful();

        $this->assertSame('profile-photos/face.jpg', $user->fresh()->profile_photo_path);
        Storage::disk('uploads')->assertExists('profile-photos/face.jpg');
        // Not left on the old disk, where nothing reads it any more.
        Storage::disk('public')->assertMissing('profile-photos/face.jpg');
    }

    /**
     * Task images changed folder as well as disk, so the row has to move with
     * the file rather than merely being pointed at a new root.
     */
    public function test_a_renamed_folder_is_reflected_in_the_row(): void
    {
        Storage::disk('public')->put('task-completions/proof.jpg', 'the proof');

        $project = $this->project();
        $task = $project->tasks()->create([
            'task_title' => 'A task',
            'task_description' => 'Work to do',
            'status' => 'completed',
        ]);
        $image = $task->images()->create(['image_path' => 'task-completions/proof.jpg']);

        $this->artisan('uploads:migrate-legacy')->assertSuccessful();

        $this->assertSame('task-images/proof.jpg', $image->fresh()->image_path);
        Storage::disk('uploads')->assertExists('task-images/proof.jpg');
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        Storage::disk('public')->put('profile-photos/face.jpg', 'the picture');

        $user = User::create([
            'user_code' => 'EMP-9002',
            'name' => 'Legacy Person',
            'first_name' => 'Legacy',
            'last_name' => 'Person',
            'email' => 'dry.run@example.test',
            'role' => 'admin',
            'status' => User::STATUS_ACTIVE,
            'password' => 'a-password',
            'profile_photo_path' => 'profile-photos/face.jpg',
        ]);

        $this->artisan('uploads:migrate-legacy', ['--dry-run' => true])->assertSuccessful();

        Storage::disk('public')->assertExists('profile-photos/face.jpg');
        Storage::disk('uploads')->assertMissing('profile-photos/face.jpg');
        $this->assertSame('profile-photos/face.jpg', $user->fresh()->profile_photo_path);
    }

    /**
     * Run twice by accident, or run again after a partial one, and nothing is
     * lost - a row already naming its new home is left where it is.
     */
    public function test_running_it_again_is_harmless(): void
    {
        // A completion photograph: written into public/ under the old
        // arrangement, so it exercises the other of the two source shapes.
        $project = $this->project();
        file_put_contents($this->legacyPublicFile('completion/site.jpg'), 'the site');

        $photo = \App\Models\ProjectCompletionPhoto::create([
            'project_id' => $project->project_id,
            'photo_path' => 'uploads/completion/site.jpg',
            'uploaded_at' => now(),
        ]);

        $this->artisan('uploads:migrate-legacy')->assertSuccessful();
        $this->artisan('uploads:migrate-legacy')->assertSuccessful();

        $this->assertSame('completion-photos/site.jpg', $photo->fresh()->photo_path);
        Storage::disk('uploads')->assertExists('completion-photos/site.jpg');
        $this->assertFalse(file_exists(public_path('uploads/completion/site.jpg')));
    }

    /**
     * A path under public/, created for the test and removed after it.
     */
    private function legacyPublicFile(string $relative): string
    {
        $absolute = public_path('uploads/'.$relative);

        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0775, true);
        }

        $this->beforeApplicationDestroyed(function () use ($absolute): void {
            if (file_exists($absolute)) {
                unlink($absolute);
            }
        });

        return $absolute;
    }

    /**
     * A row whose file was already gone - wiped by a deploy before uploads
     * became durable - is reported rather than silently repointed at nothing.
     */
    public function test_a_row_with_no_file_is_reported_and_left_alone(): void
    {
        $project = $this->project();

        $document = Document::create([
            'project_id' => $project->project_id,
            'document_type' => 'contract',
            'document_name' => 'gone.pdf',
            'document_path' => 'uploads/contract/gone.pdf',
            'uploaded_at' => now(),
        ]);

        $this->artisan('uploads:migrate-legacy')
            ->expectsOutputToContain('gone')
            ->assertSuccessful();

        $this->assertSame('uploads/contract/gone.pdf', $document->fresh()->document_path);
    }
}
