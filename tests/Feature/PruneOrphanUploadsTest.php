<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Removing uploads that nothing points at.
 *
 * A file on the uploads disk is only reachable through the row that names it,
 * so one with no row cannot be opened by any route the application offers. It
 * is waste - left by a delete that removed the row and not the file, or by a
 * test run written against the real disk.
 *
 * The property that matters is the one a careless prune would break: a file a
 * row still names must survive. The command builds its referenced set from
 * every column that holds a path, and these tests are what would catch a kind
 * of upload being added later without being added there.
 */
class PruneOrphanUploadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
    }

    private function userWithPhoto(string $path): User
    {
        return User::create([
            'user_code' => 'EMP-8001',
            'name' => 'Someone',
            'first_name' => 'Some',
            'last_name' => 'One',
            'email' => 'someone@example.test',
            'role' => 'admin',
            'status' => User::STATUS_ACTIVE,
            'password' => 'a-password',
            'profile_photo_path' => $path,
        ]);
    }

    public function test_a_file_no_row_names_is_deleted(): void
    {
        Storage::disk('uploads')->put('profile-photos/orphan.jpg', 'nobody');

        $this->artisan('uploads:prune-orphans', ['--force' => true])->assertSuccessful();

        Storage::disk('uploads')->assertMissing('profile-photos/orphan.jpg');
    }

    public function test_a_file_a_row_still_names_survives(): void
    {
        Storage::disk('uploads')->put('profile-photos/kept.jpg', 'in use');
        Storage::disk('uploads')->put('profile-photos/orphan.jpg', 'nobody');

        $this->userWithPhoto('profile-photos/kept.jpg');

        $this->artisan('uploads:prune-orphans', ['--force' => true])->assertSuccessful();

        Storage::disk('uploads')->assertExists('profile-photos/kept.jpg');
        Storage::disk('uploads')->assertMissing('profile-photos/orphan.jpg');
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        Storage::disk('uploads')->put('profile-photos/orphan.jpg', 'nobody');

        $this->artisan('uploads:prune-orphans', ['--dry-run' => true])->assertSuccessful();

        Storage::disk('uploads')->assertExists('profile-photos/orphan.jpg');
    }

    /**
     * Without --force it asks, and answering no leaves everything where it is.
     */
    public function test_it_asks_before_deleting(): void
    {
        Storage::disk('uploads')->put('profile-photos/orphan.jpg', 'nobody');

        $this->artisan('uploads:prune-orphans')
            ->expectsConfirmation('Delete these permanently?', 'no')
            ->assertSuccessful();

        Storage::disk('uploads')->assertExists('profile-photos/orphan.jpg');
    }

    public function test_an_empty_disk_is_not_an_error(): void
    {
        $this->artisan('uploads:prune-orphans', ['--force' => true])
            ->expectsOutputToContain('Nothing to prune')
            ->assertSuccessful();
    }
}
