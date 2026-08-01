<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The single way an administrative action reaches the audit trail.
 *
 * Nothing here throws: an audit entry must never be the reason a legitimate
 * action fails, and the action itself is already committed by the time this
 * is called.
 */
class ActivityLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * Record one action against the account it affected.
     */
    public function record(string $action, ?User $subject = null, ?string $description = null): ActivityLog
    {
        $actor = $this->request->user();

        return ActivityLog::create([
            'actor_id' => $actor?->id,
            // Authentication is not built yet, so an action taken from the
            // Configuration page has no signed-in administrator to name.
            'actor_name' => $actor?->fullName() ?? 'System Administrator',
            'action' => $action,
            'description' => $description ?? $this->defaultDescription($action, $subject),
            'subject_id' => $subject?->id,
            'subject_name' => $subject?->fullName(),
            'ip_address' => $this->request->ip(),
        ]);
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
}
