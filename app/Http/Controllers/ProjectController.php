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
use Throwable;
use App\Models\TechnicianReport;
use App\Models\Task;
use Illuminate\Http\Request;

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

        try {

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
                    'company_name' => $this->inputCompanyName($validated),
                    'surname' => $validated['surname'] ?? null,
                    'firstname' => $validated['firstname'],
                    'middlename' => $validated['middle_name'] ?? null,
                    'fullname' => trim(collect([
                        $validated['firstname'],
                        $validated['middle_name'] ?? null,
                        $validated['surname'] ?? null,
                    ])->filter()->implode(' ')),
                    'email_address' => $validated['client_email'],
                    'contact_number' => $validated['client_phone'],
                ]);

                $projectTypeIds = ProjectType::query()
                    ->get()
                    ->filter(fn(ProjectType $projectType) =>
                    in_array($projectType->type_name, $validated['project_types'], true))
                    ->pluck('type_id')
                    ->all();

                $project->projectTypes()->sync($projectTypeIds);

                $selectedTechnicianIds = collect([
                    $validated['lead_tech'],
                    ...$validated['technicians'],
                ])
                    ->map(fn($id) => (int) $id)
                    ->unique()
                    ->values();

                $projectTechnicians = $selectedTechnicianIds->map(function (int $technicianId) use ($project) {
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

                $projectTechnicians->each(function ($projectTechnician) use ($schedule) {
                    ScheduleTechnician::create([
                        'schedule_id' => $schedule->schedule_id,
                        'project_technician_id' => $projectTechnician->project_technician_id,
                    ]);
                });

                $this->storeDocument(
                    $request->file('assessment_report'),
                    $project->project_id,
                    'assessment'
                );

                $this->storeDocument(
                    $request->file('approved_quotation'),
                    $project->project_id,
                    'quotation'
                );

                if ($validated['client_type'] !== 'Residential' && $request->hasFile('contract')) {
                    $this->storeDocument(
                        $request->file('contract'),
                        $project->project_id,
                        'contract'
                    );
                }
            });

            return redirect()
                ->route('super-admin.projects')
                ->with(session()->flash('success', 'Project created successfully.'));
        } catch (Throwable $e) {

            return redirect()
                ->route('super-admin.projects')
                ->with(session()->flash('error', 'An error occurred while creating the project: ' . $e->getMessage()));
        }
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
    private function inputCompanyName(array $validated): ?string
    {
        if ($validated['client_type'] === 'Commercial' && filled($validated['company_name'] ?? null)) {
            return $validated['company_name'] ?? null;
        }
        return null;
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

        $directory = public_path('uploads/' . $folder);

        File::ensureDirectoryExists($directory);

        $fileName = Str::uuid()->toString() . '.' . $uploadedFile->getClientOriginalExtension();
        $uploadedFile->move($directory, $fileName);

        Document::create([
            'project_id' => $projectId,
            'document_type' => $folder,
            'document_name' => $fileName,
            'document_path' => 'uploads/' . $folder . '/' . $fileName,
            'uploaded_at' => now(),
        ]);
    }

    public function show(Request $request, int $id)
{
    $project = Project::with([

    'clients',

    'documents',

    'schedule',

    'projectTypes',

    'projectTechnicians.technician' => function ($query) {

        $query->with('account')
              ->withCount([
                  'tasks as tasks_count' => function ($q) {

                      $q->whereIn('status', [
                          'pending',
                          'ongoing'
                      ]);

                  }
              ]);

    }

])->findOrFail($id);

    $projectTypes = ProjectType::query()
        ->orderBy('type_name', 'asc')
        ->get();

    // Get technician reports for this project
    $reports = TechnicianReport::with('images')
        ->where('project_id', $id);

    $tasks = Task::with(['technician', 'images'])
    ->where('project_id', $id)
    ->latest()
    ->get();

    // Filter by report type
    if ($request->filled('report_type')) {
        $reports->where('report_type', $request->report_type);
    }

    $reports = $reports->latest()->get();

    return view('super-admin.projectDetails', compact(
        'project',
        'projectTypes',
        'reports',
        'tasks'
    ));
}

    public function previewDocument(int $id, string $type)
    {
        $project = Project::query()
            ->with(['documents'])
            ->findOrFail($id);

        $document = $project->documents->firstWhere('document_type', $type);

        abort_unless($document, 404);

        $documentUrl = asset($document->document_path);
        $extension = strtolower(pathinfo($document->document_path, PATHINFO_EXTENSION));

        $previewType = match ($extension) {
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg' => 'image',
            'pdf' => 'pdf',
            'doc', 'docx' => 'docx',
            default => 'file',
        };

        $title = match ($type) {
            'assessment' => 'Assessment',
            'quotation' => 'Quotation',
            'contract' => 'Contract',
            default => ucfirst($type),
        };

        return view('super-admin.projectDocumentPreview', compact(
            'project',
            'document',
            'documentUrl',
            'previewType',
            'title'
        ));
    }


    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_initial' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'address' => ['required', 'string'],
            'contact_number' => ['required', 'regex:/^09\d{9}$/'],
            'email_address' => ['required', 'email', 'max:255'],
            'quotation' => ['required', 'numeric', 'min:0'],
            'project_description' => ['required', 'string'],
            'project_types' => ['required', 'array', 'min:1'],
            'project_types.*' => ['required', 'integer', 'exists:tbl_project_types,type_id'],

            'assessmentDocument' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,docx'],
            'quotationDocument' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,docx'],
            'contractDocument' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,docx'],
        ]);

        try {

            DB::transaction(function () use ($validated, $request, $id) {

                $project = Project::findOrFail($id);

                $project->update([
                    'quotation' => $validated['quotation'],
                    'address' => $validated['address'],
                    'description' => $validated['project_description'],
                ]);

                $client = Client::query()
                    ->where('project_id', $project->project_id)
                    ->firstOrFail();

                $client->update([
                    'client_type' => $client->client_type,
                    'company_name' => $validated['company_name'] ?? null,
                    'surname' => $validated['last_name'],
                    'firstname' => $validated['first_name'],
                    'middlename' => $validated['middle_initial'] ?? null,
                    'fullname' => trim(collect([
                        $validated['first_name'],
                        $validated['middle_initial'] ?? null,
                        $validated['last_name'],
                    ])->filter()->implode(' ')),
                    'email_address' => $validated['email_address'],
                    'contact_number' => $validated['contact_number'],
                ]);

                $project->projectTypes()->sync($validated['project_types']);

                // Update documents here if needed...
                $this->replaceDocument(
                    $request->file('assessmentDocument'),
                    $project->project_id,
                    'assessment'
                );

                $this->replaceDocument(
                    $request->file('quotationDocument'),
                    $project->project_id,
                    'quotation'
                );

                if (
                    $client->client_type === 'Commercial' &&
                    $request->hasFile('contractDocument')
                ) {
                    $this->replaceDocument(
                        $request->file('contractDocument'),
                        $project->project_id,
                        'contract'
                    );
                }
            });

            return redirect()
                ->route('super-admin.projects.show', $id)
                ->with('success', 'Project updated successfully.');
        } catch (\Throwable $e) {

            return redirect()
                ->route('super-admin.projects.show', $id)
                ->with('error', $e->getMessage());
        }
    }

    private function replaceDocument($uploadedFile, int $projectId, string $documentType): void
    {
        if (! $uploadedFile) {
            return;
        }

        $directory = public_path('uploads/' . $documentType);

        File::ensureDirectoryExists($directory);

        // Find existing document
        $document = Document::query()
            ->where('project_id', $projectId)
            ->where('document_type', $documentType)
            ->first();

        // Delete old physical file
        if ($document && File::exists(public_path($document->document_path))) {
            File::delete(public_path($document->document_path));
        }

        // Store new file
        $fileName = Str::uuid() . '.' . $uploadedFile->getClientOriginalExtension();

        $uploadedFile->move($directory, $fileName);

        $data = [
            'document_name' => $fileName,
            'document_path' => 'uploads/' . $documentType . '/' . $fileName,
            'uploaded_at' => now(),
        ];

        if ($document) {

            // Replace existing database record
            $document->update($data);
        } else {

            // Create one if it doesn't exist
            Document::create(array_merge($data, [
                'project_id' => $projectId,
                'document_type' => $documentType,
            ]));
        }
    }
}
