<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Services\ProjectPhaseProgress;
use App\Services\ProjectPhaseRules;
use App\Services\ProjectPhaseSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The phase setup screen, and the four actions that change a project's phase
 * structure or move it along.
 *
 * One controller for both portals rather than a copy in each. The setup screen
 * a Lead Technician sees is the same screen an Admin sees - same rows, same
 * rules, same finalize confirmation - and the only thing that differs is the
 * layout it is wrapped in and the page it goes back to. Two copies of it would
 * be two places for the lock to be got wrong.
 */
class ProjectPhaseController extends Controller
{
    public function __construct(
        private readonly ProjectPhaseRules $rules,
        private readonly ProjectPhaseSetup $setup,
        private readonly ProjectPhaseProgress $progress,
    ) {}

    /**
     * The setup-only screen.
     *
     * A project whose phases are already finalized never gets here: it is sent
     * back to its details page, where the monitoring interface is. That is the
     * "there is no normal way back to setup" rule enforced at the door rather
     * than by not drawing a link - a Lead Technician who keeps the URL cannot
     * use it a second time.
     */
    public function setup(Request $request, Project $project)
    {
        $user = $request->user();

        if ($project->phasesAreFinalized()) {
            return redirect()
                ->to($this->projectUrl($request, $project))
                ->with('info', 'This project\'s phases have already been finalized.');
        }

        $this->authorizeSetup($request, $project);

        $phases = $project->phases()->withCount('tasks')->inOrder()->get();

        return view('projects.phaseSetup', [
            'layout' => $this->layoutFor($user),
            'project' => $project,
            'phases' => $phases,
            // A fresh project is offered the four stages most of this
            // company's jobs actually have, as editable rows. Nothing is
            // stored until the person saves or finalizes - see
            // ProjectPhase::SUGGESTED_PHASES.
            'suggested' => $phases->isEmpty() ? ProjectPhase::SUGGESTED_PHASES : [],
            // Only ever non-empty after a Super Admin override: tasks cannot
            // exist on a project that has never been finalized. These are the
            // phases whose removal has to be resolved rather than refused.
            'phasesHoldingTasks' => $this->setup->phasesHoldingTasks($project),
            'projectUrl' => $this->projectUrl($request, $project),
            'saveUrl' => $this->actionUrl($request, $project, 'save'),
            'finalizeUrl' => $this->actionUrl($request, $project, 'finalize'),
            'minPhases' => ProjectPhase::MIN_PHASES,
            'maxPhases' => ProjectPhase::MAX_PHASES,
        ]);
    }

