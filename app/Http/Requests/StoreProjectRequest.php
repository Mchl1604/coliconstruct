<?php

namespace App\Http\Requests;

use App\Models\ProjectType;
use App\Models\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $projectTypes = $this->allowedProjectTypeNames();
        $contractRules = $this->input('client_type') === 'Commercial'
            ? ['required', 'file', 'mimes:jpg,jpeg,png,pdf,docx']
            : ['nullable'];

        return [
            'client_type' => ['required', Rule::in(['Residential', 'Commercial'])],
            'company_name' => ['nullable', 'string', 'max:255', Rule::requiredIf($this->input('client_type') === 'Commercial')],
            'surname' => ['required', 'string', 'max:255'],
            'firstname' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'client_email' => ['required', 'email:rfc', 'max:255'],
            'client_phone' => ['required', 'regex:/^09\d{9}$/'],
            'project_address' => ['required', 'string'],
            'quotation_amount' => ['required', 'numeric', 'min:0'],
            'project_types' => ['required', 'array', 'min:1'],
            'project_types.*' => ['required', 'string', Rule::in($projectTypes)],
            'assessment_report' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,docx'],
            'approved_quotation' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,docx'],
            'contract' => $contractRules,
            'project_description' => ['required', 'string'],
            'lead_tech' => ['required', 'integer', Rule::exists('tbl_technicians', 'technician_id')],
            'technicians' => ['required', 'array', 'min:1'],
            'technicians.*' => ['required', 'integer', Rule::exists('tbl_technicians', 'technician_id')],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ];
    }

    public function messages(): array
    {
        return [
            'quotation_amount.numeric' => 'The quotation amount must be a valid number.',
            'quotation_amount.min' => 'The quotation amount must be at least zero.',
        ];
    }

    public function after(): array
{
    return [
        function ($validator): void {
            $technicianIds = collect([
                $this->input('lead_tech'),
                ...($this->input('technicians', [])),
            ])
                ->filter()
                ->map(fn ($technicianId): int => (int) $technicianId)
                ->unique()
                ->values();

            if ($technicianIds->isEmpty()) {
                return;
            }

            $startDate = CarbonImmutable::parse($this->input('start_date'))->startOfDay();
            $endDate = CarbonImmutable::parse($this->input('end_date'))->endOfDay();

            $conflictingSchedules = Schedule::query()
                ->whereHas('project', function ($query): void {
                    $query->whereIn('status', ['pending', 'ongoing']);
                })
                ->with(['scheduleTechnicians.projectTechnician'])
                ->whereHas('scheduleTechnicians.projectTechnician', function ($query) use ($technicianIds): void {
                    $query->whereIn('technician_id', $technicianIds->all());
                })
                ->get()
                ->filter(function (Schedule $schedule) use ($startDate, $endDate): bool {
                    $existingStart = CarbonImmutable::parse($schedule->start_datetime)->startOfDay();
                    $existingEnd = CarbonImmutable::parse($schedule->end_datetime ?? $schedule->start_datetime)->endOfDay();

                    return $startDate->lessThanOrEqualTo($existingEnd)
                        && $endDate->greaterThanOrEqualTo($existingStart);
                });

            if ($conflictingSchedules->isNotEmpty()) {
                $validator->errors()->add('start_date', 'The selected schedule overlaps an existing technician assignment.');
                $validator->errors()->add('end_date', 'The selected schedule overlaps an existing technician assignment.');
            }
        },
    ];
}

    /**
     * @return array<int, string>
     */
    private function allowedProjectTypeNames(): array
    {
        $projectTypeNames = ProjectType::query()
            ->orderBy('type_name', 'asc')
            ->pluck('type_name')
            ->all();

        if ($projectTypeNames !== []) {
            return $projectTypeNames;
        }

        return [
            'Aircon Installation',
            'Aircon Repair',
            'Aircon Cleaning',
            'Ducting Fabrication',
            'Ducting Installation',
        ];
    }
}
