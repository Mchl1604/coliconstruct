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
 * Configuration -> System Settings: the editor behind every word and picture
 * on the public website, and behind the operational settings beneath it - the
 * completion confirmation window, the enquiry cooldown, the Terms and
 * Conditions.
 *
 * One controller for both because they are one mechanism: the same table, the
 * same catalogue, the same cached read, the same audit entry. What a section
 * holds decides how it is validated - see the `rules` a settings field carries
 * in SystemContent::DEFINITIONS - and nothing else about it differs.
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

        $label = SystemContent::sectionLabel($section);

        abort_if($label === null, 404);

        return response()->json([
            'section' => $section,
            'label' => $label,
            'fields' => $this->fieldsFor($section),
        ]);
    }

    /**
     * Save a section's text fields in one go.
     */
    public function update(Request $request, string $section): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $label = SystemContent::sectionLabel($section);

        abort_if($label === null, 404);

        [$rules, $messages] = $this->validationFor($section);

        // Hand-rolled for the same reason as the rest of Configuration: the
        // app only renders exceptions as JSON for api/* paths, so a thrown
        // ValidationException here would answer with an HTML redirect.
        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $saved = $this->content->saveText(
            $section,
            (array) $request->input('values', []),
            $request->user()
        );

        $isSettings = SystemContent::isSettingsSection($section);

        $this->activityLogger->record(
            ActivityLog::SYSTEM_SETTINGS_UPDATED,
            null,
            $isSettings
                ? sprintf('Updated the %s.', $label)
                : sprintf('Updated the %s content of the public website.', $label)
        );

        return response()->json([
            // Named on the way back as well as on the way in: the editor
            // re-renders from this payload, and it has to know which section it
            // is drawing to decide which of the extra panels belongs beside it.
            'section' => $section,
            'label' => $label,
            'message' => $isSettings ? 'Settings updated.' : 'Website content updated.',
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
     * The rules and the wording for one section's fields.
     *
     * Website copy is all the same shape - some text, not too long - and a
     * blank field there is a real answer: an empty hero badge hides the badge.
     * A setting is not like that. A confirmation window of nought days would
     * complete every waiting project on the next sweep and an empty set of
     * terms would leave registration asking people to accept nothing, so a
     * field that configures behaviour states its own rules in the catalogue
     * and they are used here in place of the generic pair.
     *
     * The messages are the definitions' own too, because "The values.
     * project_settings.auto_completion_days must be at least 1" is not a
     * sentence to show anybody.
     *
     * @return array{0: array<string, array<int, string>>, 1: array<string, string>}
     */
    private function validationFor(string $section): array
    {
        $rules = [];
        $messages = [];

        foreach (SystemContent::definitionsFor($section) as $key => $definition) {
            if (in_array($definition['type'], SystemContent::FILE_TYPES, true)) {
                continue;
            }

            // Keyed by content key, which contains dots - escaped so the
            // validator reads them as one field rather than nested data.
            $field = 'values.'.str_replace('.', '\.', $key);

            $rules[$field] = $definition['rules'] ?? ($definition['type'] === SystemContent::TYPE_TEXT
                ? ['nullable', 'string', 'max:255']
                : ['nullable', 'string', 'max:20000']);

            // Registered under both spellings. The rule is keyed with the dots
            // escaped, so the validator reads one field rather than nested
            // data - but by the time it looks a message up it has resolved the
            // attribute back to its plain form. Which of the two it asks for is
            // an internal detail; answering to both costs one array entry and
            // means a rule that gains a message does not silently keep printing
            // "The values.legal.terms and conditions field is required."
            foreach ($definition['messages'] ?? [] as $rule => $message) {
                $messages[$field.'.'.$rule] = $message;
                $messages['values.'.$key.'.'.$rule] = $message;
            }
        }

        return [$rules, $messages];
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
