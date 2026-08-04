<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\SystemContent;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\SystemContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Configuration -> System Contents: the editor behind every word and picture
 * on the public website.
 *
 * Super Admin only, enforced here rather than by the route group: the whole
 * Configuration page is shared with Admin, and this one tab is not theirs.
 * An Admin never sees the tab, and never gets past this check either.
 */
class SystemContentController extends Controller
{
    public function __construct(
        private readonly SystemContentService $content,
        private readonly ActivityLogger $activityLogger
    ) {}

    /**
     * A section's fields with their current values, for the editor.
     */
    public function show(Request $request, string $section): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        abort_unless(isset(SystemContent::SECTIONS[$section]), 404);

        return response()->json([
            'section' => $section,
            'label' => SystemContent::SECTIONS[$section],
            'fields' => $this->fieldsFor($section),
        ]);
    }

    /**
     * Save a section's text fields in one go.
     */
    public function update(Request $request, string $section): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        abort_unless(isset(SystemContent::SECTIONS[$section]), 404);

        $definitions = SystemContent::definitionsFor($section);

        $rules = [];

        foreach ($definitions as $key => $definition) {
            if (in_array($definition['type'], SystemContent::FILE_TYPES, true)) {
                continue;
            }

            // Keyed by content key, which contains dots - escaped so the
            // validator reads them as one field rather than nested data.
            $rules['values.'.str_replace('.', '\.', $key)] = $definition['type'] === SystemContent::TYPE_TEXT
                ? ['nullable', 'string', 'max:255']
                : ['nullable', 'string', 'max:20000'];
        }

        // Hand-rolled for the same reason as the rest of Configuration: the
        // app only renders exceptions as JSON for api/* paths, so a thrown
        // ValidationException here would answer with an HTML redirect.
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $saved = $this->content->saveText(
            $section,
            (array) $request->input('values', []),
            $request->user()
        );

        $this->activityLogger->record(
            ActivityLog::SYSTEM_SETTINGS_UPDATED,
            null,
            sprintf('Updated the %s content of the public website.', SystemContent::SECTIONS[$section])
        );

        return response()->json([
            'message' => 'Website content updated.',
            'saved' => $saved,
            'fields' => $this->fieldsFor($section),
        ]);
    }

    /**
     * Upload or replace one image.
     */
    public function storeImage(Request $request, string $key): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        abort_unless(SystemContent::isImageKey($key), 404);

        $validator = Validator::make($request->all(), [
            'image' => [
                'required',
                'file',
                'mimes:'.implode(',', SystemContentService::ALLOWED_IMAGE_EXTENSIONS),
                'max:'.SystemContentService::MAX_IMAGE_KILOBYTES,
            ],
        ], [
            'image.mimes' => 'Images must be one of: '
                .implode(', ', SystemContentService::ALLOWED_IMAGE_EXTENSIONS).'.',
            'image.max' => 'Images must be 4 MB or smaller.',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        try {
            $this->content->saveImage($key, $request->file('image'), $request->user());
        } catch (Throwable $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::SYSTEM_SETTINGS_UPDATED,
            null,
            sprintf("Updated the '%s' image on the public website.", $this->labelFor($key))
        );

        return response()->json([
            'message' => 'Image updated.',
            'url' => $this->content->image($key),
        ]);
    }

    public function destroyImage(Request $request, string $key): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        abort_unless(SystemContent::isImageKey($key), 404);

        try {
            $this->content->removeImage($key, $request->user());
        } catch (Throwable $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $this->activityLogger->record(
            ActivityLog::SYSTEM_SETTINGS_UPDATED,
            null,
            sprintf("Removed the '%s' image from the public website.", $this->labelFor($key))
        );

        return response()->json(['message' => 'Image removed.']);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Public website content is the Super Admin's alone; an Admin may run the
     * business but not rewrite the shopfront.
     */
    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === User::ROLE_SUPER_ADMIN, 403);
    }

    private function labelFor(string $key): string
    {
        return SystemContent::DEFINITIONS[$key]['label'] ?? $key;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fieldsFor(string $section): array
    {
        $stored = $this->content->all();

        return collect(SystemContent::definitionsFor($section))
            ->map(function (array $definition, string $key) use ($stored): array {
                $isImage = in_array($definition['type'], SystemContent::FILE_TYPES, true);

                return [
                    'key' => $key,
                    'label' => $definition['label'],
                    'type' => $definition['type'],
                    'help' => $definition['help'] ?? null,
                    // The raw stored value for text fields; images report the
                    // URL instead, since the path means nothing to the editor.
                    'value' => $isImage ? null : ($stored[$key] ?? $definition['default'] ?? ''),
                    'url' => $isImage ? $this->content->image($key) : null,
                    'is_default' => ! $isImage && ! array_key_exists($key, $stored),
                ];
            })
            ->values()
            ->all();
    }
}
