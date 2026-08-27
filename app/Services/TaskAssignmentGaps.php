<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tasks that cannot proceed because they are incomplete, counted and worded.
 *
 * A task is stuck when nobody holds it, when it has no dates to be done
 * between, or when it has neither. Task::scopeNeedsAssignment() decides that -
 * one rule, in SQL - and this turns it into the figures and the sentences the
 * two portals print.
 *
 * Both portals pass their OWN base query in: the Super Admin dashboard hands
 * over every task, a lead hands over the tasks on the projects they are on.
 * The permission filter is therefore the caller's, and the definition of
 * "stuck" is not - which is the whole point of the split. Nobody gets to
 * invent a second answer to "is this task unassigned?".
 *
 * Nothing is stored. The alert is the current state of the task rows, so
 * filling in a technician or a date clears it on the next read rather than
 * leaving a record somebody has to go and dismiss.
 */
class TaskAssignmentGaps
{
    /**
     * The gaps in the order they are offered, worst first: a task missing both
     * fields is further from being workable than one missing either.
     *
     * @var array<int, string>
     */
    public const ORDER = [Task::GAP_BOTH, Task::GAP_TECHNICIAN, Task::GAP_DATE];

    /**
     * What the affected tasks in this query add up to.
     *
     * Returns the three counts, their total, and one line of prose per
     * non-empty gap for the alert to print. `total` of zero means there is
     * nothing to say, and every caller draws nothing in that case rather than
     * an empty panel or a row of noughts.
     *
     * @param  Builder<Task>|null  $tasks  the caller's already-permitted scope;
     *                                     null means every task
     * @return array{
     *     total: int,
     *     counts: array<string, int>,
     *     lines: array<int, array{gap: string, count: int, label: string, text: string}>
     * }
     */
    public function summarise(?Builder $tasks = null): array
    {
        $base = $tasks ?? Task::query();

        $counts = [];

        foreach (self::ORDER as $gap) {
            // One count per gap, each off a fresh clone of the caller's scope:
            // the three are mutually exclusive by construction, so they add up
            // to the total without a fourth query to check.
            $counts[$gap] = (clone $base)
                ->needsAssignment()
                ->withAssignmentGap($gap)
                ->count();
        }

        $lines = [];

        foreach (self::ORDER as $gap) {
            if ($counts[$gap] === 0) {
                continue;
            }

            $lines[] = [
                'gap' => $gap,
                'count' => $counts[$gap],
                'label' => Task::GAP_LABELS[$gap],
                // "2 tasks missing technician" - the whole sentence, so a view
                // prints one string and cannot get the agreement wrong.
                'text' => self::sentence($counts[$gap], $gap),
            ];
        }

        return [
            'total' => array_sum($counts),
            'counts' => $counts,
            'lines' => $lines,
        ];
    }

    /**
     * The headline over the breakdown: "3 tasks need attention".
     */
    public static function headline(int $total): string
    {
        return $total.' '.($total === 1 ? 'task needs' : 'tasks need').' attention';
    }

    /**
     * The one-line summary the Super Admin dashboard shows in place of a
     * breakdown: "5 tasks need a technician or date".
     */
    public static function dashboardSummary(int $total): string
    {
        return $total.' '.($total === 1 ? 'task needs' : 'tasks need').' a technician or date';
    }

    /**
     * One gap as a sentence: "2 tasks missing technician".
     */
    private static function sentence(int $count, string $gap): string
    {
        $what = match ($gap) {
            Task::GAP_TECHNICIAN => 'missing technician',
            Task::GAP_DATE => 'missing date',
            default => 'missing technician and date',
        };

        return $count.' '.($count === 1 ? 'task' : 'tasks').' '.$what;
    }
}
