<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\ProjectType;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Technician;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    public function index()
    {
        $projects = Project::query()
            ->with(['clients', 'documents', 'schedule', 'projectTypes', 'projectTechnicians.technician'])
            ->orderBy('project_id', 'desc')
            ->get();

        return view('super-admin.projects', compact('projects'));
    }

    public function create()
    {
        $projectTypes = ProjectType::query()->orderBy('type_name', 'asc')->get();
        $technicians = Technician::query()
            ->with(['account', 'skills'])
            ->whereHas('account', function ($query): void {
                $query->whereIn('role', ['technician', 'lead_technician']);
            })
            ->orderBy('technician_id')
            ->get();

        $selectedProjectTypes = $this->defaultSelectedProjectTypes($projectTypes);
        $suggestedTechnicians = $this->suggestTechnicians($technicians, $selectedProjectTypes);
        $otherTechnicians = $technicians
            ->reject(function (Technician $technician) use ($suggestedTechnicians): bool {
                return $suggestedTechnicians->contains('technician_id', $technician->technician_id);
            })
            ->values();

        $technicianSchedules = $this->buildTechnicianSchedules();

        return view('super-admin.createProject', compact(
            'projectTypes',
            'technicians',
            'suggestedTechnicians',
            'otherTechnicians',
            'technicianSchedules'
        ));
    }

    public function store(StoreProjectRequest $request)
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $request): void {
            $project = Project::create([
                'name' => $this->resolveProjectName($validated),
                'status' => 'pending',
                'quotation' => $validated['quotation_amount'],
                'address' => $validated['project_address'],
                'description' => $validated['project_description'],
            ]);

            $project->forceFill([
                'reference_no' => $this->generateReferenceNumber($project->project_id),
            ])->save();

            Client::create([
                'project_id' => $project->project_id,
                'client_type' => $validated['client_type'],
                'surname' => $validated['surname'] ?? null,
                'firstname' => $validated['firstname'],
                'middlename' => $validated['middle_name'] ?? null,
                'lastname' => trim(collect([
                    $validated['firstname'],
                    $validated['middle_name'] ?? null,
                    $validated['surname'] ?? null,
                ])->filter()->implode(' ')),
                'email_address' => $validated['client_email'],
                'contact_number' => $validated['client_phone'],
            ]);

            $projectTypeIds = ProjectType::query()
                ->get()
                ->filter(function (ProjectType $projectType) use ($validated): bool {
                    return in_array($projectType->type_name, $validated['project_types'], true);
                })
                ->pluck('type_id')
                ->all();

            $project->projectTypes()->sync($projectTypeIds);

            $selectedTechnicianIds = collect([
                $validated['lead_tech'],
                ...$validated['technicians'],
            ])
                ->map(fn ($technicianId): int => (int) $technicianId)
                ->unique()
                ->values();

            $projectTechnicians = $selectedTechnicianIds->map(function (int $technicianId) use ($project): ProjectTechnician {
                return ProjectTechnician::create([
                    'project_id' => $project->project_id,
                    'technician_id' => $technicianId,
                ]);
            });

            $schedule = Schedule::create([
                'project_id' => $project->project_id,
                'start_datetime' => CarbonImmutable::parse($validated['start_date'])->startOfDay(),
                'end_datetime' => CarbonImmutable::parse($validated['end_date'])->endOfDay(),
                'status' => 'scheduled',
                'remarks' => 'Created from project wizard',
            ]);

            $projectTechnicians->each(function (ProjectTechnician $projectTechnician) use ($schedule): void {
                ScheduleTechnician::create([
                    'schedule_id' => $schedule->schedule_id,
                    'project_technician_id' => $projectTechnician->project_technician_id,
                ]);
            });

            $this->storeDocument($request->file('assessment_report'), $project->project_id, 'assessment');
            $this->storeDocument($request->file('approved_quotation'), $project->project_id, 'quotation');

            if ($validated['client_type'] !== 'Residential' && $request->hasFile('contract')) {
                $this->storeDocument($request->file('contract'), $project->project_id, 'contract');
            }
        });

        return redirect()->route('super-admin.projects');
    }

    private function defaultSelectedProjectTypes(Collection $projectTypes): array
    {
        if ($projectTypes->isEmpty()) {
            return ['Aircon Installation'];
        }

        return [$projectTypes->first()->type_name];
    }

    private function suggestTechnicians(Collection $technicians, array $selectedProjectTypes): Collection
    {
        if ($selectedProjectTypes === []) {
            return collect();
        }

        return $technicians
            ->map(function (Technician $technician) use ($selectedProjectTypes): Technician {
                $matchCount = $technician->skills
                    ->pluck('skill_name')
                    ->intersect($selectedProjectTypes)
                    ->count();

                $technician->setAttribute('match_count', $matchCount);

                return $technician;
            })
            ->filter(function (Technician $technician): bool {
                return (int) $technician->getAttribute('match_count') > 0;
            })
            ->sortByDesc('match_count')
            ->values();
    }

    private function buildTechnicianSchedules(): array
    {
        $schedules = Schedule::query()
            ->with(['scheduleTechnicians.projectTechnician'])
            ->get();

        $scheduleMap = [];

        foreach ($schedules as $schedule) {
            $startDate = CarbonImmutable::parse($schedule->start_datetime)->toDateString();
            $endDate = CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime)->toDateString();

            foreach ($schedule->scheduleTechnicians as $scheduleTechnician) {
                $technicianId = $scheduleTechnician->projectTechnician?->technician_id;

                if (! $technicianId) {
                    continue;
                }

                $scheduleMap[$technicianId][] = [
                    'start' => $startDate,
                    'end' => $endDate,
                ];
            }
        }

        return $scheduleMap;
    }

    private function resolveProjectName(array $validated): string
    {
        if ($validated['client_type'] === 'Commercial' && filled($validated['company_name'] ?? null)) {
            return $validated['company_name'];
        }

        return trim(collect([
            $validated['firstname'],
            $validated['middle_name'] ?? null,
            $validated['surname'],
        ])->filter()->implode(' '));
    }

    private function generateReferenceNumber(int $projectId): string
    {
        return sprintf('PRJ-%s-%s', now()->format('Ymd'), str_pad((string) $projectId, 5, '0', STR_PAD_LEFT));
    }

    private function storeDocument($uploadedFile, int $projectId, string $folder): void
    {
        if (! $uploadedFile) {
            return;
        }

        $directory = public_path('uploads/'.$folder);

        File::ensureDirectoryExists($directory);

        $fileName = Str::uuid()->toString().'.'.$uploadedFile->getClientOriginalExtension();
        $uploadedFile->move($directory, $fileName);

        Document::create([
            'project_id' => $projectId,
            'document_type' => $folder,
            'document_name' => $fileName,
            'document_path' => 'uploads/'.$folder.'/'.$fileName,
            'uploaded_at' => now(),
        ]);
    }
}
