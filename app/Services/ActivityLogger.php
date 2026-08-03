<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single way an action reaches the audit trail.
 *
 * Every module calls record() and nothing else: the module an action belongs
 * to, the actor's role, and where they were working from are all derived here,
 * so no caller has to remember them and no two callers can disagree.
 *
 * Two guarantees hold whatever the caller does:
 *
 *   - Nothing thrown here escapes. An audit entry must never be the reason a
 *     legitimate action fails.
 *   - Inside a transaction the write is deferred to after the commit, so a
 *     rolled-back action leaves no trace claiming it happened.
 */
class ActivityLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * Record one action.
     *
     * @param  string  $action  One of the ActivityLog constants.
     * @param  User|null  $subject  The account the action was about, if any.
     * @param  string|null  $description  Overrides the generated sentence.
     * @param  Model|null  $record  What the action was about when it was not
     *                              an account: a project, a task, a report.
     */
    public function record(
        string $action,
        ?User $subject = null,
        ?string $description = null,
        ?Model $record = null
    ): void {
        // Read the request now: by the time an after-commit callback runs, the
        // actor still resolves, but reading eagerly keeps the snapshot honest
        // and the callback cheap.
        $attributes = $this->attributes($action, $subject, $description, $record);

        // Outside a transaction this fires immediately.
        DB::afterCommit(function () use ($attributes): void {
            $this->write($attributes);
        });
    }

    /**
     * Record an action nobody is signed in for.
     *
     * A failed sign-in is the case that matters: there is no authenticated
     * actor, but the address that was tried is exactly what an audit trail is
     * for. The account, when it exists, is named as the subject.
     */
    public function recordAnonymous(string $action, string $actorName, ?User $subject = null, ?string $description = null): void
    {
        $attributes = array_merge($this->attributes($action, $subject, $description), [
            'actor_id' => null,
            'actor_name' => $actorName,
            'actor_role' => $subject?->role,
        ]);

        DB::afterCommit(function () use ($attributes): void {
            $this->write($attributes);
        });
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function attributes(
        string $action,
        ?User $subject,
        ?string $description,
        ?Model $record = null
    ): array {
        $actor = $this->request->user();
        $agent = (string) $this->request->userAgent();

        return [
            'actor_id' => $actor?->id,
            // Actions taken by a console command or a scheduled job have no
            // signed-in administrator to name.
            'actor_name' => $actor?->fullName() ?? 'System',
            'actor_role' => $actor?->role,

            'action' => $action,
            'module' => ActivityLog::moduleFor($action),
            'description' => $description ?? $this->defaultDescription($action, $subject),

            'subject_id' => $subject?->id,
            'subject_name' => $subject?->fullName(),
            'subject_role' => $subject?->role,

            'record_type' => $record ? class_basename($record) : null,
            'record_id' => $record?->getKey(),

            'ip_address' => $this->request->ip(),
            'browser' => $this->browser($agent),
            'operating_system' => $this->operatingSystem($agent),
            'user_agent' => $agent ?: null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(array $attributes): void
    {
        try {
            ActivityLog::create($attributes);
        } catch (Throwable $exception) {
            // The action itself already succeeded. Losing its audit entry is
            // worth knowing about, but not worth failing the request over.
            Log::error('Could not write an activity log entry.', [
                'action' => $attributes['action'] ?? null,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * "Employee Account Created" on its own says nothing about who it was
     * about; this fills that in when the caller has nothing more specific.
     */
    private function defaultDescription(string $action, ?User $subject): string
    {
        if (! $subject) {
            return $action;
        }

        return sprintf('%s - %s (%s)', $action, $subject->fullName(), $subject->user_code);
    }

    /**
     * Enough of the user agent to answer "what were they using".
     *
     * Deliberately coarse: this is context for a human reading an audit trail,
     * not analytics, and a full parser is a dependency this does not need.
     * Order matters - Edge and Opera both claim to be Chrome, and Chrome
     * claims to be Safari.
     */
    private function browser(string $agent): ?string
    {
        if ($agent === '') {
            return null;
        }

        return match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/'), str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Other',
        };
    }

    private function operatingSystem(string $agent): ?string
    {
        if ($agent === '') {
            return null;
        }

        return match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            // Checked before macOS: an iPhone user agent says "like Mac OS X".
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS X'), str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Other',
        };
    }
}
