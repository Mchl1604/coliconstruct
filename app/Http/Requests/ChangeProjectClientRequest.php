<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChoosesClient;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Move one project to another client - picked from those on record, or typed
 * in new with the wizard's rules.
 *
 * The client's own record is never edited here: the project simply points at
 * a different one. Everything else the project holds stays where it is.
 */
class ChangeProjectClientRequest extends FormRequest
{
    use ChoosesClient;

    private ?Project $project = null;

    /**
     * Administrators only, asked here as well as by the route group.
     */
    public function authorize(): bool
    {
        return in_array($this->user()?->role, User::ADMINISTRATOR_ROLES, true);
    }

    /**
     * The project being moved.
     */
    public function project(): Project
    {
        return $this->project ??= Project::query()->with('client')->findOrFail((int) $this->route('id'));
    }

    public function rules(): array
    {
        return $this->clientRules();
    }

    public function messages(): array
    {
        return $this->clientMessages();
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $project = $this->project();

                if ($project->isReadOnly()) {
                    $validator->errors()->add('client', 'This project can no longer be edited.');

                    return;
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->usesExistingClient() && $this->chosenClient()?->client_id === $project->client_id) {
                    $validator->errors()->add('client_id', 'Already this project\'s client.');

                    return;
                }

                // Partial days are a Residential offering, so a project
                // holding one cannot become a Commercial client's.
                $hasPartialDays = $project->schedules()
                    ->where('scheduling_mode', Schedule::MODE_PARTIAL_DAY)
                    ->exists();

                if ($hasPartialDays && $this->clientType() === 'Commercial') {
                    $validator->errors()->add('client_id', 'Commercial clients can\'t keep partial-day bookings.');
                }
            },
        ];
    }
}
