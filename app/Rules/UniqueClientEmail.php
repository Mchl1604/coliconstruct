<?php

namespace App\Rules;

use App\Services\ClientProjects;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One address is one client.
 *
 * tbl_clients keeps one row per person, keyed in practice by their address -
 * it carries a unique index, compared normalised. A second client typed in
 * under an address already on record is the same person entered twice, so it
 * is refused and the existing client is used instead.
 *
 * Matched on the address only, never on the name: names collide between
 * different people and get spelled differently by the same one.
 */
class UniqueClientEmail implements ValidationRule
{
    /**
     * @param  int|null  $ignoreClientId  the client being edited, whose own
     *                                    address is not a duplicate of itself
     */
    public function __construct(private readonly ?int $ignoreClientId = null) {}

    /**
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $existing = app(ClientProjects::class)->clientWithEmail(is_string($value) ? $value : null);

        if ($existing !== null && $existing->client_id !== $this->ignoreClientId) {
            $fail('Client already exists.');
        }
    }
}
