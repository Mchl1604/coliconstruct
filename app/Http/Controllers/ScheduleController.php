<?php
namespace App\Http\Controllers;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;


class ScheduleController extends Controller
{
    /**
     * Project statuses whose schedules count as "busy" time for a technician.
     *
     * @var array<int, string>
     */
    private const ACTIVE_PROJECT_STATUSES = ['pending', 'ongoing'];

    public function index()
    {
        $projects = Project::query()
            ->with([
                'clients',
                'schedules',
                'projectTechnicians.technician.account',
            ])
            ->where('is_archived', false)
            ->orderBy('project_id', 'desc')
            ->get();

        $calendarEvents = $this->buildCalendarEvents($projects);
        $technicianSchedules = $this->buildTechnicianSchedules();

        return view('super-admin.schedule', compact(
            'projects',
            'calendarEvents',
            'technicianSchedules'
        ));
    }

    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'ranges' => ['required', 'array', 'min:1'],
            'ranges.*.schedule_id' => ['nullable', 'integer', 'exists:tbl_schedule,schedule_id'],
            'ranges.*.start_date' => ['required', 'date'],
            'ranges.*.end_date' => ['required', 'date', 'after_or_equal:ranges.*.start_date'],
        ], [
            'ranges.required' => 'At least one date range is required.',
            'ranges.min' => 'At least one date range is required.',
        ]);

        $project = Project::with(['schedules', 'projectTechnicians'])->findOrFail($id);

        try {
            DB::transaction(function () use ($validated, $project): void {
                $ranges = collect($validated['ranges'])->map(function (array $range) {
                    return [
                        'schedule_id' => isset($range['schedule_id']) ? (int) $range['schedule_id'] : null,
                        'start' => CarbonImmutable::parse($range['start_date'])->startOfDay(),
                        'end' => CarbonImmutable::parse($range['end_date'])->endOfDay(),
                    ];
                });

                $this->assertNewRangesNotInPast($ranges);
                $this->assertNoOverlapWithinSubmission($ranges);
                $this->assertRangesAvailable($project, $ranges);

                $keepScheduleIds = $ranges->pluck('schedule_id')->filter()->values();

                $project->schedules()
                    ->whereNotIn('schedule_id', $keepScheduleIds->all())
                    ->get()
                    ->each(fn (Schedule $schedule) => $schedule->delete());

                $projectTechnicianIds = $project->projectTechnicians->pluck('project_technician_id');

                $ranges->each(function (array $range) use ($project, $projectTechnicianIds): void {
                    if ($range['schedule_id']) {
                        Schedule::query()
                            ->where('project_id', $project->project_id)
                            ->where('schedule_id', $range['schedule_id'])
                            ->update([
                                'start_datetime' => $range['start'],
                                'end_datetime' => $range['end'],
                            ]);

                        return;
                    }

                    $schedule = Schedule::create([
                        'project_id' => $project->project_id,
                        'start_datetime' => $range['start'],
                        'end_datetime' => $range['end'],
                        'status' => 'scheduled',
                        'remarks' => 'Added from schedules page',
                    ]);

                    $projectTechnicianIds->each(function (int $projectTechnicianId) use ($schedule): void {
                        ScheduleTechnician::create([
                            'schedule_id' => $schedule->schedule_id,
                            'project_technician_id' => $projectTechnicianId,
                        ]);
                    });
                });
            });

            return redirect()
                ->route('super-admin.schedules.index')
                ->with('success', 'Schedule updated successfully.');
        } catch (Throwable $e) {
            return redirect()
                ->route('super-admin.schedules.index')
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Ensure none of the submitted ranges collide with another project's
     * schedule for any technician assigned to this project.
     *
     * @param  Collection<int, array{schedule_id: ?int, start: CarbonImmutable, end: CarbonImmutable}>  $ranges
     */
    private function assertRangesAvailable(Project $project, Collection $ranges): void
    {
        $technicianIds = $project->projectTechnicians->pluck('technician_id')->unique()->values();

        if ($technicianIds->isEmpty()) {
            return;
        }

        $busySchedules = Schedule::query()
            ->where('project_id', '!=', $project->project_id)
            ->whereIn('status', self::ACTIVE_PROJECT_STATUSES)
            ->whereHas('scheduleTechnicians.projectTechnician', function ($query) use ($technicianIds): void {
                $query->whereIn('technician_id', $technicianIds->all());
            })
            ->with(['scheduleTechnicians.projectTechnician.technician.account'])
            ->get(['schedule_id', 'project_id', 'start_datetime', 'end_datetime']);

        foreach ($ranges as $range) {
            foreach ($busySchedules as $busySchedule) {
                $overlaps = $range['start']->lte($busySchedule->end_datetime)
                    && $range['end']->gte($busySchedule->start_datetime);

                if (! $overlaps) {
                    continue;
                }

                $conflictingTechnicianNames = $busySchedule->scheduleTechnicians
                    ->map(fn (ScheduleTechnician $scheduleTechnician) => $scheduleTechnician->projectTechnician)
                    ->filter()
                    ->filter(fn ($projectTechnician) => $technicianIds->contains($projectTechnician->technician_id))
                    ->map(fn ($projectTechnician) => $projectTechnician->technician?->name)
                    ->filter()
                    ->unique()
                    ->values();

                $technicianLabel = $conflictingTechnicianNames->isNotEmpty()
                    ? $conflictingTechnicianNames->join(', ')
                    : 'an assigned technician';

                throw new RuntimeException(sprintf(
                    '%s to %s is not available: %s is already scheduled on another project during that period.',
                    $range['start']->toDateString(),
                    $range['end']->toDateString(),
                    $technicianLabel
                ));
            }
        }
    }

    /**
     * New date ranges (no existing schedule_id) cannot start before today.
     * Existing ranges are left alone so already-saved past dates don't
     * block an unrelated edit to the same project.
     *
     * @param  Collection<int, array{schedule_id: ?int, start: CarbonImmutable, end: CarbonImmutable}>  $ranges
     */
    private function assertNewRangesNotInPast(Collection $ranges): void
    {
        $today = CarbonImmutable::today();

        foreach ($ranges as $range) {
            if ($range['schedule_id']) {
                continue;
            }

            if ($range['start']->lt($today)) {
                throw new RuntimeException('New date ranges cannot start before today.');
            }
        }
    }

    /**
     * Ranges submitted together for the same project must not overlap each
     * other, whether they're pre-existing ranges being edited or brand new
     * ones being added alongside them. This is what stops a range added on
     * a previous edit from remaining pickable when adding another one.
     *
     * @param  Collection<int, array{schedule_id: ?int, start: CarbonImmutable, end: CarbonImmutable}>  $ranges
     */
    private function assertNoOverlapWithinSubmission(Collection $ranges): void
    {
        $rangesList = $ranges->values();

        for ($i = 0; $i < $rangesList->count(); $i++) {
            for ($j = $i + 1; $j < $rangesList->count(); $j++) {
                $a = $rangesList[$i];
                $b = $rangesList[$j];

                $overlaps = $a['start']->lte($b['end']) && $a['end']->gte($b['start']);

                if ($overlaps) {
                    throw new RuntimeException(sprintf(
                        'Date range %s to %s overlaps with %s to %s in the same submission.',
                        $a['start']->toDateString(),
                        $a['end']->toDateString(),
                        $b['start']->toDateString(),
                        $b['end']->toDateString()
                    ));
                }
            }
        }
    }

    /**
     * Build FullCalendar-ready events, one per schedule date range.
     * Title is kept to the reference number only to avoid cluttered bars;
     * the project name is passed separately for the hover tooltip.
     *
     * @param  \Illuminate\Support\Collection<int, Project>  $projects
     * @return array<int, array<string, mixed>>
     */
    private function buildCalendarEvents(Collection $projects): array
    {
        $statusColors = [
            'pending' => '#f0ad4e',
            'ongoing' => '#0d6efd',
            'completed' => '#198754',
            'cancelled' => '#dc3545',
        ];

        $events = [];

        foreach ($projects as $project) {
            $color = $project->on_hold
                ? '#6c757d'
                : ($statusColors[$project->status] ?? '#0d6efd');

            foreach ($project->schedules as $schedule) {
                $start = CarbonImmutable::parse($schedule->start_datetime);
                $end = CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime);

                $events[] = [
                    'id' => $schedule->schedule_id,
                    'title' => $project->reference_no,
                    'start' => $start->toDateString(),
                    // FullCalendar's end date for all-day events is exclusive.
                    'end' => $end->addDay()->toDateString(),
                    'color' => $color,
                    'extendedProps' => [
                        'projectId' => $project->project_id,
                        'scheduleId' => $schedule->schedule_id,
                        'referenceNo' => $project->reference_no,
                        'projectName' => $project->name,
                        'status' => $project->status,
                        'onHold' => $project->on_hold,
                    ],
                ];
            }
        }

        return $events;
    }

    /**
     * Build a map of technician_id => list of busy date ranges, tagged with
     * the project each range belongs to, for every active project schedule.
     *
     * @return array<int, array<int, array{start: string, end: string, project_id: int}>>
     */
    private function buildTechnicianSchedules(): array
    {
        $schedules = Schedule::query()
            ->whereHas('project', function ($query): void {
                $query->whereIn('status', self::ACTIVE_PROJECT_STATUSES);
            })
            ->with([
                'scheduleTechnicians:schedule_technician_id,schedule_id,project_technician_id',
                'scheduleTechnicians.projectTechnician:project_technician_id,technician_id',
            ])
            ->get(['schedule_id', 'project_id', 'start_datetime', 'end_datetime']);

        $scheduleMap = [];

        foreach ($schedules as $schedule) {
            $startDate = CarbonImmutable::parse($schedule->start_datetime)->toDateString();
            $endDate = CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime)->toDateString();

            foreach ($schedule->scheduleTechnicians as $scheduleTechnician) {
                $technicianId = $scheduleTechnician->projectTechnician?->technician_id;

                if (! $technicianId) {
                    continue;
                }

                $scheduleMap[$technicianId][] = [
                    'start' => $startDate,
                    'end' => $endDate,
                    'project_id' => $schedule->project_id,
                ];
            }
        }

        return $scheduleMap;
    }
}