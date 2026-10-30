<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\DetailForm;
use App\Models\ProjectType;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\DetailFormCatalog;
use App\Services\ProjectTypeCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Configuration -> System Settings -> Project Types: the list of work the
 * company does.
 *
 * Every write goes through ProjectTypeCatalog, which is what keeps the
 * technician specialty list in step - add a project type here and it becomes a
 * specialty a technician can hold.
 *
 * Super Admin only, checked here rather than on the route group for the same
 * reason System Contents does it: the Configuration page is shared with Admin,
 * and this tab is not theirs.
 */
class ProjectTypeController extends Controller
{
    public function __construct(
        private readonly ProjectTypeCatalog $catalog,
        private readonly DetailFormCatalog $forms,
        private readonly ActivityLogger $activityLogger
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return response()->json(['types' => $this->catalog->all()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $validator = $this->validateName($request);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $formValidator = $this->validateForm($request);

        if ($formValidator->fails()) {
            return response()->json(['error' => $formValidator->errors()->first()], 422);
        }

        // Every type has a form - it is what the wizard asks for in place of
        // a free-text description - so the two are written together.
        try {
            $type = DB::transaction(fn (): ProjectType => $this->forms->assign(
                $this->catalog->add((string) $request->input('type_name')),
                DetailForm::query()->findOrFail($request->integer('form_id'))
            ));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::PROJECT_TYPE_CREATED,
            null,
            sprintf(
                "Added '%s' as a project type, and as a technician specialty.",
                $type->type_name
            )
        );

        return response()->json([
            'message' => sprintf("'%s' added as a project type and specialty.", $type->type_name),
            'types' => $this->catalog->all()->all(),
        ]);
    }

    public function update(Request $request, ProjectType $projectType): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $validator = $this->validateName($request);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $previousName = $projectType->type_name;

        try {
            $type = $this->catalog->rename($projectType, (string) $request->input('type_name'));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::PROJECT_TYPE_UPDATED,
            null,
            sprintf("Renamed the project type '%s' to '%s'.", $previousName, $type->type_name)
        );

        return response()->json([
            'message' => sprintf("'%s' was renamed to '%s'.", $previousName, $type->type_name),
            'types' => $this->catalog->all()->all(),
        ]);
    }

    /**
     * Point a type at another detail form. Never at none: every type has one.
     */
    public function updateForm(Request $request, ProjectType $projectType): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $validator = $this->validateForm($request);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $form = DetailForm::query()->findOrFail($request->integer('form_id'));

        $this->forms->assign($projectType, $form);

        $this->activityLogger->record(
            ActivityLog::PROJECT_TYPE_FORM_CHANGED,
            null,
            sprintf("Set the form of '%s' to '%s'.", $projectType->type_name, $form->name)
        );

        return response()->json([
            'message' => sprintf("'%s' now uses %s.", $projectType->type_name, $form->name),
            'types' => $this->catalog->all()->all(),
        ]);
    }

    public function destroy(Request $request, ProjectType $projectType): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $name = $projectType->type_name;

        try {
            $this->catalog->remove($projectType);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::PROJECT_TYPE_DELETED,
            null,
            sprintf("Removed the project type '%s', and the matching specialty.", $name)
        );

        return response()->json([
            'message' => sprintf("'%s' was removed.", $name),
            'types' => $this->catalog->all()->all(),
        ]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Hand-rolled for the same reason as the rest of Configuration: the app
     * only renders exceptions as JSON for api/* paths, so a thrown
     * ValidationException here would answer with an HTML redirect.
     */
    private function validateName(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'type_name' => ['required', 'string', 'max:255'],
        ], [
            'type_name.required' => 'Enter a name for the project type.',
            'type_name.max' => 'A project type name may be 255 characters at most.',
        ]);
    }

    private function validateForm(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'form_id' => ['required', 'integer', 'exists:tbl_detail_forms,form_id'],
        ], [
            'form_id.required' => 'Pick a form.',
            'form_id.*' => 'That form no longer exists.',
        ]);
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === User::ROLE_SUPER_ADMIN, 403);
    }
}
