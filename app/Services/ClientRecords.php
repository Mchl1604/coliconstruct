<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;

/**
 * A client record and the client account that belongs to it.
 *
 * The two describe one person, so their name and contact number are kept as
 * one: a change made on either side - the client's own profile, the account
 * in Configuration, or the client details on a project - is written to the
 * other in the same save. For a commercial client the company name travels
 * the same way, from the client record to the account.
 *
 * The email address is the exception and deliberately so. It is the
 * account's login credential, changed only by its owner behind an emailed
 * code - see ProfileService::changeEmail - so nothing here moves it.
 */
class ClientRecords
{
    public function __construct(private readonly ClientProjects $clientProjects) {}

    /**
     * The client the wizard's New client fields describe - also what Change
     * client creates.
     *
     * @param  array<string, mixed>  $fields  validated ChoosesClient fields
     */
    public function create(array $fields): Client
    {
        $account = $this->clientProjects->accountFor($fields['client_email']);
        $commercial = $fields['client_type'] === 'Commercial';

        $client = Client::create([
            // Linked to the account behind the address when there is one and
            // no other client holds it. Null otherwise, which is the ordinary
            // case: work is booked before the client opens an account.
            'user_id' => $account && ! $this->clientProjects->accountHeldByAnotherClient($account)
                ? $account->id
                : null,
            'client_type' => $fields['client_type'],
            'company_name' => $commercial && filled($fields['company_name'] ?? null) ? $fields['company_name'] : null,
            'surname' => $fields['surname'] ?? null,
            'firstname' => $fields['firstname'],
            'middlename' => $fields['middle_name'] ?? null,
            'fullname' => $this->fullName($fields['firstname'], $fields['middle_name'] ?? null, $fields['surname'] ?? null),
            'email_address' => $fields['client_email'],
            'contact_number' => $fields['client_phone'],
        ]);

        return $client;
    }

    /**
     * The account details a client's record supplies when their account is
     * opened: name, number, and the company for a commercial client.
     *
     * @return array{name: string, first_name: string, middle_name: ?string, last_name: ?string, contact_number: ?string, company_name: ?string}
     */
    public function accountDetailsFor(Client $client): array
    {
        $first = trim((string) $client->firstname);
        $middle = $this->initial($client->middlename);
        $last = trim((string) $client->surname) ?: null;

        return [
            'name' => $this->fullName($first, $middle, $last),
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'contact_number' => filled($client->contact_number) ? (string) $client->contact_number : null,
            'company_name' => $this->companyFor($client),
        ];
    }

    /**
     * Copy an account's name and number onto its client record, after the
     * account was edited - by its owner on their profile, or by an
     * administrator in Configuration. Every project of that client shows the
     * new name.
     */
    public function syncFromAccount(User $account): void
    {
        $client = Client::query()->where('user_id', $account->id)->first();

        if ($client === null) {
            return;
        }

        $client->forceFill([
            'firstname' => (string) $account->first_name,
            'middlename' => $account->middle_name,
            'surname' => $account->last_name,
            'fullname' => $this->fullName($account->first_name, $account->middle_name, $account->last_name),
            'contact_number' => $account->contact_number,
        ]);

        if ($client->isDirty()) {
            $client->save();
        }
    }

    /**
     * Copy a client record's name, number and company onto its account, after
     * an administrator edited the client on a project.
     */
    public function syncToAccount(Client $client): void
    {
        $account = $client->user_id ? User::query()->find($client->user_id) : null;

        if ($account === null) {
            return;
        }

        $account->forceFill($this->accountDetailsFor($client));

        if ($account->isDirty()) {
            $account->save();
        }
    }

    /**
     * The company a commercial client's account carries. A residential
     * client's account carries none, even when a company was recorded
     * against them.
     */
    public function companyFor(Client $client): ?string
    {
        return $client->isCommercial() && filled($client->company_name)
            ? (string) $client->company_name
            : null;
    }

    /**
     * First, middle and last, as the wizard has always written `fullname`.
     */
    private function fullName(?string $first, ?string $middle, ?string $last): string
    {
        return trim(collect([$first, $middle, $last])
            ->map(fn (?string $part): string => trim((string) $part))
            ->filter()
            ->implode(' '));
    }

    /**
     * One letter, which is what an account's middle initial is. Older client
     * records carry "A." or a whole name.
     */
    private function initial(?string $middle): ?string
    {
        $letter = preg_replace('/[^A-Za-z]/', '', (string) $middle);

        return $letter === '' ? null : mb_strtoupper(mb_substr($letter, 0, 1));
    }
}
