<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Task;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * The morning reminder run.
 *
 * Three passes over the same set of open tasks: due tomorrow, due today, and
 * already past their date. Scheduled once a day at 08:00, and safe to run
 * again by hand - NotificationService suppresses a repeat of the same message
 * to the same person within the day, so a second run adds nothing.
 */
class SendTaskReminders extends Command
{
    protected $signature = 'tasks:remind';

    protected $description = 'Notify technicians and leads about tasks due tomorrow, due today, or overdue.';

    public function handle(NotificationService $notifications): int
    {
        $today = CarbonImmutable::today();
        $tomorrow = $today->addDay();

        $dueTomorrow = $this->openTasksDue($tomorrow->toDateString());
        $dueToday = $this->openTasksDue($today->toDateString());
        $overdue = $this->openTasksBefore($today->toDateString());

        foreach ($dueTomorrow as $task) {
            $notifications->taskDueTomorrow($task);
        }

        foreach ($dueToday as $task) {
            $notifications->taskDueToday($task);
        }

        foreach ($overdue as $task) {
            $notifications->taskOverdue($task);
        }

        $this->info(sprintf(
            'Reminders sent: %d due tomorrow, %d due today, %d overdue.',
            $dueTomorrow->count(),
            $dueToday->count(),
            $overdue->count()
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Task>
     */
    private function openTasksDue(string $date)
    {
        return $this->openTasks()->whereDate('due_date', $date)->get();
    }

    /**
     * @return Collection<int, Task>
     */
    private function openTasksBefore(string $date)
    {
        return $this->openTasks()->whereDate('due_date', '<', $date)->get();
    }

    /**
     * Tasks that still owe work, on projects that are still live. A reminder
     * about a completed, cancelled or archived project is noise.
     */
    private function openTasks()
    {
        return Task::query()
            ->with(['technician.account', 'project.projectTechnicians.technician.account'])
            ->whereIn('status', Task::OPEN_STATUSES)
            ->whereNotNull('due_date')
            ->whereHas('project', function ($query): void {
                $query->whereIn('status', Project::ACTIVE_PROJECT_STATUSES)
                    ->where('is_archived', false);
            });
    }
}
