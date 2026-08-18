<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectCompletionPhoto;
use App\Models\Schedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Closing a project out: what it does to the booked dates, and the three
 * states the work passes through on its way to being finished.
 *
 *   Complete Project   ->  Awaiting Client Confirmation
 *   Confirm Completion ->  Completed          (the client agreed)
 *   seven days pass    ->  Completed          (nobody answered)
 *
 * The company saying the work is done is not the same thing as the work being
 * signed off, and the two used to be one step. Splitting them is the whole of
 * this class: requestCompletion() does everything that used to happen at
 * completion except closing the project, and confirm() closes it.
 *
 * The dates are released at the first step, not the second. Work often
 * finishes ahead of schedule, and the dates still booked past the completion
 * date are then a lie: the project reads as ongoing on a calendar, the
 * technicians on it read as busy, and it can go on to look overdue for a job
 * that is already done. Waiting a week for a client to reply would keep that
 * lie in place for a week.
 */
class ProjectCompletion
{
    /**
     * The folder completion photographs live in, under the uploads root.
     */
    private const PHOTO_FOLDER = 'completion';

    /**
     * The completion report both portals collect.
     *
     * One rule set rather than two: the Super Admin modal and the technician's
     * dialog write the same columns, and a difference between them would mean
     * the same project could be closed out to two different standards.
     *
     * `$photosRequired` is the one deliberate difference. A lead is on site,
     * so the photographs are the evidence and are required of them; an
     * administrator closing a project from the office may not have any.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(bool $photosRequired = false): array
    {
        return [
            // Bounded at both ends. A completion date in the future would
            // silently defeat releaseFutureSchedules() - every booked date
            // would fall before the cutoff and nothing would be released -
            // and a project cannot have been finished before it was created.
            'completion_date' => ['required', 'date', 'before_or_equal:today'],
            'completion_summary' => ['required', 'string'],
            'completion_remarks' => ['nullable', 'string'],
            'completion_photos' => $photosRequired
                ? ['required', 'array', 'min:1', 'max:20']
                : ['nullable', 'array', 'max:20'],
            // Matched to every other photo upload in the system, so a phone
            // picture is always accepted and nothing arrives that the browser
            // then struggles to show.
            'completion_photos.*' => ['file', 'mimes:jpg,jpeg,png', 'max:5120'],

            // Only ever filled in when the completion rules objected and an
            // administrator went ahead anyway. Optional here because the rules
            // are what decide whether it is needed - see
            // ProjectController::complete(), which requires it once it knows
            // there is something to override. A lead technician never sends
            // it: their route refuses a project with blockers outright.
            'completion_override_reason' => ['nullable', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'completion_date.before_or_equal' => 'The completion date cannot be in the future.',
            'completion_photos.required' => 'At least one completion photo is required.',
            'completion_photos.min' => 'At least one completion photo is required.',
            'completion_photos.max' => 'Up to 20 completion photos can be uploaded at once.',
            'completion_photos.*.max' => 'Each completion photo must be 5 MB or smaller.',
        ];
    }

    /**
     * Hand a finished project over to its client for confirmation.
     *
     * Everything here is one unit of work and every caller runs it inside a
     * transaction: the status, the report, the photographs and the released
     * dates either all land or none of them do. A project left Awaiting
     * Confirmation with its dates still booked - or released dates on a
     * project still reading as ongoing - would be worse than the failure.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<int, UploadedFile>|null  $photos
     */
    public function requestCompletion(
        Project $project,
        array $validated,
        ?array $photos,
        ?User $actor = null,
        array $overriddenBlockers = []
    ): void {
        $completedOn = CarbonImmutable::parse($validated['completion_date']);

        $project->update($this->overrideColumns($validated, $actor, $overriddenBlockers) + [
            'status' => Project::STATUS_AWAITING_CLIENT_CONFIRMATION,
            'on_hold' => false,
            'completed_at' => $completedOn,
            'completion_summary' => $validated['completion_summary'],
            'completion_remarks' => $validated['completion_remarks'] ?? null,
            'completion_requested_at' => CarbonImmutable::now(),
            'completion_requested_by' => $actor?->id,
            // A project reopened once and completed again starts a fresh
            // seven days, so last time's reminder must not silence this one.
            'completion_reminder_sent_at' => null,
            // Set only when the project actually reaches Completed.
            'client_confirmed_at' => null,
            'client_confirmed_by' => null,
            'completion_method' => null,
        ]);

        $this->storePhotos($project, $photos);

        // Dates booked past the completion date are released: the work is
        // done, so the project must stop reading as booked and its technicians
        // must stop reading as busy.
        $this->releaseFutureSchedules($project, $completedOn);

        // Everything else (technicians, task history, and the days already
        // worked) is intentionally left untouched for auditing and reporting.
    }

