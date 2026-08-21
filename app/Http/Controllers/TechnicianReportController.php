<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TechnicianReport;
use App\Models\TechnicianReportImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TechnicianReportController extends Controller
{
    /**
     * Statuses that may still receive a report. Mirrors
     * ReportController::REPORTABLE_STATUSES.
     *
     * @var array<int, string>
     */
    private const REPORTABLE_STATUSES = ['unscheduled', 'pending', 'ongoing'];

    public function store(Request $request, $id)
    {
        $request->validate([
            'report_type' => 'required|in:progress,incident',
            'report_title' => 'required|string|max:255',
            'report_description' => 'required|string',
            'images.*' => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
            // Supplied by the Reports page, which picks the project first and
            // can therefore say who filed the report. Absent from the Project
            // Details form, which keeps its original behaviour below.
            'technician_id' => 'nullable|integer|exists:tbl_technicians,technician_id',
        ]);

        $project = Project::find($id);

        if (! $project) {
            return back()->with('error', 'That project no longer exists.');
        }

        // A finished, cancelled or archived project is a closed record.
        if (! in_array($project->status, self::REPORTABLE_STATUSES, true) || $project->isArchived()) {
            return back()->with(
                'error',
                sprintf('%s is %s and can no longer receive reports.', $project->name, $project->statusLabel())
            );
        }

        // Paused work takes no reports. A hold keeps its status at
        // Unscheduled, which is a reportable one, so this has to be asked
        // separately - a report describes a visit, and nobody is visiting a
        // project that has been paused.
        if ($project->on_hold) {
            return back()->with(
                'error',
                sprintf('%s is on hold. Resume it before filing a report.', $project->name)
            );
        }

        DB::beginTransaction();

        try {

            $report = TechnicianReport::create([
                'project_id' => $project->project_id,
                'technician_id' => $request->input('technician_id')
                    ?? $this->defaultTechnicianId($project),
                // Who actually filed it. An administrator has no technician
                // record, so without this the report would be credited to the
                // project's lead - somebody who had nothing to do with it.
                'submitted_by' => $request->user()?->id,
                'report_type' => $request->report_type,
                'report_title' => $request->report_title,
                'report_description' => $request->report_description,
                'report_date' => now()->toDateString(),
            ]);

            if ($request->hasFile('images')) {

                foreach ($request->file('images') as $image) {

                    $path = $image->store('technician-reports', 'public');

                    TechnicianReportImage::create([
                        'technician_report_id' => $report->id,
                        'image_path' => $path,
                    ]);
                }
            }

            DB::commit();

            return back()->with('success', 'Report submitted successfully.');

        } catch (\Exception $e) {

            DB::rollBack();

            return back()->with('error', $this->safeErrorMessage($e, 'Unable to save report. Nothing was changed.'));
        }
    }

    /**
     * Who a report is attributed to when the form didn't say.
     *
     * Falls back to the project's lead technician, then any assigned
     * technician, so the "Submitted By" column means something. Only reaches
     * technician 1 - the original hard-coded value - when a project has no
     * team at all.
     */
    private function defaultTechnicianId(Project $project): int
    {
        $project->loadMissing('projectTechnicians.technician.account');

        $lead = $project->projectTechnicians
            ->first(fn ($assignment) => optional($assignment->technician?->account)->role === 'lead_technician');

        return (int) (
            $lead->technician_id
            ?? $project->projectTechnicians->first()?->technician_id
            ?? 1
        );
    }
}
