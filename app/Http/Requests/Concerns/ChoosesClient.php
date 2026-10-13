<?php

namespace App\Http\Requests\Concerns;

use App\Models\Client;
use App\Rules\NotAnEmployeeEmail;
use App\Rules\UniqueClientEmail;
use App\Support\PersonName;
use Illuminate\Validation\Rule;

/**
 * Pick a client already on record, or type a new one in.
 *
 * Shared by the New Project wizard and Project Details' Change client dialog,
 * which ask the same question with the same fields. An existing client is
 * used exactly as stored, so whatever the form posted for their details is
 * neither checked nor kept; a new one is held to the wizard's rules,
 * including one address being one client.
 */
trait ChoosesClient
{
    public const CLIENT_EXISTING = 'existing';

    public const CLIENT_NEW = 'new';

    /**
     * The client picked in Existing client mode, read once.
     */
    private ?Client $chosenClient = null;

    private bool $chosenClientLoaded = false;

    /**
     * Whether the form picked a client already on record.
     */
    public function usesExistingClient(): bool
    {
        return $this->input('client_mode') === self::CLIENT_EXISTING;
    }

    /**
     * The client picked in Existing client mode, or null.
     */
    public function chosenClient(): ?Client
    {
        if (! $this->chosenClientLoaded) {
            $this->chosenClientLoaded = true;
            $clientId = $this->input('client_id');

            $this->chosenClient = $this->usesExistingClient() && is_numeric($clientId)
                ? Client::query()->find((int) $clientId)
                : null;
        }

        return $this->chosenClient;
    }

    /**
     * Residential or Commercial: the stored client's type for an existing
     * client - whatever the form posted for it is ignored - and the typed one
     * for a new client.
     */
    public function clientType(): ?string
    {
        return $this->usesExistingClient()
            ? $this->chosenClient()?->client_type
            : $this->input('client_type');
    }

    /**
     * New client is the wizard's default, so a form that does not say is
     * treated as one.
     */
    protected function defaultToNewClient(): void
    {
        if (! $this->filled('client_mode')) {
            $this->merge(['client_mode' => self::CLIENT_NEW]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function clientRules(): array
    {
        $existing = $this->usesExistingClient();
        $excludeForExisting = Rule::excludeIf($existing);

        return [
            'client_mode' => ['required', Rule::in([self::CLIENT_EXISTING, self::CLIENT_NEW])],
            'client_id' => [Rule::excludeIf(! $existing), 'required', 'integer', Rule::exists('tbl_clients', 'client_id')],
            'client_type' => [$excludeForExisting, 'required', Rule::in(['Residential', 'Commercial'])],
            'company_name' => [$excludeForExisting, 'nullable', 'string', 'max:255', Rule::requiredIf($this->input('client_type') === 'Commercial')],
            'surname' => [$excludeForExisting, 'required', 'string', 'max:255'],
            'firstname' => [$excludeForExisting, 'required', 'string', 'max:255'],
            // An initial, not a name - the field has always been
            // labelled M.I. and now only accepts one.
            'middle_name' => [$excludeForExisting, ...PersonName::middleInitialRules()],
            // A client is not a member of staff. An employee's address
            // here would hand them the client's own project - see
            // NotAnEmployeeEmail. Nor is it a client already on record:
            // that client is picked instead - see UniqueClientEmail.
            'client_email' => [$excludeForExisting, 'required', 'email:rfc', 'max:255', new NotAnEmployeeEmail, new UniqueClientEmail],
            'client_phone' => [$excludeForExisting, 'required', 'regex:/^09\d{9}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function clientMessages(): array
    {
        return [
            ...PersonName::middleInitialMessages(),
            'client_mode.required' => 'Choose existing or new client.',
            'client_mode.in' => 'Choose existing or new client.',
            'client_id.required' => 'Pick a client.',
            'client_id.integer' => 'Pick a client.',
            'client_id.exists' => 'That client no longer exists.',
        ];
    }
}