    /**
     * What to write about an override, or what to clear when there is not one.
     *
     * Both halves matter. A project completed against the rules records the
     * reason, what the rules objected to, and who decided - and one completed
     * normally records that it was not overridden, rather than inheriting a
     * note from a previous attempt. A project reopened and completed again is
     * exactly that case: the second completion may be perfectly ordinary, and
     * last time's override must not stay attached to it.
     *
     * The blockers are stored as the sentences that were shown rather than as
     * task ids: those tasks may since be finished or gone, and the record has
     * to keep saying what was true when somebody decided to go ahead.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<int, string>  $blockers
     * @return array<string, mixed>
     */
    private function overrideColumns(array $validated, ?User $actor, array $blockers): array
    {
        $reason = trim((string) ($validated['completion_override_reason'] ?? ''));

        if ($reason === '' || $blockers === []) {
            return [
                'completion_override_reason' => null,
                'completion_override_blockers' => null,
                'completion_overridden_by' => null,
            ];
        }

        return [
            'completion_override_reason' => $reason,
            'completion_override_blockers' => array_values($blockers),
            'completion_overridden_by' => $actor?->id,
        ];
    }

    /**
     * Close the project for good.
     *
     * Reached two ways - the client pressed Confirm Completion, or seven days
     * went by without a reply - and the only difference between them is who is
     * recorded and how. Neither touches a schedule: the dates were settled
     * when completion was requested, and the history stands.
     *
     * @param  string  $method  One of the Project::METHOD_* constants.
     */
    public function confirm(Project $project, ?User $client, string $method): void
    {
        $project->update([
            'status' => 'completed',
            'on_hold' => false,
            'client_confirmed_at' => CarbonImmutable::now(),
            'client_confirmed_by' => $client?->id,
            'completion_method' => $method,
        ]);
    }

    /**
     * File the completion photographs against the project.
     *
     * Both portals put them in one place so the completion report reads the
     * same wherever it was written from, and photographs are added rather than
     * swapped - a project reopened and completed again keeps the evidence from
     * the first visit as well as the second.
     *
     * @param  array<int, UploadedFile>|null  $photos
     * @return int how many were stored
     */
    public function storePhotos(Project $project, ?array $photos): int
    {
        $photos = collect($photos ?? [])
            ->filter(fn ($photo): bool => $photo instanceof UploadedFile);

        if ($photos->isEmpty()) {
            return 0;
        }

        // Written to the configured root, which the test suite points at a
        // throwaway directory - move() is not something Storage::fake() can
        // intercept, so without this the suite fills public/uploads.
        $directory = config('uploads.root').'/'.self::PHOTO_FOLDER;

        File::ensureDirectoryExists($directory);

        $photos->each(function (UploadedFile $photo) use ($directory, $project): void {
            // Named by uuid rather than by what it was called on the way in:
            // two people uploading "done.jpg" must not land on one file.
            $fileName = Str::uuid()->toString().'.'.$photo->getClientOriginalExtension();

            $photo->move($directory, $fileName);

            ProjectCompletionPhoto::create([
                'project_id' => $project->project_id,
                // Relative to public/, because asset() reads it back.
                'photo_path' => config('uploads.public_prefix').'/'.self::PHOTO_FOLDER.'/'.$fileName,
                'uploaded_at' => now(),
            ]);
        });

        return $photos->count();
    }

    /**
     * Drop every scheduled day that falls after the completion date.
     *
     * A range wholly in the future is deleted. A range that was already
     * running is kept but cut short at the completion date, because it records
     * days the crew actually worked - deleting it would erase real history.
     * Its schedule_technicians rows go with any deleted range, by cascade.
     *
     * Every range the project holds is examined, not just the next one: work
     * booked in several separate stretches has to be released in all of them,
     * or the crew stays booked for a stretch nobody is going to work.
     *
     * @return int how many ranges were removed entirely
     */
    public function releaseFutureSchedules(Project $project, ?CarbonInterface $completedOn = null): int
    {
        $cutoff = CarbonImmutable::parse($completedOn ?? CarbonImmutable::now())->endOfDay();

        $schedules = Schedule::query()
            ->where('project_id', $project->project_id)
            ->get();

        $removed = 0;

        foreach ($schedules as $schedule) {
            $start = CarbonImmutable::parse($schedule->start_datetime);
            $end = CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime);

            if ($start->gt($cutoff)) {
                $schedule->delete();
                $removed++;

                continue;
            }

            if ($end->gt($cutoff)) {
                $schedule->update(['end_datetime' => $cutoff]);
            }
        }

        // The relation is stale now that rows have gone, and callers go on to
        // read it - isOverdue() consults every range.
        $project->unsetRelation('schedules');

        return $removed;
    }

    /**
     * The dates a project still holds, as a person reads them.
     *
     * Used to say what the released dates left behind, in the activity log and
     * in the toast - "its schedule now runs Aug 6 - Aug 8" is a far more
     * useful confirmation than a count of deleted rows.
     */
    public function describeRemainingSchedule(Project $project): string
    {
        $schedules = Schedule::query()
            ->where('project_id', $project->project_id)
            ->orderBy('start_datetime')
            ->get();

        if ($schedules->isEmpty()) {
            return 'no remaining dates';
        }

        return $schedules->map(fn (Schedule $schedule): string => $schedule->describe())->implode('; ');
    }
}
