<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\DetailForm;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\DetailFormCatalog;
use App\Support\DetailFieldScope;
use App\Support\DetailFieldType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Configuration -> System Settings -> Project Types -> Detail Forms: the forms
 * a project type asks for in the create wizard's Units step.
 *
 * Every write goes through DetailFormCatalog. Super Admin only, checked here
 * for the same reason Project Types does it: the Configuration page is shared
 * with Admin, and this tab is not theirs.
 */
class DetailFormController extends Controller
{
    /** The most fields one form may hold. */
    public const MAX_FIELDS = 40;

    public function __construct(
        private readonly DetailFormCatalog $catalog,
        private readonly ActivityLogger $activityLogger
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return response()->json(['forms' => $this->catalog->all()->all()]);
    }

    public function show(Request $request, DetailForm $detailForm): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return response()->json(['form' => $this->catalog->forBuilder($detailForm)]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->save($request, null);
    }

    public function update(Request $request, DetailForm $detailForm): JsonResponse
    {
        return $this->save($request, $detailForm);
    }

    public function destroy(Request $request, DetailForm $detailForm): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $name = $detailForm->name;

        try {
            $this->catalog->remove($detailForm);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::DETAIL_FORM_DELETED,
            null,
            sprintf("Removed the detail form '%s'.", $name)
        );

        return response()->json([
            'message' => sprintf("'%s' was removed.", $name),
            'forms' => $this->catalog->all()->all(),
        ]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function save(Request $request, ?DetailForm $form): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $validator = $this->validator($request);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        try {
            $saved = $this->catalog->save($form, $validator->validated());
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            $form ? ActivityLog::DETAIL_FORM_UPDATED : ActivityLog::DETAIL_FORM_CREATED,
            null,
            sprintf($form ? "Updated the detail form '%s'." : "Created the detail form '%s'.", $saved->name)
        );

        return response()->json([
            'message' => sprintf($form ? "'%s' saved." : "'%s' created.", $saved->name),
            'form' => $this->catalog->forBuilder($saved),
            'forms' => $this->catalog->all()->all(),
        ]);
    }

    /**
     * Hand-rolled for the same reason as the rest of Configuration: the app
     * only renders exceptions as JSON for api/* paths.
     */
    private function validator(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'fields' => ['required', 'array', 'min:1', 'max:'.self::MAX_FIELDS],
            'fields.*.field_id' => ['nullable', 'integer'],
            'fields.*.label' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'fields.*.input_type' => ['required', Rule::enum(DetailFieldType::class)],
            'fields.*.scope' => ['required', Rule::enum(DetailFieldScope::class)],
            'fields.*.options' => ['nullable', 'array', 'max:50'],
            'fields.*.options.*' => ['nullable', 'string', 'max:100'],
            'fields.*.is_required' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'Enter a form name.',
            'name.max' => 'A form name may be 100 characters at most.',
            'fields.required' => 'Add at least one field.',
            'fields.min' => 'Add at least one field.',
            'fields.max' => 'A form takes '.self::MAX_FIELDS.' fields at most.',
            'fields.*.label.required' => 'Every field needs a label.',
            'fields.*.label.distinct' => 'Two fields have the same label.',
            'fields.*.label.max' => 'A field label may be 100 characters at most.',
            'fields.*.input_type.*' => 'Pick a field type.',
            'fields.*.scope.*' => 'Pick Project or Per unit.',
            'fields.*.options.max' => 'A field takes 50 options at most.',
        ])->after(function (\Illuminate\Validation\Validator $validator) use ($request): void {
            // A dropdown or checkbox field needs at least one real option.
            foreach ((array) $request->input('fields', []) as $field) {
                $type = DetailFieldType::tryFrom((string) ($field['input_type'] ?? ''));

                if ($type?->hasOptions() && collect((array) ($field['options'] ?? []))->filter(fn ($option): bool => is_string($option) && trim($option) !== '')->isEmpty()) {
                    $validator->errors()->add('fields', 'Add options for each dropdown or checkbox field.');

                    return;
                }
            }
        });
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === User::ROLE_SUPER_ADMIN, 403);
    }
}
