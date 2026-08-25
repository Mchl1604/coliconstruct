<?php

namespace App\Support;

/**
 * The rules a person's name fields are held to, in one place.
 *
 * Only the middle one is here, because only the middle one has a rule worth
 * agreeing on: it is an INITIAL, a single letter, and four separate forms
 * collect it - the profile page, the account editor in Configuration, the
 * project wizard and the project's client details. Each of those used to state
 * its own maximum, and three of them allowed a hundred characters into a box
 * labelled M.I.
 *
 * `alpha:ascii` rather than plain `alpha`: the unqualified rule accepts any
 * Unicode letter, and an initial that can be any mark in any script is not the
 * single a-z character this is meant to be.
 */
class PersonName
{
    /**
     * @return array<int, string>
     */
    public static function middleInitialRules(): array
    {
        return ['nullable', 'string', 'size:1', 'alpha:ascii'];
    }

    /**
     * The refusal, named for whichever field carries the initial - the forms
     * do not agree on that either (`middle_name` on three of them,
     * `middle_initial` on the fourth), but a person reading the message should
     * not have to care.
     *
     * @return array<string, string>
     */
    public static function middleInitialMessages(string $field = 'middle_name'): array
    {
        $message = 'The middle initial must be a single letter (A-Z).';

        return [
            $field.'.size' => $message,
            $field.'.alpha' => $message,
            $field.'.string' => $message,
        ];
    }
}
