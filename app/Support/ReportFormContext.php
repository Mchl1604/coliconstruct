<?php

namespace App\Support;

use App\Models\Project;
use Illuminate\Http\Request;

/**
 * Where a report form was opened from, and so where it leads back to.
 *
 * The same two forms serve a lead and a Super Admin, each from a project's page
 * or from their Reports page. Opened from a project - `from=project` - the form
 * asks for no project and goes back to it.
 */
class ReportFormContext
{
    public static function portal(Request $request): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'super-admin.') ? 'super-admin' : 'technician';
    }

    public static function cameFromProject(Request $request): bool
    {
        return $request->input('from') === 'project';
    }

    /**
     * The project's Reports tab when the form was opened from it, the Reports
     * page otherwise.
     */
    public static function backUrl(Request $request, ?Project $project): string
    {
        $portal = self::portal($request);

        if ($project !== null && self::cameFromProject($request)) {
            return route($portal.'.projects.show', $project->project_id).'#reports';
        }

        return route($portal === 'super-admin' ? 'super-admin.reports.index' : 'technician.reports');
    }
}
