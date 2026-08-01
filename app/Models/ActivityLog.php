<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One administrative action, recorded for audit.
 *
 * The actor and subject names are stored alongside their ids so an entry still
 * reads correctly after either account is renamed or removed.
 */
class ActivityLog extends Model
{
    protected $table = 'tbl_activity_logs';

    protected $primaryKey = 'activity_log_id';

    /**
     * Every action the User Management module records. Kept as constants so a
     * log query never depends on a hand-typed string.
     */
    public const EMPLOYEE_CREATED = 'Employee Account Created';

    public const EMPLOYEE_UPDATED = 'Employee Updated';

    public const EMPLOYEE_ACTIVATED = 'Employee Activated';

    public const EMPLOYEE_DEACTIVATED = 'Employee Deactivated';

    public const EMPLOYEE_ARCHIVED = 'Employee Archived';

    public const CLIENT_CREATED = 'Client Account Created';

    public const CLIENT_UPDATED = 'Client Updated';

    public const CLIENT_PASSWORD_RESET = 'Client Password Reset';

    public const CLIENT_ACTIVATED = 'Client Activated';

    public const CLIENT_DEACTIVATED = 'Client Deactivated';

    public const CLIENT_ARCHIVED = 'Client Archived';

    protected $fillable = [
        'actor_id',
        'actor_name',
        'action',
        'description',
        'subject_id',
        'subject_name',
        'ip_address',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id', 'id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_id', 'id');
    }

    /**
     * Newest first, which is the only order the log is ever read in.
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('activity_log_id');
    }
}
