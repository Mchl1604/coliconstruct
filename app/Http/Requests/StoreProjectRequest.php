<?php

namespace App\Http\Requests;

use App\Models\Document;
use App\Models\ProjectType;
use App\Models\Schedule;
use App\Services\ScheduleModeRules;
use App\Services\TechnicianAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    /**
     * What the submitted schedule turned out to mean, worked out during
     * validation so the controller does not have to interpret raw input a
     * second time and reach a different answer.
     *
     * @var array{mode: string, start: CarbonImmutable, end: CarbonImmutable}|null
     */
    private ?array $scheduleEntry = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $projectTypes = $this->allowedProjectTypeNames();

        // Every document is held to the same size, stated on the model so the
        // wizard and the edit dialog cannot drift apart.
        $documentRules = ['file', 'mimes:jpg,jpeg,png,pdf,docx', 'max:'.Document::MAX_KILOBYTES];

        $contractRules = $this->input('client_type') === 'Commercial'
            ? array_merge(['required'], $documentRules)
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
            'assessment_report' => array_merge(['required'], $documentRules),
            'approved_quotation' => array_merge(['required'], $documentRules),
            'contract' => $contractRules,
            'project_description' => ['required', 'string'],
            'lead_tech' => ['required', 'integer', Rule::exists('tbl_technicians', 'technician_id')],
            'technicians' => ['required', 'array', 'min:1'],
            'technicians.*' => ['required', 'integer', Rule::exists('tbl_technicians', 'technician_id')],
            // The dates and times themselves are shape-checked here and given
            // their meaning by ScheduleModeRules in after(), which is the only
            // place that knows what the chosen mode requires.
            ...app(ScheduleModeRules::class)->rules(),
        ];
    }

    public function messages(): array
    {
        return [
            'quotation_amount.numeric' => 'The quotation amount must be a valid number.',
            'quotation_amount.min' => 'The quotation amount must be at least zero.',
            'assessment_report.max' => 'The assessment report must be '.Document::MAX_LABEL.' or smaller.',
            'approved_quotation.max' => 'The approved quotation must be '.Document::MAX_LABEL.' or smaller.',
            'contract.max' => 'The contract must be '.Document::MAX_LABEL.' or smaller.',
            ...app(ScheduleModeRules::class)->messages(),
        ];
    }

    /**
     * The schedule this request asks for, as the controller should store it.
     *
     * @return array{mode: string, start: CarbonImmutable, end: CarbonImmutable}
     */
    public function scheduleEntry(): array
    {
        // Unreachable while the request has passed validation: after() either
        // fills this in or fails the request.
        return $this->scheduleEntry ?? throw new \RuntimeException('The schedule was not validated.');
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                // Only run once the fields this check depends on are themselves
                // valid, otherwise there is no team to check availability for.
                if ($validator->errors()->hasAny(['lead_tech', 'technicians'])) {
                    return;
                }

                $scheduleRules = app(ScheduleModeRules::class);

                $entry = $scheduleRules->validateEntry(
                    $validator,
                    $this->only([
                        'scheduling_mode',
                        'start_date',
                        'end_date',
                        'project_date',
                        'start_time',
                        'end_time',
                    ]),
                    '',
                    // Partial days are a Residential offering. A Commercial
                    // project asking for one is refused rather than quietly
                    // downgraded to a whole day.
                    $this->input('client_type') === 'Residential'
                );

                if (! $entry) {
                    return;
                }

                $this->scheduleEntry = $entry;

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

                $availability = app(TechnicianAvailabilityService::class);
                $conflicts = $availability->findConflicts($technicianIds, [$entry]);

                if ($conflicts->isEmpty()) {
                    return;
                }

                $message = $availability->conflictMessage($conflicts);

                // Reported against the fields the chosen mode actually shows,
                // so the wizard can put the message where the person is
                // looking.
                $fields = $entry['mode'] === Schedule::MODE_PARTIAL_DAY
                    ? ['project_date', 'start_time', 'end_time']
                    : ['start_date', 'end_date'];

                foreach ($fields as $field) {
                    $validator->errors()->add($field, $message);
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
