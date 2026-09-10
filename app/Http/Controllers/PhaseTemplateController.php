<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\PhaseStage;
use App\Models\ProjectType;
use App\Models\ProjectTypeStageTask;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\PhaseTemplateCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Configuration -> System Settings -> Project Settings: the default phases each
 * kind of job goes through, and the work each one starts with.
 *
 * Two levels, because a project can be more than one type at once and the two
 * levels are what makes that answerable:
 *
 *   - The STAGES are shared. One "Installation" that every type refers to, in
 *     one agreed order, so a project that is two types gets one Installation
 *     phase rather than two phases with the same name in an arbitrary order.
 *   - The TASKS are per type. What Aircon Installation does during Installation
 *     is not what Electrical Works does, and a project that is both should
 *     start with both lists under the one heading.
 *
 * Super Admin only, checked here rather than on the route group for the same
 * reason Project Types does it: the Configuration page is shared with Admin,
 * and this tab is not theirs.
 */
class PhaseTemplateController extends Controller
{
    public function __construct(
        private readonly PhaseTemplateCatalog $catalog,
        private readonly ActivityLogger $activityLogger
    ) {}

    /**
     * The vocabulary and every type's standing, in one read.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return response()->json([
            'stages' => $this->catalog->vocabulary()->all(),
            'types' => $this->catalog->typeSummaries()->all(),
            'max_tasks_per_stage' => ProjectTypeStageTask::MAX_PER_STAGE,
        ]);
    }

    // ------------------------------------------------------------------
    // The vocabulary of stages
    // ------------------------------------------------------------------

    public function storeStage(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $validator = $this->validateStage($request);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        try {
            $stage = $this->catalog->addStage(
                (string) $request->input('name'),
                (string) $request->input('default_description')
            );
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::PHASE_STAGE_CREATED,
            null,
            sprintf("Added '%s' as a phase stage.", $stage->name)
        );

        return $this->refreshed(sprintf("'%s' added as a phase stage.", $stage->name));
    }

    public function updateStage(Request $request, PhaseStage $phaseStage): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $validator = $this->validateStage($request);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $previousName = $phaseStage->name;

        try {
            $stage = $this->catalog->renameStage(
                $phaseStage,
                (string) $request->input('name'),
                (string) $request->input('default_description')
            );
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::PHASE_STAGE_UPDATED,
            null,
            $previousName === $stage->name
                ? sprintf("Updated the phase stage '%s'.", $stage->name)
                : sprintf("Renamed the phase stage '%s' to '%s'.", $previousName, $stage->name)
        );

        return $this->refreshed(sprintf("'%s' was updated.", $stage->name));
    }

    public function destroyStage(Request $request, PhaseStage $phaseStage): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $name = $phaseStage->name;

        try {
            $this->catalog->removeStage($phaseStage);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::PHASE_STAGE_DELETED,
            null,
            sprintf("Removed the phase stage '%s'.", $name)
        );

        return $this->refreshed(sprintf("'%s' was removed.", $name));
    }

    /**
     * The order every merged structure comes out in.
     */
    public function reorderStages(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $validator = Validator::make($request->all(), [
            'stage_ids' => ['required', 'array', 'min:1'],
            'stage_ids.*' => ['required', 'integer'],
        ], [
            'stage_ids.required' => 'Send the stages in the order they should be in.',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        try {
            $this->catalog->reorderStages($validator->validated()['stage_ids']);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::PHASE_STAGES_REORDERED,
            null,
            'Reordered the phase stages. New projects are offered their phases in this order.'
        );

        return $this->refreshed('Stage order saved.');
    }

    // ------------------------------------------------------------------
    // One project type's template
    // ------------------------------------------------------------------

    public function showTemplate(Request $request, ProjectType $projectType): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return response()->json([
            'type' => [
                'type_id' => $projectType->type_id,
                'type_name' => $projectType->type_name,
            ],
            'stages' => $this->catalog->templateFor($projectType),
            'max_tasks_per_stage' => ProjectTypeStageTask::MAX_PER_STAGE,
        ]);
    }

    public function updateTemplate(Request $request, ProjectType $projectType): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $validator = Validator::make($request->all(), [
            // An empty template is legitimate and is how a type is put back to
            // contributing nothing, so this is present-but-possibly-empty
            // rather than required.
            'stages' => ['present', 'array'],
            'stages.*.stage_id' => ['required', 'integer'],
            'stages.*.tasks' => ['present', 'array'],
            'stages.*.tasks.*.title' => ['required', 'string', 'max:255'],
            'stages.*.tasks.*.description' => ['required', 'string', 'max:2000'],
        ], [
            'stages.*.tasks.*.title.required' => 'Every default task needs a title.',
            'stages.*.tasks.*.description.required' => 'Every default task needs a description.',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        try {
            $this->catalog->saveTemplate($projectType, $validator->validated()['stages']);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $projectType->refresh()->loadCount(['stages', 'stageTasks']);

        // Says plainly what a template change does and does not do, because the
        // question anybody reading this log entry will have is whether it moved
        // a project that was already running.
        $this->activityLogger->record(
            ActivityLog::PHASE_TEMPLATE_UPDATED,
            null,
            sprintf(
                "Updated the default phases for '%s': %d %s, %d default %s. Projects already set up are unaffected.",
                $projectType->type_name,
                $projectType->stages_count,
                $projectType->stages_count === 1 ? 'stage' : 'stages',
                $projectType->stage_tasks_count,
                $projectType->stage_tasks_count === 1 ? 'task' : 'tasks'
            )
        );

        return response()->json([
            'message' => sprintf("Default phases for '%s' saved.", $projectType->type_name),
            'stages' => $this->catalog->templateFor($projectType),
            'types' => $this->catalog->typeSummaries()->all(),
        ]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Every write answers with the whole catalogue, so the editor never has to
     * work out what a change did to the counts beside it.
     */
    private function refreshed(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'stages' => $this->catalog->vocabulary()->all(),
            'types' => $this->catalog->typeSummaries()->all(),
        ]);
    }

    /**
     * Hand-rolled for the same reason as the rest of Configuration: the app
     * only renders exceptions as JSON for api/* paths, so a thrown
     * ValidationException here would answer with an HTML redirect.
     */
    private function validateStage(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:150'],
            'default_description' => ['required', 'string', 'max:500'],
        ], [
            'name.required' => 'Enter a name for the stage.',
            'name.max' => 'A stage name may be 150 characters at most.',
            'default_description.required' => 'Enter a short description for the stage.',
            'default_description.max' => 'A stage description may be 500 characters at most.',
        ]);
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === User::ROLE_SUPER_ADMIN, 403);
    }
}
