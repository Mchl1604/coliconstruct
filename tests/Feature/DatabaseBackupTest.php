<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\DatabaseBackup;
use App\Models\User;
use App\Services\DatabaseBackupService;
use App\Support\TarGzReader;
use App\Support\TarGzWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Configuration -> System Settings -> Backup & Restore.
 *
 * What matters most is what a restore refuses and what it leaves behind when
 * it fails: an archive that was edited, signed by another installation, made
 * under a newer schema, missing a Super Admin, or pointing a file outside the
 * upload folders must change nothing - and a restore that fails half way
 * through its inserts must leave every table exactly as it was.
 */
class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'test-password';

    /** @var list<string> */
    private array $scratch = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        Storage::fake('backups');
    }

    protected function tearDown(): void
    {
        foreach ($this->scratch as $path) {
            is_dir($path) ? File::deleteDirectory($path) : File::delete($path);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function service(): DatabaseBackupService
    {
        return app(DatabaseBackupService::class);
    }

    private function account(string $name, string $role = User::ROLE_ADMIN): User
    {
        $sequence = User::count() + 1;
        [$first, $last] = explode(' ', $name, 2);

        return User::create([
            'user_code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => $name,
            'first_name' => $first,
            'last_name' => $last,
            'email' => Str::slug($name, '.').'@example.test',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'password' => 'another-password',
        ]);
    }

    private function archivePath(DatabaseBackup $backup): string
    {
        return Storage::disk('backups')->path($backup->path);
    }

    /**
     * Every entry in an archive, by name, in archive order.
     *
     * @return array<string, string>
     */
    private function entries(string $archive): array
    {
        $directory = $this->scratchPath();
        File::ensureDirectoryExists($directory);

        $index = 0;
        $entries = TarGzReader::extract($archive, function () use ($directory, &$index): string {
            return $directory.'/'.($index++);
        });

        return array_map(fn (array $entry): string => (string) file_get_contents($entry['path']), $entries);
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function writeArchive(array $entries): string
    {
        $path = $this->scratchPath().'.tar.gz';
        $writer = new TarGzWriter($path);

        foreach ($entries as $name => $contents) {
            $writer->addString($name, $contents);
        }

        $writer->close();

        return $path;
    }

    private function scratchPath(): string
    {
        $path = storage_path('framework/testing/backup-test-'.Str::random(10));
        $this->scratch[] = $path;
        $this->scratch[] = $path.'.tar.gz';

        return $path;
    }

    private function restore(DatabaseBackup $backup, array $overrides = []): TestResponse
    {
        return $this->postJson(route('super-admin.configuration.backups.restore', $backup), array_merge([
            'password' => self::PASSWORD,
            'confirmation' => 'RESTORE',
        ], $overrides));
    }

    private function upload(string $archive, string $name = 'site-backup.tar.gz'): TestResponse
    {
        return $this->post(route('super-admin.configuration.backups.upload'), [
            'backup_file' => new UploadedFile($archive, $name, 'application/gzip', null, true),
            'password' => self::PASSWORD,
            'confirmation' => 'RESTORE',
        ], ['Accept' => 'application/json']);
    }

    // ------------------------------------------------------------------
    // Who
    // ------------------------------------------------------------------

    public function test_only_a_super_admin_can_reach_backups(): void
    {
        $backup = DatabaseBackup::factory()->create();

        $this->actingAs($this->account('Ana Admin'));

        $this->getJson(route('super-admin.configuration.backups.index'))->assertForbidden();
        $this->postJson(route('super-admin.configuration.backups.store'))->assertForbidden();
        $this->postJson(route('super-admin.configuration.backups.upload'))->assertForbidden();
        $this->postJson(route('super-admin.configuration.backups.restore', $backup))->assertForbidden();
        $this->deleteJson(route('super-admin.configuration.backups.destroy', $backup))->assertForbidden();

        $this->assertModelExists($backup);
    }

    public function test_the_settings_tab_offers_backup_and_restore_to_a_super_admin_only(): void
    {
        $this->actingAsSuperAdmin();

        $this->get(route('super-admin.configuration.index'))
            ->assertOk()
            ->assertSee('Backup & Restore')
            ->assertSee('databaseBackups.js', false);

        $this->actingAs($this->account('Ana Admin'));

        $this->get(route('super-admin.configuration.index'))
            ->assertOk()
            ->assertDontSee('databaseBackups.js', false)
            ->assertDontSee('restoreBackupModal', false);
    }

    // ------------------------------------------------------------------
    // Creating
    // ------------------------------------------------------------------

    public function test_a_backup_holds_every_table_and_every_uploaded_file(): void
    {
        $this->actingAsSuperAdmin();

        Storage::disk('uploads')->put('profile-photos/face.jpg', 'photo-bytes');
        Storage::disk('uploads')->put('documents/contract.pdf', 'contract-bytes');

        $this->postJson(route('super-admin.configuration.backups.store'), ['include_files' => true])
            ->assertOk()
            ->assertJsonPath('backups.0.kind', DatabaseBackup::KIND_MANUAL)
            ->assertJsonPath('backups.0.file_count', 2);

        $backup = DatabaseBackup::sole();
        Storage::disk('backups')->assertExists($backup->path);

        $entries = $this->entries($this->archivePath($backup));

        $this->assertArrayHasKey('database/users.ndjson', $entries);
        $this->assertArrayHasKey('manifest.json', $entries);
        $this->assertArrayHasKey('manifest.sig', $entries);
        $this->assertSame('photo-bytes', $entries['files/profile-photos/face.jpg']);
        $this->assertSame('contract-bytes', $entries['files/documents/contract.pdf']);

        foreach (DatabaseBackupService::EXCLUDED_TABLES as $table) {
            $this->assertArrayNotHasKey('database/'.$table.'.ndjson', $entries);
        }

        $this->assertStringContainsString('super.admin@coliconstruct.test', $entries['database/users.ndjson']);
        $this->assertTrue($backup->includes_files);
        $this->assertDatabaseHas('tbl_activity_logs', ['action' => ActivityLog::BACKUP_CREATED]);
    }

    public function test_a_backup_can_leave_the_files_out(): void
    {
        $this->actingAsSuperAdmin();

        Storage::disk('uploads')->put('profile-photos/face.jpg', 'photo-bytes');

        $this->postJson(route('super-admin.configuration.backups.store'), ['include_files' => false])->assertOk();

        $backup = DatabaseBackup::sole();
        $names = array_keys($this->entries($this->archivePath($backup)));

        $this->assertFalse($backup->includes_files);
        $this->assertSame([], array_values(array_filter($names, fn (string $name): bool => str_starts_with($name, 'files/'))));
    }

    public function test_only_one_backup_runs_at_a_time(): void
    {
        $this->actingAsSuperAdmin();

        $lock = Cache::lock('database-backup', 60);
        $lock->get();

        $this->postJson(route('super-admin.configuration.backups.store'))
            ->assertStatus(422)
            ->assertJsonPath('error', 'Another backup is running.');

        $lock->release();

        $this->assertSame(0, DatabaseBackup::count());
    }

    // ------------------------------------------------------------------
    // Restoring
    // ------------------------------------------------------------------

    public function test_restoring_puts_back_rows_and_files_and_signs_everybody_out(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $kept = $this->account('Original Name');

        Storage::disk('uploads')->put('profile-photos/face.jpg', 'version-one');
        Storage::disk('uploads')->put('documents/contract.pdf', 'contract-bytes');

        $backup = $this->service()->create($admin, true);

        $kept->forceFill(['name' => 'Changed Name'])->save();
        $this->account('Added Later');
        Storage::disk('uploads')->put('profile-photos/face.jpg', 'version-two');
        Storage::disk('uploads')->delete('documents/contract.pdf');

        $this->restore($backup)
            ->assertOk()
            ->assertJsonPath('redirect', route('auth.login'));

        $this->assertSame('Original Name', $kept->fresh()->name);
        $this->assertDatabaseMissing('users', ['name' => 'Added Later']);
        $this->assertSame('version-one', Storage::disk('uploads')->get('profile-photos/face.jpg'));
        Storage::disk('uploads')->assertExists('documents/contract.pdf');

        $this->assertGuest();
        $this->assertSame(1, DatabaseBackup::query()->where('kind', DatabaseBackup::KIND_PRE_RESTORE)->count());
        $this->assertDatabaseHas('tbl_activity_logs', ['action' => ActivityLog::RESTORE_PERFORMED]);
    }

    public function test_the_backup_taken_before_a_restore_undoes_it(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $backup = $this->service()->create($admin, false);
        $this->account('Added Later');

        $this->restore($backup)->assertOk();
        $this->assertDatabaseMissing('users', ['name' => 'Added Later']);

        $safety = DatabaseBackup::query()->where('kind', DatabaseBackup::KIND_PRE_RESTORE)->sole();

        $this->actingAs(User::query()->where('email', 'super.admin@coliconstruct.test')->sole());
        $this->restore($safety)->assertOk();

        $this->assertDatabaseHas('users', ['name' => 'Added Later']);
    }

    public function test_a_restore_needs_the_password_and_the_typed_word(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $backup = $this->service()->create($admin, false);
        $this->account('Added Later');

        $this->restore($backup, ['password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Incorrect password.');

        $this->restore($backup, ['confirmation' => 'restor'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Type RESTORE to confirm.');

        $this->assertDatabaseHas('users', ['name' => 'Added Later']);
        $this->assertSame(0, DatabaseBackup::query()->where('kind', DatabaseBackup::KIND_PRE_RESTORE)->count());
        $this->assertAuthenticatedAs($admin);
    }

    public function test_an_edited_backup_is_refused(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $entries = $this->entries($this->archivePath($this->service()->create($admin, false)));
        $this->account('Added Later');

        $editedRows = $entries;
        $editedRows['database/users.ndjson'] = str_replace('Test Super Admin', 'Someone Else', $editedRows['database/users.ndjson']);

        $editedManifest = $entries;
        $editedManifest['manifest.json'] = str_replace('"includes_files": false', '"includes_files": true', $editedManifest['manifest.json']);

        foreach ([$editedRows, $editedManifest] as $edited) {
            $this->upload($this->writeArchive($edited))
                ->assertStatus(422)
                ->assertJsonPath('error', 'Backup failed verification.');
        }

        $this->assertDatabaseHas('users', ['name' => 'Added Later']);
        $this->assertSame(1, DatabaseBackup::count());
    }

    public function test_a_backup_made_under_another_app_key_is_refused(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $backup = $this->service()->create($admin, false);

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->restore($backup)
            ->assertStatus(422)
            ->assertJsonPath('error', 'Backup failed verification.');
    }

    public function test_a_backup_from_a_newer_version_is_refused(): void
    {
        $admin = $this->actingAsSuperAdmin();

        DB::table('migrations')->insert(['migration' => '2099_01_01_000000_from_the_future', 'batch' => 99]);
        $backup = $this->service()->create($admin, false);
        DB::table('migrations')->where('migration', '2099_01_01_000000_from_the_future')->delete();

        $this->restore($backup)
            ->assertStatus(422)
            ->assertJsonPath('error', 'Backup is from a newer version.');
    }

    public function test_a_backup_without_an_active_super_admin_is_refused(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $admin->forceFill(['status' => User::STATUS_DEACTIVATED])->save();
        $backup = $this->service()->create($admin, false);
        $admin->forceFill(['status' => User::STATUS_ACTIVE])->save();

        $this->restore($backup)
            ->assertStatus(422)
            ->assertJsonPath('error', 'Backup has no active super admin.');
    }

    public function test_a_file_outside_the_upload_folders_is_refused(): void
    {
        $this->actingAsSuperAdmin();

        foreach (['files/../../public/evil.php', 'files/not-an-upload-folder/evil.php', 'elsewhere.txt'] as $name) {
            $this->upload($this->writeArchive([$name => '<?php echo 1;']))
                ->assertStatus(422)
                ->assertJsonPath('error', 'Not a valid backup file.');
        }

        Storage::disk('uploads')->assertMissing('not-an-upload-folder/evil.php');
        $this->assertFileDoesNotExist(public_path('evil.php'));
    }

    public function test_a_damaged_or_foreign_file_is_refused(): void
    {
        $this->actingAsSuperAdmin();

        $notAnArchive = $this->scratchPath().'.tar.gz';
        file_put_contents($notAnArchive, 'just some text');

        $this->upload($notAnArchive)->assertStatus(422)->assertJsonPath('error', 'Backup file is damaged.');
        $this->upload($notAnArchive, 'backup.zip')->assertStatus(422)->assertJsonPath('error', 'Choose a .tar.gz backup.');
    }

    public function test_upload_and_restore_restores_and_keeps_the_archive(): void
    {
        $admin = $this->actingAsSuperAdmin();

        Storage::disk('uploads')->put('task-images/before.png', 'image-bytes');

        $backup = $this->service()->create($admin, true);
        $copy = $this->scratchPath().'.tar.gz';
        File::copy($this->archivePath($backup), $copy);

        // As if on another server: no row, no stored archive.
        $this->service()->delete($backup);
        Storage::disk('uploads')->delete('task-images/before.png');
        $this->account('Added Later');

        $this->upload($copy)
            ->assertOk()
            ->assertJsonPath('redirect', route('auth.login'));

        $this->assertDatabaseMissing('users', ['name' => 'Added Later']);
        $this->assertSame('image-bytes', Storage::disk('uploads')->get('task-images/before.png'));

        $uploaded = DatabaseBackup::query()->where('kind', DatabaseBackup::KIND_UPLOADED)->sole();
        Storage::disk('backups')->assertExists($uploaded->path);
        $this->assertSame(1, $uploaded->file_count);
    }

    public function test_a_restore_that_fails_part_way_changes_no_rows(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $entries = $this->entries($this->archivePath($this->service()->create($admin, false)));
        $this->account('Added Later');

        // A correctly signed archive whose users table repeats a primary key:
        // every check passes, and the INSERT is what fails.
        $lines = explode("\n", rtrim($entries['database/users.ndjson'], "\n"));
        $lines[] = $lines[1];
        $entries['database/users.ndjson'] = implode("\n", $lines)."\n";

        $manifest = json_decode($entries['manifest.json'], true);
        $manifest['tables']['users']['sha256'] = hash('sha256', $entries['database/users.ndjson']);
        $entries['manifest.json'] = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $entries['manifest.sig'] = $this->service()->sign($entries['manifest.json']);

        $this->upload($this->writeArchive($entries))
            ->assertStatus(500)
            ->assertJsonPath('error', 'Restore failed. Nothing changed.');

        $this->assertDatabaseHas('users', ['name' => 'Added Later']);
        $this->assertDatabaseHas('users', ['email' => 'super.admin@coliconstruct.test']);
        $this->assertSame(0, DatabaseBackup::query()->where('kind', DatabaseBackup::KIND_UPLOADED)->count());
        $this->assertAuthenticatedAs($admin);
    }

    // ------------------------------------------------------------------
    // Downloading and deleting
    // ------------------------------------------------------------------

    public function test_downloading_a_backup_returns_the_archive_and_is_logged(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $backup = $this->service()->create($admin, false);

        $this->get(route('super-admin.configuration.backups.download', $backup))
            ->assertOk()
            ->assertDownload($backup->file_name);

        $this->assertDatabaseHas('tbl_activity_logs', ['action' => ActivityLog::BACKUP_DOWNLOADED]);
    }

    public function test_deleting_a_backup_removes_its_archive(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $backup = $this->service()->create($admin, false);

        $this->deleteJson(route('super-admin.configuration.backups.destroy', $backup))
            ->assertOk()
            ->assertJsonPath('backups', []);

        Storage::disk('backups')->assertMissing($backup->path);
        $this->assertModelMissing($backup);
        $this->assertDatabaseHas('tbl_activity_logs', ['action' => ActivityLog::BACKUP_DELETED]);
    }

    // ------------------------------------------------------------------
    // The archive format
    // ------------------------------------------------------------------

    public function test_long_entry_names_survive_the_archive(): void
    {
        $name = 'files/documents/'.str_repeat('nested-folder/', 8).str_repeat('a', 60).'.pdf';

        $entries = $this->entries($this->writeArchive([$name => 'deep', 'short.txt' => '']));

        $this->assertSame(['deep', ''], [$entries[$name], $entries['short.txt']]);
    }
}
