<?php

namespace App\Http\Controllers;

use App\Exceptions\BackupRefused;
use App\Models\ActivityLog;
use App\Models\DatabaseBackup;
use App\Services\ActivityLogger;
use App\Services\DatabaseBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Configuration -> System Settings -> Backup & Restore.
 *
 * Super Admin only, on the route group: a backup is every row and every file
 * in the system, and a restore replaces all of them.
 *
 * A restore asks for the Super Admin's password and for RESTORE typed out,
 * and ends with everybody signed out - see DatabaseBackupService for why.
 */
class DatabaseBackupController extends Controller
{
    /**
     * What has to be typed to confirm a restore.
     */
    public const CONFIRMATION_WORD = 'RESTORE';

    public function __construct(
        private readonly DatabaseBackupService $backups,
        private readonly ActivityLogger $activityLogger
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['backups' => $this->listing()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'include_files' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $this->allowLongRun();

        try {
            $backup = $this->backups->create($request->user(), $request->boolean('include_files', true));
        } catch (BackupRefused $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Backup failed. Try again.'], 500);
        }

        $this->activityLogger->record(
            ActivityLog::BACKUP_CREATED,
            null,
            sprintf('Created backup %s.', $backup->file_name),
            $backup
        );

        return response()->json([
            'message' => 'Backup created.',
            'backups' => $this->listing(),
        ]);
    }

    public function download(DatabaseBackup $backup): StreamedResponse
    {
        $disk = $this->backups->disk();

        abort_unless($disk->exists($backup->path), 404);

        $this->activityLogger->record(
            ActivityLog::BACKUP_DOWNLOADED,
            null,
            sprintf('Downloaded backup %s.', $backup->file_name),
            $backup
        );

        return $disk->download($backup->path, $backup->file_name);
    }

    public function restore(Request $request, DatabaseBackup $backup): JsonResponse
    {
        if ($error = $this->confirmationError($request)) {
            return response()->json(['error' => $error], 422);
        }

        $this->allowLongRun();

        try {
            $safety = $this->backups->restore($backup, $request->user());
        } catch (BackupRefused $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Restore failed. Nothing changed.'], 500);
        }

        $this->activityLogger->record(
            ActivityLog::RESTORE_PERFORMED,
            null,
            sprintf('Restored %s; previous data saved as %s.', $backup->file_name, $safety->file_name),
            $backup
        );

        return $this->signedOut($request);
    }

    /**
     * Upload & Restore: an archive downloaded earlier, put back in one step.
     */
    public function upload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'backup_file' => ['required', 'file'],
        ], [
            'backup_file.required' => 'Choose a backup file.',
            'backup_file.file' => 'File failed to upload.',
            'backup_file.uploaded' => 'File failed to upload.',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $file = $request->file('backup_file');

        if (! str_ends_with(strtolower($file->getClientOriginalName()), '.tar.gz')) {
            return response()->json(['error' => 'Choose a .tar.gz backup.'], 422);
        }

        if ($error = $this->confirmationError($request)) {
            return response()->json(['error' => $error], 422);
        }

        $this->allowLongRun();

        try {
            $result = $this->backups->restoreUpload($file->getRealPath(), $request->user());
        } catch (BackupRefused $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Restore failed. Nothing changed.'], 500);
        }

        $this->activityLogger->record(
            ActivityLog::RESTORE_PERFORMED,
            null,
            sprintf(
                'Restored uploaded %s; previous data saved as %s.',
                $result['uploaded']->file_name,
                $result['safety']->file_name
            ),
            $result['uploaded']
        );

        return $this->signedOut($request);
    }

    public function destroy(DatabaseBackup $backup): JsonResponse
    {
        try {
            $this->backups->delete($backup);
        } catch (BackupRefused $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::BACKUP_DELETED,
            null,
            sprintf('Deleted backup %s.', $backup->file_name)
        );

        return response()->json([
            'message' => 'Backup deleted.',
            'backups' => $this->listing(),
        ]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function listing(): array
    {
        return $this->backups->all()
            ->map(fn (DatabaseBackup $backup): array => $backup->toListing())
            ->values()
            ->all();
    }

    /**
     * Hand-rolled, as the rest of Configuration is: the app only renders a
     * thrown ValidationException as JSON for api/* paths.
     */
    private function confirmationError(Request $request): ?string
    {
        $validator = Validator::make($request->all(), [
            'password' => ['required', 'string'],
            'confirmation' => ['required', 'string'],
        ], [
            'password.required' => 'Enter your password.',
            'confirmation.required' => 'Type '.self::CONFIRMATION_WORD.' to confirm.',
        ]);

        if ($validator->fails()) {
            return $validator->errors()->first();
        }

        if (! Hash::check((string) $request->input('password'), (string) $request->user()->password)) {
            return 'Incorrect password.';
        }

        if (strtoupper(trim((string) $request->input('confirmation'))) !== self::CONFIRMATION_WORD) {
            return 'Type '.self::CONFIRMATION_WORD.' to confirm.';
        }

        return null;
    }

    /**
     * The restored accounts may not include this one, or may hold another
     * password for it - so the restore ends with signing in again.
     */
    private function signedOut(Request $request): JsonResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('success', 'Restore complete. Sign in again.');

        return response()->json([
            'message' => 'Restore complete.',
            'redirect' => route('auth.login'),
        ]);
    }

    /**
     * A backup reads every file on the uploads disk, and a restore must not
     * be cut off half way by a closed tab or a time limit.
     */
    private function allowLongRun(): void
    {
        ignore_user_abort(true);

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
    }
}
