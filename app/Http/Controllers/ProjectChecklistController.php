<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectDetail;
use App\Services\ActivityLogger;
use App\Services\ServiceReport;
use App\Support\ServiceReportForms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Project Activity -> Checklist: a lead or the Super Admin ticks a unit's
 * indoor and outdoor items as the work is done. Each tick saves on its own, and
 * shows on the printed Service Report.
 */
class ProjectChecklistController extends Controller
{
    public function __construct(
        private readonly ServiceReport $serviceReport,
        private readonly ActivityLogger $activityLogger
    ) {}

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorize('updateChecklist', $project);

        // Hand-rolled: the app only renders exceptions as JSON for api/* paths.
        $validator = Validator::make($request->all(), [
            'detail_id' => ['required', 'integer'],
            'item' => ['required', 'string', Rule::in(array_keys(ServiceReportForms::checklistItems()))],
            'done' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $validated = $validator->validated();
        $typeIds = $this->serviceReport->checklistTypes($project)->pluck('type_id');

        $done = DB::transaction(function () use ($project, $typeIds, $validated): array {
            $detail = ProjectDetail::query()
                ->where('project_id', $project->project_id)
                ->whereIn('type_id', $typeIds)
                ->whereKey($validated['detail_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $done = array_values(array_diff((array) $detail->checklist, [$validated['item']]));

            if ($validated['done']) {
                $done[] = $validated['item'];
            }

            $detail->update(['checklist' => $done]);

            return $done;
        });

        $this->activityLogger->record(
            ActivityLog::PROJECT_CHECKLIST_UPDATED,
            null,
            sprintf(
                "%s '%s' on the checklist of project '%s'.",
                $validated['done'] ? 'Ticked' : 'Unticked',
                ServiceReportForms::checklistItems()[$validated['item']],
                $project->reference_no
            ),
            $project
        );

        return response()->json(['done_count' => count($done)]);
    }
}
