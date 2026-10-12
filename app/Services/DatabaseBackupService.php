<?php

namespace App\Services;

use App\Exceptions\BackupRefused;
use App\Models\DatabaseBackup;
use App\Models\User;
use App\Support\TarGzReader;
use App\Support\TarGzWriter;
use App\Support\UploadStore;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use UnexpectedValueException;

/**
 * Configuration -> System Settings -> Backup & Restore.
 *
 * A backup is one .tar.gz on the backups disk:
 *
 *   database/<table>.ndjson   every row of every table, one JSON array a line,
 *                             under a first line naming the columns
 *   files/<path>              every file on the uploads disk, unless left out
 *   manifest.json             what is in the archive: the migrations it was
 *                             taken under, each entry's SHA-256, who made it
 *   manifest.sig              an HMAC of manifest.json under APP_KEY
 *
 * Data only - the tables themselves always come from the migrations. That is
 * what lets the same code back up MySQL in production and SQLite in the test
 * suite, and what keeps the deployment free of a mysqldump binary it does not
 * have.
 *
 * A restore checks everything before it changes anything: the signature (so
 * only an archive this installation made is accepted), every entry against
 * the manifest, every file path against the upload folders, the migrations,
 * the columns, and that at least one active Super Admin comes back. Then it
 * takes a full backup of what is about to be replaced, writes the files, and
 * swaps every table's rows inside ONE transaction - so a restore that fails
 * part way leaves the database exactly as it was.
 */
class DatabaseBackupService
{
    public const DISK = 'backups';

    public const FORMAT = 'coliconstruct-backup';

    public const FORMAT_VERSION = 1;

    /**
     * Never written into a backup, never touched by a restore.
     *
     * migrations is the schema's own record, and the schema is not what a
     * restore replaces. The rest are the framework's working state - sessions,
     * cache, the queue - which mean nothing at another moment in time. And
     * tbl_database_backups is the list of backups itself: restoring last
     * week's data must not also forget the backups taken since.
     *
     * @var list<string>
     */
    public const EXCLUDED_TABLES = [
        'migrations',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
        'tbl_database_backups',
    ];

    private const LOCK = 'database-backup';

    private const LOCK_SECONDS = 3600;

    /**
     * Bound values per INSERT while restoring. Well under both MySQL's 65,535
     * placeholders and SQLite's 32,766.
     */
    private const VALUES_PER_INSERT = 2000;

    /**
     * Every backup, newest first.
     *
     * @return Collection<int, DatabaseBackup>
     */
    public function all(): Collection
    {
        return DatabaseBackup::query()
            ->orderByDesc('created_at')
            ->orderByDesc('backup_id')
            ->get();
    }

    public function create(User $actor, bool $includeFiles): DatabaseBackup
    {
        return $this->exclusively(
            fn (): DatabaseBackup => $this->write($actor, $includeFiles, DatabaseBackup::KIND_MANUAL)
        );
    }

    /**
     * Put a stored backup back. Returns the backup taken just before.
     */
    public function restore(DatabaseBackup $backup, User $actor): DatabaseBackup
    {
        return $this->exclusively(function () use ($backup, $actor): DatabaseBackup {
            return $this->inWorkDirectory(function (string $work) use ($backup, $actor): DatabaseBackup {
                $archive = $work.'/restore.tar.gz';
                $this->download($backup->path, $archive);

                $contents = $this->open($archive, $work);
                $safety = $this->write($actor, true, DatabaseBackup::KIND_PRE_RESTORE);

                $this->apply($contents);

                return $safety;
            });
        });
    }

    /**
     * Restore an archive somebody uploaded, and keep it in the list.
     *
     * @return array{uploaded: DatabaseBackup, safety: DatabaseBackup}
     */
    public function restoreUpload(string $archivePath, User $actor): array
    {
        return $this->exclusively(function () use ($archivePath, $actor): array {
            return $this->inWorkDirectory(function (string $work) use ($archivePath, $actor): array {
                $contents = $this->open($archivePath, $work);
                $safety = $this->write($actor, true, DatabaseBackup::KIND_PRE_RESTORE);

                $this->apply($contents);

                // Kept only once it has restored: an archive that fails to is
                // not one anybody should be offered again.
                $uploaded = $this->keep(
                    $archivePath,
                    DatabaseBackup::KIND_UPLOADED,
                    $contents['manifest'],
                    $actor
                );

                return ['uploaded' => $uploaded, 'safety' => $safety];
            });
        });
    }

