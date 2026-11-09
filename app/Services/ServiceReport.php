<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectDetail;
use App\Models\ProjectType;
use App\Support\BusinessTime;
use App\Support\DetailFieldScope;
use App\Support\ServiceReportForms;
use Illuminate\Support\Collection;

/**
 * A project's Service Report as the printed sheet reads it: the header from
 * the project and its client, then the section of each type the project has.
 * Only those sections appear.
 */
class ServiceReport
{
    /**
     * The project's types that are written up on the report.
     *
     * @return Collection<int, ProjectType>
     */
    public function reportTypes(Project $project): Collection
    {
        $project->loadMissing('projectTypes');

        return $project->projectTypes
            ->filter(fn (ProjectType $type): bool => $this->isReportType($type))
            ->values();
    }

    public function isReportType(ProjectType $type): bool
    {
        return isset(ServiceReportForms::SECTION_BY_TYPE[$type->type_name]);
    }

    /**
     * The project's types that carry the indoor and outdoor checklists.
     *
     * @return Collection<int, ProjectType>
     */
    public function checklistTypes(Project $project): Collection
    {
        return $this->reportTypes($project)
            ->filter(fn (ProjectType $type): bool => in_array($type->type_name, ServiceReportForms::CHECKLIST_TYPES, true))
            ->values();
    }

    /**
     * Each checklist type with its units, as the Checklist tab shows them.
     *
     * @return array<int, array{type_name: string, units: array<int, array{detail_id: int, title: string, done: array<int, string>}>}>
     */
    public function checklists(Project $project): array
    {
        $project->loadMissing('details');

        return $this->checklistTypes($project)
            ->map(fn (ProjectType $type): array => [
                'type_name' => $type->type_name,
                'units' => $project->details
                    ->where('type_id', $type->type_id)
                    ->filter(fn (ProjectDetail $detail): bool => $detail->scope === DetailFieldScope::Unit)
                    ->sortBy('sort_order')
                    ->values()
                    ->map(function (ProjectDetail $detail, int $index): array {
                        $area = ((array) $detail->values)['area_served'] ?? null;

                        return [
                            'detail_id' => (int) $detail->detail_id,
                            'title' => 'Unit '.($index + 1).(filled($area) ? ' - '.$area : ''),
                            'done' => array_values((array) $detail->checklist),
                        ];
                    })
                    ->all(),
            ])
            ->all();
    }

    /**
     * @return array{header: array<string, string>, sections: array<string, array{project: array<string, mixed>, units: array<int, array<string, mixed>>}>}
     */
    public function for(Project $project): array
    {
        $project->loadMissing(['client', 'details', 'projectTypes']);

        $sections = [];

        foreach ($this->reportTypes($project) as $type) {
            $rows = $project->details->where('type_id', $type->type_id);
            $projectValues = (array) ($rows->first(fn (ProjectDetail $detail): bool => $detail->scope === DetailFieldScope::Project)?->values ?? []);
            $units = $rows
                ->filter(fn (ProjectDetail $detail): bool => $detail->scope === DetailFieldScope::Unit)
                ->sortBy('sort_order')
                // A value saved once per project before the form moved it onto
                // each unit still reads on the unit.
                ->map(fn (ProjectDetail $detail): array => ['_checklist' => (array) $detail->checklist] + (array) $detail->values + $projectValues)
                ->values()
                ->all();

            $sections[ServiceReportForms::SECTION_BY_TYPE[$type->type_name]] = [
                'project' => $projectValues,
                'units' => $units ?: [$projectValues],
            ];
        }

        return [
            'header' => $this->header($project),
            'sections' => $sections,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function header(Project $project): array
    {
        $client = $project->client;

        return [
            'client_name' => (string) ($client?->primaryName() ?? ''),
            'reference_no' => (string) $project->reference_no,
            'address' => (string) $project->address,
            'contact_person' => (string) ($client?->fullname ?? ''),
            'contact_no' => (string) ($client?->contact_number ?? ''),
            // Blank until the project is completed, for writing in on site.
            'date' => BusinessTime::format($project->completed_at, 'M j, Y', ''),
        ];
    }
}
