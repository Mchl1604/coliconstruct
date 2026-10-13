<?php

namespace App\Rules;

use App\Services\ClientProjects;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A client account is opened only for a client already on record.
 *
 * The address has to belong to a client - somebody Coliconstruct has booked
 * work for - and that client must not have an account already. The account
 * then takes its details from the client record (see ClientRecords), so the
 * person typing the form is never asked for them and never shown them: what
 * proves they own the address is the code emailed to it, which comes after
 * this.
 *
 * A client an administrator deliberately took an account off (see
 * user_unlinked_at) is not reopened from the public form either; that
 * decision was the office's, and so is undoing it.
 */
class RegistrableClientEmail implements ValidationRule
{
    /**
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $client = app(ClientProjects::class)->clientWithEmail(is_string($value) ? $value : null);

        if ($client === null) {
            $fail('No project under this email. Contact us.');

            return;
        }

        if ($client->user_id !== null) {
            $fail('This client already has an account.');

            return;
        }

        if ($client->user_unlinked_at !== null) {
            $fail('Contact us to open this account.');
        }
    }
}
