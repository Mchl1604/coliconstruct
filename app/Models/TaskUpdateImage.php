<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Gate;

/**
 * A photograph attached to one day's progress on a task.
 */
class TaskUpdateImage extends Model
{
    protected $table = 'tbl_task_update_images';

    protected $primaryKey = 'task_update_image_id';

    protected $fillable = [
        'task_update_id',
        'image_path',
    ];

    /**
     * Where a page links to this image - a route that checks the asking
     * account against the task's project, never the file's own location.
     */
    public function url(): string
    {
        return route('media.task-update-image', ['image' => $this->getKey()]);
    }

    /**
     * Those of these images this account may open, on the media route's own
     * terms: a plain technician reads their own tasks only, so a page leaves
     * a colleague's photo out rather than drawing it broken.
     *
     * @param  array<int, int>  $imageIds
     * @return array<int, int>
     */
    public static function idsViewableBy(User $user, array $imageIds): array
    {
        return self::query()
            ->with('taskUpdate.task')
            ->whereKey($imageIds)
            ->get()
            ->filter(fn (self $image): bool => ! $user->needsTechnicianRecord()
                || ($image->taskUpdate?->task !== null && Gate::forUser($user)->allows('view', $image->taskUpdate->task)))
            ->modelKeys();
    }

    /**
     * Not update(): that name is Eloquent's own.
     */
    public function taskUpdate(): BelongsTo
    {
        return $this->belongsTo(TaskUpdate::class, 'task_update_id', 'task_update_id');
    }
}
