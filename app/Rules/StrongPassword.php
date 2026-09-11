<?php

namespace App\Rules;

use App\Support\PasswordPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A new password that meets PasswordPolicy: long enough, with an uppercase
 * letter, a lowercase letter, a number and a special character.
 *
 * Reached through PasswordPolicy::rules() rather than on its own, so every
 * field that sets a password carries the same length cap and the same
 * confirmation handling alongside it.
 */
class StrongPassword implements ValidationRule
{
    /**
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // A non-string is the `string` rule's to report; failing here as well
        // would only say the same thing twice.
        if (! is_string($value)) {
            return;
        }

        $message = PasswordPolicy::failureMessage($value);

        if ($message !== null) {
            $fail($message);
        }
    }
}
