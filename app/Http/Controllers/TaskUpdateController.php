<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\TaskProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Posting today's progress on a task, from the technician portal - by the
 * technician holding it, or by the project's lead on their behalf. Who may is
 * TaskProgress's decision, asked again here whatever the page showed.
 */
class TaskUpdateController extends Controller
{
    public function __construct(private readonly TaskProgress $progress) {}

    public function store(Request $request, Task $task): JsonResponse|RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'notes' => ['required', 'string', 'max:2000'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ], [
            'notes.required' => 'Say what was done today.',
            'notes.max' => 'Notes are too long.',
            'images.max' => 'At most 5 photos.',
            'images.*.image' => 'Photos must be JPG or PNG.',
            'images.*.mimes' => 'Photos must be JPG or PNG.',
            'images.*.max' => 'Each photo must be under 5 MB.',
        ]);

        if ($validator->fails()) {
            return $this->answer($request, $validator->errors()->first(), false);
        }

        try {
            $this->progress->post($task, $request->user(), (string) $request->input('notes'), $request->file('images') ?? []);
        } catch (RuntimeException $exception) {
            return $this->answer($request, $exception->getMessage(), false);
        }

        return $this->answer($request, 'Update posted.', true);
    }

    private function answer(Request $request, string $message, bool $ok): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json($ok ? ['message' => $message] : ['error' => $message], $ok ? 200 : 422);
        }

        return back()->with($ok ? 'success' : 'error', $message);
    }
}
