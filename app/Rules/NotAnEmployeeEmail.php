<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An address that already belongs to a member of staff is not a client's
 * address.
 *
 * A project's client email is not just a contact detail. It is the key the
 * whole client side is keyed on: ClientProjects matches a client account to
 * its projects by it, UserAccountService links project contacts to an account
 * by it when one is opened, and the project's own notifications and emails are
 * addressed with it. Point it at an employee and those all resolve to the
 * wrong person - an administrator or a technician would find a client's
 * project sitting in their portal, and the client's own mail would go to a
 * colleague.
 *
 * It is also a dead end. `users.email` is unique, so the client can never
 * register the address to claim the project; they would simply be told it is
 * taken, with no way to see why.
 *
 * Archived staff accounts count. The row is still there and still holds the
 * address - archiving takes an account off the active lists, it does not
 * release its email - so a client using it would hit the same wall.
 */
class NotAnEmployeeEmail implements ValidationRule
{
    /**
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $address = mb_strtolower(trim((string) $value));

        if ($address === '') {
            return;
        }

        // Compared in SQL on both sides, the same way every other lookup of an
        // address in this system is, so a stored value with stray whitespace
        // or different casing is still recognised as the same person.
        $taken = User::query()
            ->whereIn('role', User::EMPLOYEE_ROLES)
            ->whereRaw('LOWER(TRIM(email)) = ?', [$address])
            ->exists();

        if ($taken) {
            $fail('This email address belongs to an employee account. Use the client\'s own email address.');
        }
    }
}
