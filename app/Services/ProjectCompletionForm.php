<?php

namespace App\Services;

use App\Http\Controllers\ProjectDetailController;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Complete Project, as both portals post it: the Service Report form with its
 * signatories required and Remarks optional. Saved in one transaction with the
 * completion itself, and the bottom Remarks are the summary the client is sent.
 */
class ProjectCompletionForm
{
    public function __construct(
        private readonly ProjectDetailForms $detailForms,
        private readonly ProjectCompletion $completion,
    ) {}

    /**
     * Refused back to the form on its own error bag, which reopens it.
     *
     * @return array<string, mixed>
     */
    public function validate(Request $request, Project $project): array
    {
        $types = $project->projectTypes;

        return Validator::make(
            $request->all(),
            [
                ...$this->detailForms->rules($types),
                'report_performed_by' => ['required', 'string', 'max:150'],
                'report_witnessed_by' => ['required', 'string', 'max:150'],
                'report_remarks' => ['nullable', 'string', 'max:2000'],
            ],
            [
                ...$this->detailForms->messages($types),
                'report_performed_by.required' => 'Enter Performed By.',
                'report_witnessed_by.required' => 'Enter Witnessed and Accepted By.',
            ],
            $this->detailForms->attributes($types)
        )->validateWithBag(ProjectDetailController::ERROR_BAG);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function complete(Project $project, array $validated, User $by): void
    {
        DB::transaction(function () use ($project, $validated, $by): void {
            $this->detailForms->sync($project, $project->projectTypes, $validated['details'] ?? []);

            $project->update([
                'report_performed_by' => $validated['report_performed_by'],
                'report_witnessed_by' => $validated['report_witnessed_by'],
                'report_remarks' => $validated['report_remarks'] ?? null,
            ]);

            $this->completion->requestCompletion(
                $project,
                ['completion_summary' => $validated['report_remarks'] ?? null],
                null,
                $by
            );
        });
    }
}