    /**
     * Keep the structure typed so far without locking it.
     *
     * Not in the specification, and there because the alternative is worse:
     * without it the only way out of the setup screen is the irreversible
     * button, so somebody halfway through a nine-phase structure at five
     * o'clock either finalizes something unfinished or loses it.
     */
    public function save(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeSetup($request, $project);

        try {
            $this->setup->save($project, ...$this->submittedStructure($request));
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->to($this->actionUrl($request, $project, 'setup'))
            ->with('success', 'Phase setup saved. The structure is not locked yet.');
    }

    /**
     * Lock the structure and turn the monitoring interface on.
     */
    public function finalize(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeSetup($request, $project);

        try {
            $this->setup->finalize($project, $request->user(), ...$this->submittedStructure($request));
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->to($this->projectUrl($request, $project))
            ->with('success', sprintf(
                'Phases finalized. This project is now monitored across %d %s.',
                (int) $project->fresh()->phase_count,
                (int) $project->fresh()->phase_count === 1 ? 'phase' : 'phases'
            ));
    }

    /**
     * Unlock a finalized structure. Super Admin only.
     */
    public function override(Request $request, Project $project): RedirectResponse
    {
        if (! $this->rules->canOverrideStructure($request->user(), $project)) {
            throw new AccessDeniedHttpException('Only a Super Admin can change a finalized phase structure.');
        }

        // Nothing is asked for. The dialog states what unlocking does in one
        // sentence and the Super Admin confirms it; who did it and when is
        // recorded on the project and in the activity log either way.
        try {
            $this->setup->override($project, $request->user());
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->to($this->actionUrl($request, $project, 'setup'))
            ->with('success', 'Phase structure unlocked. Make your changes, then finalize it again.');
    }

    /**
     * Close out the project's current phase.
     */
    public function complete(Request $request, Project $project, ProjectPhase $phase): RedirectResponse
    {
        if ($phase->project_id !== $project->project_id) {
            abort(404);
        }

        if (! $this->rules->canCompletePhase($request->user(), $project)) {
            throw new AccessDeniedHttpException('You cannot complete phases on this project.');
        }

        // Sent by the Complete Anyway dialog, which confirms in a sentence and
        // asks for nothing else. Without it the ordinary rules apply, so a
        // Super Admin pressing the ordinary button is refused an unfinished
        // phase exactly as anybody else is.
        $override = $request->boolean('override');

        if ($override && ! $this->rules->canOverridePhaseCompletion($request->user(), $project)) {
            throw new AccessDeniedHttpException('Only a Super Admin can complete a phase with work outstanding.');
        }

        try {
            $this->progress->complete($phase, $request->user(), $override);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->to($this->projectUrl($request, $project).$this->phasesAnchor())
            ->with('success', sprintf('%s completed.', $phase->label()));
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The submitted rows and the reassignments that go with them, validated.
     *
     * @return array{0: array<int, array{phase_id: int|null, title: string, description: string}>, 1: array<int, int>}
     *
     * @throws ValidationException
     */
    private function submittedStructure(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'phases' => ['required', 'array', 'min:'.ProjectPhase::MIN_PHASES, 'max:'.ProjectPhase::MAX_PHASES],
            'phases.*.phase_id' => ['nullable', 'integer'],
            'phases.*.title' => ['required', 'string', 'max:150'],
            'phases.*.description' => ['required', 'string', 'max:500'],
            // Keyed by the phase being removed, holding the phase its tasks
            // move to. Absent on every ordinary save.
            'reassign' => ['nullable', 'array'],
            'reassign.*' => ['nullable', 'integer'],
        ], [
            'phases.required' => 'Add at least one phase before saving.',
            'phases.min' => 'Add at least one phase before saving.',
            'phases.*.title.required' => 'Every phase needs a title.',
            'phases.*.description.required' => 'Every phase needs a short description.',
        ]);

        $validated = $validator->validate();

        $rows = collect($validated['phases'])
            ->map(fn (array $row): array => [
                'phase_id' => isset($row['phase_id']) && $row['phase_id'] !== null
                    ? (int) $row['phase_id']
                    : null,
                'title' => (string) $row['title'],
                'description' => (string) $row['description'],
            ])
            ->values()
            ->all();

        $reassignments = collect($validated['reassign'] ?? [])
            ->filter()
            ->mapWithKeys(fn ($target, $phaseId): array => [(int) $phaseId => (int) $target])
            ->all();

        return [$rows, $reassignments];
    }

    private function authorizeSetup(Request $request, Project $project): void
    {
        if (! $this->rules->canSetUp($request->user(), $project)) {
            throw new AccessDeniedHttpException('You cannot set up phases for this project.');
        }
    }

    /**
     * Which portal this request came through.
     *
     * Read from the route name rather than from the role, because the two are
     * not the same question: a Lead Technician only ever reaches the
     * technician routes, but deciding by role would send an Admin who somehow
     * arrived on a technician route back to a page they cannot open.
     */
    private function inAdminPortal(Request $request): bool
    {
        return str_starts_with((string) $request->route()?->getName(), 'super-admin.');
    }

    /**
     * Where the phases live on the page being returned to.
     *
     * Both portals now split the project into Project Information and Project
     * Progress, and the phases sit in the second - so the fragment names that
     * pane rather than the panel inside it, and tabFromHash.js opens it.
     *
     * A method rather than a literal at the call site because it is a fact
     * about two templates, and the next person to rename a pane should find
     * one place to change.
     */
    private function phasesAnchor(): string
    {
        return '#project-progress';
    }

    private function projectUrl(Request $request, Project $project): string
    {
        return $this->inAdminPortal($request)
            ? route('super-admin.projects.show', $project->project_id)
            : route('technician.projects.show', $project->project_id);
    }

    private function actionUrl(Request $request, Project $project, string $action): string
    {
        $prefix = $this->inAdminPortal($request) ? 'super-admin' : 'technician';

        return route(sprintf('%s.projects.phases.%s', $prefix, $action), $project->project_id);
    }

    /**
     * The navigation the setup screen is wrapped in, so one view serves both
     * portals.
     */
    private function layoutFor(?User $user): string
    {
        return $user !== null && in_array($user->role, [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN], true)
            ? 'layouts.superadminNav'
            : 'layouts.portalNav';
    }
}
