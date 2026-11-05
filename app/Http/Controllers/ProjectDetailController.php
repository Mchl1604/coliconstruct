<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Services\ActivityLogger;
use App\Services\ProjectDetailForms;
use App\Services\ServiceReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Project Details -> Edit Description: editing what each type's detail form
 * was filled in with after the project was created.
 *
 * Checked and saved by ProjectDetailForms, exactly as the create wizard is.
 */
class ProjectDetailController extends Controller
{
    /** The error bag the Edit Description dialog reopens on. */
    public const ERROR_BAG = 'details';

    public function __construct(
        private readonly ProjectDetailForms $detailForms,
        private readonly ActivityLogger $activityLogger,
        private readonly ServiceReport $serviceReport
    ) {}

    public function update(Request $request, int $id): RedirectResponse
    {
        $project = Project::with('projectTypes')->findOrFail($id);

        if ($project->isReadOnly()) {
            return redirect()
                ->route('super-admin.projects.show', $id)
                ->with('error', 'This project can no longer be edited.');
        }

        $types = $project->projectTypes;

        $validated = Validator::make(
            $request->all(),
            [
                ...$this->detailForms->rules($types),
                'report_performed_by' => ['nullable', 'string', 'max:150'],
                'report_witnessed_by' => ['nullable', 'string', 'max:150'],
                'report_remarks' => ['nullable', 'string', 'max:2000'],
            ],
            $this->detailForms->messages($types),
            $this->detailForms->attributes($types)
        )->validateWithBag(self::ERROR_BAG);

        DB::transaction(function () use ($project, $types, $validated): void {
            $this->detailForms->sync($project, $types, $validated['details'] ?? []);

            // Only asked of a project with a Service Report.
            if ($this->serviceReport->reportTypes($project)->isNotEmpty()) {
                $project->update([
                    'report_performed_by' => $validated['report_performed_by'] ?? null,
                    'report_witnessed_by' => $validated['report_witnessed_by'] ?? null,
                    'report_remarks' => $validated['report_remarks'] ?? null,
                ]);
            }
        });

        $this->activityLogger->record(
            ActivityLog::PROJECT_DESCRIPTION_UPDATED,
            null,
            sprintf("Updated the description of project '%s'.", $project->reference_no),
            $project
        );

        return redirect()
            ->route('super-admin.projects.show', $id)
            ->with('success', 'Description saved.');
    }
}
