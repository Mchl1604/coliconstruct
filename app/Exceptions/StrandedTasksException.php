<?php

namespace App\Exceptions;

use App\Models\Task;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A schedule change would leave open tasks with no booked day at either end,
 * and nobody has confirmed that their dates may be cleared.
 *
 * Thrown inside the save's transaction so nothing is half-written while the
 * question is put, the same way HistoricalConflictException is. The editor
 * normally asks first - see ScheduleController::taskImpact() - so reaching this
 * means that check did not run, and the save is refused rather than clearing
 * dates somebody never saw.
 */
class StrandedTasksException extends RuntimeException
{
    /**
     * @param  Collection<int, Task>  $tasks
     */
    public function __construct(private readonly Collection $tasks, string $message)
    {
        parent::__construct($message);
    }

    /**
     * @return Collection<int, Task>
     */
    public function tasks(): Collection
    {
        return $this->tasks;
    }
}