    public function delete(DatabaseBackup $backup): void
    {
        $this->exclusively(function () use ($backup): void {
            $this->disk()->delete($backup->path);
            $backup->delete();
        });
    }

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /**
     * The tables a backup holds and a restore replaces.
     *
     * Only this connection's own schema: on a MySQL server that hosts other
     * databases, an unqualified listing would include theirs too.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $tables = Schema::getTableListing(Schema::getCurrentSchemaListing(), false);
        $tables = array_values(array_diff($tables, self::EXCLUDED_TABLES));

        sort($tables);

        return $tables;
    }

    /**
     * Whether a path may be written to the uploads disk by a restore: inside
     * one of the configured upload folders, and nowhere else.
     */
    public function isUploadPath(string $path): bool
    {
        $segments = explode('/', $path);

        if (count($segments) < 2 || ! in_array($segments[0], $this->uploadFolders(), true)) {
            return false;
        }

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || preg_match('/[\x00-\x1F\x7F\\\\]/', $segment) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * The signature a manifest must carry. Keyed on APP_KEY, so an archive
     * made by another installation - or edited after it was made - fails.
     */
    public function sign(string $manifest): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new BackupRefused('Set APP_KEY first.');
        }

        return hash_hmac('sha256', $manifest, hash('sha256', 'database-backup:'.$key, true));
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    private function write(User $actor, bool $includeFiles, string $kind): DatabaseBackup
    {
        return $this->inWorkDirectory(function (string $work) use ($actor, $includeFiles, $kind): DatabaseBackup {
            $archive = $work.'/backup.tar.gz';
            $writer = new TarGzWriter($archive);

            try {
                $tables = $this->writeTables($writer, $work);
                $files = $includeFiles ? $this->writeFiles($writer, $work) : [];

                $manifest = json_encode([
                    'format' => self::FORMAT,
                    'version' => self::FORMAT_VERSION,
                    'app' => config('app.name'),
                    'created_at' => now()->toIso8601String(),
                    'created_by' => $actor->fullName(),
                    'driver' => DB::getDriverName(),
                    'includes_files' => $includeFiles,
                    'migrations' => DB::table('migrations')->orderBy('id')->pluck('migration')->all(),
                    'tables' => (object) $tables,
                    'files' => (object) $files,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

                $writer->addString('manifest.json', $manifest);
                $writer->addString('manifest.sig', $this->sign($manifest));
            } finally {
                $writer->close();
            }

            return $this->keep($archive, $kind, json_decode($manifest, true), $actor);
        });
    }

    /**
     * Every table's rows, read inside one transaction so that on MySQL they
     * all come from the same moment rather than drifting while the dump runs.
     *
     * @return array<string, array{columns: list<string>, rows: int, sha256: string}>
     */
    private function writeTables(TarGzWriter $writer, string $work): array
    {
        return DB::transaction(function () use ($writer, $work): array {
            $tables = [];

            foreach ($this->tables() as $table) {
                $local = $work.'/table.ndjson';
                $columns = Schema::getColumnListing($table);
                $rows = $this->dumpTable($table, $columns, $local);

                $tables[$table] = [
                    'columns' => $columns,
                    'rows' => $rows,
                    'sha256' => hash_file('sha256', $local),
                ];

                $writer->addFile('database/'.$table.'.ndjson', $local);
            }

            return $tables;
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function dumpTable(string $table, array $columns, string $local): int
    {
        $out = fopen($local, 'wb');
        $rows = 0;

        try {
            fwrite($out, json_encode($columns, JSON_THROW_ON_ERROR)."\n");

            foreach (DB::table($table)->cursor() as $row) {
                $row = (array) $row;
                $values = [];

                foreach ($columns as $column) {
                    $values[] = $this->encodeValue($row[$column] ?? null);
                }

                fwrite($out, json_encode(
                    $values,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
                )."\n");

                $rows++;
            }
        } finally {
            fclose($out);
        }

        return $rows;
    }

    /**
     * @return array<string, array{size: int, sha256: string}>
     */
    private function writeFiles(TarGzWriter $writer, string $work): array
    {
        $disk = UploadStore::disk();
        $files = [];

        foreach ($this->uploadFolders() as $folder) {
            foreach ($disk->allFiles($folder) as $path) {
                if (! $this->isUploadPath($path)) {
                    continue;
                }

                $source = $disk->readStream($path);

                // Deleted between the listing and now: there is nothing left
                // to keep, and no row will name it once its delete commits.
                if (! is_resource($source)) {
                    continue;
                }

                $local = $work.'/file.bin';
                $this->copyStream($source, $local);
                clearstatcache(true, $local);

                $files[$path] = [
                    'size' => (int) filesize($local),
                    'sha256' => hash_file('sha256', $local),
                ];

                $writer->addFile('files/'.$path, $local);
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * Store an archive on the backups disk and list it.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function keep(string $archive, string $kind, array $manifest, User $actor): DatabaseBackup
    {
        $prefix = match ($kind) {
            DatabaseBackup::KIND_PRE_RESTORE => 'before-restore',
            DatabaseBackup::KIND_UPLOADED => 'uploaded',
            default => 'backup',
        };

        $fileName = sprintf(
            '%s-%s-%s.tar.gz',
            $prefix,
            now()->format('Y-m-d-His'),
            Str::lower(Str::random(4))
        );

        $stream = fopen($archive, 'rb');

        try {
            $this->disk()->writeStream($fileName, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $tables = (array) ($manifest['tables'] ?? []);
        clearstatcache(true, $archive);

        return DatabaseBackup::create([
            'file_name' => $fileName,
            'path' => $fileName,
            'kind' => $kind,
            'includes_files' => (bool) ($manifest['includes_files'] ?? false),
            'size_bytes' => (int) filesize($archive),
            'table_count' => count($tables),
            'row_count' => array_sum(array_map(fn (array $table): int => (int) $table['rows'], $tables)),
            'file_count' => count((array) ($manifest['files'] ?? [])),
            'created_by' => $actor->id,
            'created_by_name' => $actor->fullName(),
        ]);
    }

    // ------------------------------------------------------------------
    // Reading and checking
    // ------------------------------------------------------------------

    /**
     * Extract an archive and check every part of it. Nothing outside the
     * work directory is touched here.
     *
     * @return array{manifest: array<string, mixed>, tables: array<string, string>, files: array<string, string>}
     */
    private function open(string $archive, string $work): array
    {
        $extracted = $work.'/extracted';
        File::ensureDirectoryExists($extracted);

        $index = 0;

        try {
            $entries = TarGzReader::extract($archive, function (string $name) use ($extracted, &$index): string {
                if (! $this->isArchiveEntry($name)) {
                    throw new BackupRefused('Not a valid backup file.');
                }

                return $extracted.'/'.($index++).'.part';
            });
        } catch (UnexpectedValueException) {
            throw new BackupRefused('Backup file is damaged.');
        }

        $manifestRaw = isset($entries['manifest.json']) ? (string) file_get_contents($entries['manifest.json']['path']) : null;
        $signature = isset($entries['manifest.sig']) ? trim((string) file_get_contents($entries['manifest.sig']['path'])) : null;

        if ($manifestRaw === null || $signature === null) {
            throw new BackupRefused('Not a valid backup file.');
        }

        if (! hash_equals($this->sign($manifestRaw), $signature)) {
            throw new BackupRefused('Backup failed verification.');
        }

        try {
            $manifest = json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BackupRefused('Not a valid backup file.');
        }

        if (($manifest['format'] ?? null) !== self::FORMAT) {
            throw new BackupRefused('Not a valid backup file.');
        }

        if ((int) ($manifest['version'] ?? 0) > self::FORMAT_VERSION) {
            throw new BackupRefused('Backup is from a newer version.');
        }

        $tables = $this->matchEntries($entries, (array) ($manifest['tables'] ?? []), 'database/', '.ndjson');
        $files = $this->matchEntries($entries, (array) ($manifest['files'] ?? []), 'files/', '');

        // Every entry has to be one the manifest vouches for.
        if (count($entries) !== count($tables) + count($files) + 2) {
            throw new BackupRefused('Backup failed verification.');
        }

        foreach (array_keys($files) as $path) {
            if (! $this->isUploadPath($path)) {
                throw new BackupRefused('Not a valid backup file.');
            }
        }

        $this->assertFitsSchema($manifest);
        $this->assertKeepsASuperAdmin($tables, $manifest);

        return ['manifest' => $manifest, 'tables' => $tables, 'files' => $files];
    }

    private function isArchiveEntry(string $name): bool
    {
        if ($name === 'manifest.json' || $name === 'manifest.sig') {
            return true;
        }

        if (preg_match('/^database\/[A-Za-z0-9_]+\.ndjson$/', $name) === 1) {
            return true;
        }

        return str_starts_with($name, 'files/') && $this->isUploadPath(substr($name, 6));
    }

    /**
     * The extracted path of every item the manifest lists, each checked
     * against its recorded SHA-256.
     *
     * @param  array<string, array{path: string, size: int, sha256: string}>  $entries
     * @param  array<string, mixed>  $listed
     * @return array<string, string>
     */
    private function matchEntries(array $entries, array $listed, string $prefix, string $suffix): array
    {
        $matched = [];

        foreach ($listed as $name => $details) {
            $entry = $entries[$prefix.$name.$suffix] ?? null;

            if ($entry === null || ! is_array($details)
                || ! hash_equals((string) ($details['sha256'] ?? ''), $entry['sha256'])) {
                throw new BackupRefused('Backup failed verification.');
            }

            $matched[(string) $name] = $entry['path'];
        }

        return $matched;
    }

    /**
     * Data only goes back into the schema it came from, or a later one.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function assertFitsSchema(array $manifest): void
    {
        $applied = DB::table('migrations')->pluck('migration')->all();

        if (array_diff((array) ($manifest['migrations'] ?? []), $applied) !== []) {
            throw new BackupRefused('Backup is from a newer version.');
        }

        $current = $this->tables();

        foreach ((array) $manifest['tables'] as $table => $details) {
            if (! in_array($table, $current, true)
                || array_diff((array) ($details['columns'] ?? []), Schema::getColumnListing($table)) !== []) {
                throw new BackupRefused('Backup does not match this system.');
            }
        }
    }

    /**
     * Restoring must leave somebody able to sign in and administer the
     * system, or the restore could never be undone from the interface.
     *
     * @param  array<string, string>  $tables
     * @param  array<string, mixed>  $manifest
     */
    private function assertKeepsASuperAdmin(array $tables, array $manifest): void
    {
        if (! isset($tables['users'])) {
            throw new BackupRefused('Backup has no active super admin.');
        }

        $found = false;

        $this->eachRow($tables['users'], (array) $manifest['tables']['users']['columns'], function (array $user) use (&$found): bool {
            $found = ($user['role'] ?? null) === User::ROLE_SUPER_ADMIN
                && ($user['status'] ?? null) === User::STATUS_ACTIVE
                && ! (bool) ($user['is_archived'] ?? false);

            return ! $found;
        });

        if (! $found) {
            throw new BackupRefused('Backup has no active super admin.');
        }
    }

    // ------------------------------------------------------------------
    // Applying
    // ------------------------------------------------------------------

    /**
     * @param  array{manifest: array<string, mixed>, tables: array<string, string>, files: array<string, string>}  $contents
     */
    private function apply(array $contents): void
    {
        // Files first. Any row restored below may name one of them, and a file
        // written for a restore that then fails is only an orphan - which
        // uploads:prune-orphans already clears.
        $uploads = UploadStore::disk();

        foreach ($contents['files'] as $path => $local) {
            $stream = fopen($local, 'rb');
            $written = $uploads->writeStream($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($written === false) {
                throw new BackupRefused('Files could not be restored.');
            }
        }

        $manifestTables = (array) $contents['manifest']['tables'];

        DB::transaction(function () use ($contents, $manifestTables): void {
            $this->withoutForeignKeyChecks(function () use ($contents, $manifestTables): void {
                // Every table, including any created after the backup was
                // taken: at the moment it describes, they held nothing.
                foreach ($this->tables() as $table) {
                    DB::table($table)->delete();
                }

                foreach ($contents['tables'] as $table => $local) {
                    $this->load($table, (array) $manifestTables[$table]['columns'], $local);
                }
            });
        });

        // Restored settings, contents and counts must not be read from a
        // cache that still describes the data just replaced.
        Cache::flush();

        $this->signEveryoneOut();
    }

    /**
     * @param  list<string>  $columns
     */
    private function load(string $table, array $columns, string $local): void
    {
        $perInsert = max(1, intdiv(self::VALUES_PER_INSERT, max(1, count($columns))));
        $batch = [];

        $this->eachRow($local, $columns, function (array $row) use ($table, $perInsert, &$batch): bool {
            $batch[] = $row;

            if (count($batch) >= $perInsert) {
                DB::table($table)->insert($batch);
                $batch = [];
            }

            return true;
        });

        if ($batch !== []) {
            DB::table($table)->insert($batch);
        }
    }

    /**
     * Read a table file row by row, as column => value.
     *
     * @param  list<string>  $columns
     * @param  Closure(array<string, mixed>): bool  $callback  false stops early
     */
    private function eachRow(string $local, array $columns, Closure $callback): void
    {
        $in = fopen($local, 'rb');

        try {
            $header = json_decode((string) fgets($in), true);

            if ($header !== $columns) {
                throw new BackupRefused('Backup failed verification.');
            }

            while (($line = fgets($in)) !== false) {
                $line = rtrim($line, "\r\n");

                if ($line === '') {
                    continue;
                }

                $values = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                if (! is_array($values) || count($values) !== count($columns)) {
                    throw new BackupRefused('Backup failed verification.');
                }

                if ($callback(array_combine($columns, array_map($this->decodeValue(...), $values))) === false) {
                    return;
                }
            }
        } finally {
            fclose($in);
        }
    }

    /**
     * With foreign keys unchecked until the swap is complete - tables are
     * emptied and refilled in name order, not dependency order.
     *
     * SQLite cannot switch them off inside a transaction, but can defer them
     * to its commit, by which point every row is back.
     */
    private function withoutForeignKeyChecks(Closure $callback): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
            $callback();

            return;
        }

        Schema::disableForeignKeyConstraints();

        try {
            $callback();
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * A session names an account by id, and after a restore that id may be
     * somebody else - or nobody. Everybody signs in again.
     */
    private function signEveryoneOut(): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $table = (string) config('session.table', 'sessions');

        if (Schema::hasTable($table)) {
            DB::table($table)->delete();
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Strings that are not UTF-8 cannot travel as JSON, so binary values are
     * carried as base64 inside a marker no column value can be: a database
     * hands back scalars, never arrays.
     */
    private function encodeValue(mixed $value): mixed
    {
        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            return ['base64' => base64_encode($value)];
        }

        return $value;
    }

    private function decodeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return base64_decode((string) ($value['base64'] ?? ''), true);
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function uploadFolders(): array
    {
        return array_values(array_unique(array_map('strval', (array) config('uploads.folders', []))));
    }

    private function download(string $path, string $local): void
    {
        $source = $this->disk()->exists($path) ? $this->disk()->readStream($path) : null;

        if (! is_resource($source)) {
            throw new BackupRefused('Backup file is missing.');
        }

        $this->copyStream($source, $local);
    }

    /**
     * @param  resource  $source
     */
    private function copyStream($source, string $local): void
    {
        $out = fopen($local, 'wb');

        try {
            stream_copy_to_stream($source, $out);
        } finally {
            fclose($out);
            fclose($source);
        }
    }

    /**
     * One backup, restore or delete at a time: two restores racing each
     * other, or a backup reading tables a restore is halfway through
     * replacing, would each produce something nobody asked for.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function exclusively(Closure $callback): mixed
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new BackupRefused('Another backup is running.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(string): TResult  $callback
     * @return TResult
     */
    private function inWorkDirectory(Closure $callback): mixed
    {
        $work = storage_path('app/backup-work/'.Str::uuid()->toString());
        File::ensureDirectoryExists($work);

        try {
            return $callback($work);
        } finally {
            File::deleteDirectory($work);
        }
    }
}
