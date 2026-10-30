<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\IncidentPerson;
use App\Models\IncidentReport;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\TechnicianReportImage;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Support\BusinessTime;
use App\Support\UploadStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Incident reports: filed by the project's lead with a structured form, and
 * reviewed by the office - Open, Under Review, Closed.
 *
 * The report row (tbl_technician_reports) keeps the title, description, date
 * and photographs, so every list of reports keeps working; the choices hang
 * off it as an IncidentReport. A Serious or Critical incident reaches the
 * administrators the moment it is filed.
 */
class IncidentReporting
{
    public function __construct(
        private readonly ProjectPolicy $projects,
        private readonly ActivityLogger $activityLogger,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Why this account may not file an incident report on this project, or
     * null when it may. Only the project's lead files them.
     */
    public function refusal(?User $user, Project $project): ?string
    {
        if ($user === null || ! $user->isLeadTechnician() || ! $this->projects->reportsOn($user, $project)) {
            return 'Only the project\'s lead files incident reports.';
        }

        if ($project->isArchived()) {
            return 'This project is archived.';
        }

        return null;
    }

    /**
     * File an incident report.
     *
     * @param  array<string, mixed>  $data  validated by IncidentReportController
     * @param  array<int, array{technician_id?: int|null, name?: string|null, person_type: string, involvement: string}>  $persons
     * @param  array<int, UploadedFile>  $images
     *
     * @throws RuntimeException
     */
    public function file(Project $project, array $data, array $persons, array $images, User $by): TechnicianReport
    {
        $refusal = $this->refusal($by, $project);

        if ($refusal !== null) {
            throw new RuntimeException($refusal);
        }

        $occurredAt = BusinessTime::toStored($data['occurred_date'].' '.$data['occurred_time'].':00');

        if ($occurredAt->isFuture()) {
            throw new RuntimeException('It cannot have happened in the future.');
        }

        $persons = $this->people($project, $persons);
        $technician = Technician::query()->where('account_id', $by->id)->firstOrFail();
        $typeLabel = IncidentReport::TYPES[$data['incident_type']];

        [$report, $incident] = DB::transaction(function () use ($project, $data, $persons, $images, $by, $technician, $typeLabel, $occurredAt): array {
            $report = TechnicianReport::create([
                'project_id' => $project->project_id,
                'technician_id' => $technician->technician_id,
                'submitted_by' => $by->id,
                'report_type' => 'incident',
                'report_title' => sprintf('Incident: %s', $typeLabel),
                'report_description' => trim((string) $data['description']),
                'report_date' => $data['occurred_date'],
            ]);

            $incident = IncidentReport::create([
                'technician_report_id' => $report->id,
                'incident_type' => $data['incident_type'],
                'severity' => $data['severity'],
                'occurred_at' => $occurredAt,
                'phase_id' => $data['phase_id'] ?? null,
                'task_id' => $data['task_id'] ?? null,
                'location' => $data['location'],
                'location_details' => $this->text($data['location_details'] ?? null, 255),
                // Kept only for the kind of incident they describe.
                'injury_body_part' => $data['incident_type'] === 'injury' ? ($data['injury_body_part'] ?? null) : null,
                'injury_type' => $data['incident_type'] === 'injury' ? ($data['injury_type'] ?? null) : null,
                'injury_treatment' => $data['incident_type'] === 'injury' ? ($data['injury_treatment'] ?? null) : null,
                'damage_description' => $this->text($data['damage_description'] ?? null, 255),
                'damage_owner' => $data['damage_owner'] ?? null,
                'damage_cost' => filled($data['damage_cost'] ?? null) ? $data['damage_cost'] : null,
                'refrigerant_type' => $data['incident_type'] === 'refrigerant_leak' ? ($data['refrigerant_type'] ?? null) : null,
                'refrigerant_amount' => $data['incident_type'] === 'refrigerant_leak' && filled($data['refrigerant_amount'] ?? null) ? $data['refrigerant_amount'] : null,
                'immediate_action' => $this->text($data['immediate_action'] ?? null, 2000),
                'cause' => $data['cause'] ?? null,
                'preventive_action' => $this->text($data['preventive_action'] ?? null, 2000),
                'work_stopped' => (bool) ($data['work_stopped'] ?? false),
                'client_notified' => (bool) ($data['client_notified'] ?? false),
                'status' => IncidentReport::STATUS_OPEN,
            ]);

            foreach ($persons as $person) {
                IncidentPerson::create($person + ['incident_report_id' => $incident->incident_report_id]);
            }

            foreach ($images as $image) {
                TechnicianReportImage::create([
                    'technician_report_id' => $report->id,
                    'image_path' => UploadStore::put($image, 'report_images'),
                ]);
            }

            return [$report, $incident];
        });

        $this->activityLogger->record(
            ActivityLog::REPORT_GENERATED,
            null,
            sprintf('Filed a %s incident report (%s) on %s.', strtolower($incident->label('SEVERITIES', 'severity')), $typeLabel, $project->reference_no),
            $report
        );

        $this->notifications->technicianReportFiled($project, 'incident report');

        if ($incident->isUrgent()) {
            $this->notifications->urgentIncident($project, $report, $incident);
        }

        return $report;
    }

    /**
     * Move an incident through its review, with the office's notes.
     *
     * @throws RuntimeException
     */
    public function review(IncidentReport $incident, string $status, ?string $notes, User $by): IncidentReport
    {
        if (! array_key_exists($status, IncidentReport::STATUSES)) {
            throw new RuntimeException('Choose a status.');
        }

        $notes = $this->text($notes, 2000);

        if ($status === IncidentReport::STATUS_CLOSED && $notes === null && $incident->resolution_notes === null) {
            throw new RuntimeException('Say how it was resolved before closing it.');
        }

        $incident->update([
            'status' => $status,
            'resolution_notes' => $notes ?? $incident->resolution_notes,
            'reviewed_by' => $by->id,
            'reviewed_at' => now(),
        ]);

        $this->activityLogger->record(
            ActivityLog::INCIDENT_REVIEWED,
            null,
            sprintf('Marked incident %s as %s.', $incident->report?->displayCode(), strtolower(IncidentReport::STATUSES[$status])),
            $incident->report
        );

        return $incident->refresh();
    }

    /**
     * The people as they are stored: a team member is named from the team,
     * anyone else by what was typed. A technician not on the project is
     * refused rather than quietly dropped.
     *
     * @param  array<int, array<string, mixed>>  $persons
     * @return array<int, array{technician_id: int|null, name: string, person_type: string, involvement: string}>
     *
     * @throws RuntimeException
     */
    private function people(Project $project, array $persons): array
    {
        $team = ProjectTechnician::query()
            ->with('technician.account')
            ->where('project_id', $project->project_id)
            ->get()
            ->keyBy(fn (ProjectTechnician $member): int => (int) $member->technician_id);

        return collect($persons)
            ->map(function (array $person) use ($team): array {
                $technicianId = filled($person['technician_id'] ?? null) ? (int) $person['technician_id'] : null;

                if ($technicianId !== null) {
                    $member = $team->get($technicianId);

                    if ($member === null) {
                        throw new RuntimeException('Pick people from this project\'s team.');
                    }

                    return [
                        'technician_id' => $technicianId,
                        'name' => $member->technician?->name ?? 'Technician',
                        'person_type' => 'team',
                        'involvement' => $person['involvement'],
                    ];
                }

                $name = $this->text($person['name'] ?? null, 150);

                if ($name === null) {
                    throw new RuntimeException('Name everyone involved.');
                }

                return [
                    'technician_id' => null,
                    'name' => $name,
                    'person_type' => $person['person_type'] === 'team' ? 'public' : $person['person_type'],
                    'involvement' => $person['involvement'],
                ];
            })
            ->values()
            ->all();
    }

    private function text(?string $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
