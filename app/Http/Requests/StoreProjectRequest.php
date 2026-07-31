<?php

namespace App\Http\Requests;

use App\Models\ProjectType;
use App\Services\TechnicianAvailabilityService;
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
            'start_date' => ['required', 'date', 'after_or_equal:today'],
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
                // Only run once the fields this check depends on are themselves
                // valid, otherwise a bad date string would blow up the parser.
                if ($validator->errors()->hasAny(['start_date', 'end_date', 'lead_tech', 'technicians'])) {
                    return;
                }

                $technicianIds = collect([
                    $this->input('lead_tech'),
                    ...($this->input('technicians', [])),
                ])
                    ->filter()
                    ->map(fn ($technicianId): int => (int) $technicianId)
                    ->unique()
                    ->values();

                if ($technicianIds->isEmpty() || ! $this->filled(['start_date', 'end_date'])) {
                    return;
                }

                $ranges = [[
                    'start' => CarbonImmutable::parse($this->input('start_date'))->startOfDay(),
                    'end' => CarbonImmutable::parse($this->input('end_date'))->startOfDay(),
                ]];

                $conflicts = app(TechnicianAvailabilityService::class)
                    ->findConflicts($technicianIds, $ranges);

                if ($conflicts->isEmpty()) {
                    return;
                }

                $message = app(TechnicianAvailabilityService::class)->conflictMessage($conflicts);

                $validator->errors()->add('start_date', $message);
                $validator->errors()->add('end_date', $message);
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
