<?php

namespace App\Support;

use App\Rules\StrongPassword;
use RuntimeException;

/**
 * What a password has to be, in one place.
 *
 * Every way a password comes into being reads its rules from here: public
 * registration, the forced first-sign-in change, the profile's Change
 * Password, the forgotten-password reset, an administrator typing one into
 * the account dialog, the temporary passwords the system generates, and the
 * console repair. Each of those used to state its own `min:8`, which is how
 * a rule ends up holding on one form and quietly not on the next.
 *
 * The requirements are also what the forms draw their live checklist from -
 * the patterns travel to the browser as data attributes rather than being
 * written out again in JavaScript - so the ticks on screen and the verdict on
 * the server cannot disagree.
 *
 * Only a password being set is held to this. One already stored is never
 * re-checked, so tightening the policy does not lock anybody out.
 */
class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    /**
     * bcrypt reads no further than 72 bytes, so a longer value would be
     * silently cut short rather than stored as typed.
     */
    public const MAX_LENGTH = 72;

    /**
     * The characters a password must include, one of each.
     *
     * Each pattern is written so that PCRE and JavaScript read it identically
     * with the `u` flag, because the same string is used by both. A "special
     * character" is anything that is neither a letter, a digit nor whitespace:
     * `! @ # $ % ^ & *` and every other mark on the keyboard, but not a space,
     * which is too easy to type by accident to count.
     *
     * @var array<string, array{pattern: string, label: string, missing: string}>
     */
    public const CHARACTER_REQUIREMENTS = [
        'uppercase' => [
            'pattern' => '[A-Z]',
            'label' => 'At least 1 uppercase letter',
            'missing' => 'one uppercase letter',
        ],
        'lowercase' => [
            'pattern' => '[a-z]',
            'label' => 'At least 1 lowercase letter',
            'missing' => 'one lowercase letter',
        ],
        'number' => [
            'pattern' => '[0-9]',
            'label' => 'At least 1 number',
            'missing' => 'one number',
        ],
        'symbol' => [
            'pattern' => '[^\p{L}\p{N}\s]',
            'label' => 'At least 1 special character (e.g. ! @ # $ % ^ & *)',
            'missing' => 'one special character',
        ],
    ];

    /**
     * The validation rules for a new password field.
     *
     * The length minimum is part of StrongPassword rather than a separate
     * `min` rule, so a password that is both too short and missing characters
     * is told everything wrong with it in one message rather than one fault
     * per attempt.
     *
     * @param  bool  $required  False where leaving it blank means "generate one
     *                          for me", as the account dialog in Configuration
     *                          does.
     * @param  bool  $confirmed  Whether a `{field}_confirmation` must match.
     * @return array<int, mixed>
     */
    public static function rules(bool $required = true, bool $confirmed = true): array
    {
        return array_values(array_filter([
            $required ? 'required' : 'nullable',
            'string',
            'max:'.self::MAX_LENGTH,
            new StrongPassword,
            // Last, so a weak password is told it is weak before it is told
            // its two copies differ: matching is not the thing to fix first.
            $confirmed ? 'confirmed' : null,
        ]));
    }

    /**
     * The wording for the rules that sit around StrongPassword, keyed for
     * whichever field carries them. StrongPassword words its own.
     *
     * @return array<string, string>
     */
    public static function messages(string $field = 'password'): array
    {
        return [
            $field.'.required' => 'Enter a password.',
            $field.'.max' => 'Password may not be longer than '.self::MAX_LENGTH.' characters.',
            $field.'.confirmed' => 'The two passwords do not match.',
        ];
    }

    /**
     * What this password still lacks, in the order the checklist lists it.
     *
     * An empty array means the password satisfies the policy.
     *
     * @return array<int, string> keys of CHARACTER_REQUIREMENTS, plus
     *                            'length' when it is too short
     */
    public static function unmetRequirements(string $password): array
    {
        $unmet = [];

        if (mb_strlen($password) < self::MIN_LENGTH) {
            $unmet[] = 'length';
        }

        foreach (self::CHARACTER_REQUIREMENTS as $key => $requirement) {
            if (! preg_match('/'.$requirement['pattern'].'/u', $password)) {
                $unmet[] = $key;
            }
        }

        return $unmet;
    }

    public static function isSatisfiedBy(string $password): bool
    {
        return self::unmetRequirements($password) === [];
    }

    /**
     * Refuse a password that falls short.
     *
     * For the places that write a password with no form in front of them -
     * the account services, the seeder, the console repair. The controllers
     * validate first, so through the interface this never fires; it is here
     * so a new caller of those services cannot set a weak password by simply
     * not validating. A RuntimeException, because that is what every service
     * in this application throws for a refusal the person can fix.
     *
     * @throws RuntimeException
     */
    public static function ensureSatisfiedBy(string $password): void
    {
        $message = self::failureMessage($password);

        if ($message !== null) {
            throw new RuntimeException($message);
        }
    }

    /**
     * One sentence naming everything the password is missing, or null when it
     * is missing nothing.
     *
     * "Password must contain at least one uppercase letter." for a single
     * gap; every gap in one sentence when there are several, because the
     * forms show the first error only and a person should not have to fail
     * four times to learn four things.
     */
    public static function failureMessage(string $password): ?string
    {
        $unmet = self::unmetRequirements($password);

        if ($unmet === []) {
            return null;
        }

        $clauses = [];

        if (in_array('length', $unmet, true)) {
            $clauses[] = 'be at least '.self::MIN_LENGTH.' characters long';
        }

        $missing = array_map(
            fn (string $key): string => self::CHARACTER_REQUIREMENTS[$key]['missing'],
            array_values(array_diff($unmet, ['length']))
        );

        if ($missing !== []) {
            $clauses[] = 'contain at least '.self::listed($missing);
        }

        return 'Password must '.implode(' and ', $clauses).'.';
    }

    /**
     * The checklist the forms draw, with what the browser needs to tick each
     * line off as the password is typed - and to word what is still missing
     * the way failureMessage() does.
     *
     * @return array<int, array{key: string, label: string, pattern: string|null, min_length: int|null, missing: string|null}>
     */
    public static function checklist(): array
    {
        $items = [[
            'key' => 'length',
            'label' => 'At least '.self::MIN_LENGTH.' characters',
            'pattern' => null,
            'min_length' => self::MIN_LENGTH,
            'missing' => null,
        ]];

        foreach (self::CHARACTER_REQUIREMENTS as $key => $requirement) {
            $items[] = [
                'key' => $key,
                'label' => $requirement['label'],
                'pattern' => $requirement['pattern'],
                'min_length' => null,
                'missing' => $requirement['missing'],
            ];
        }

        return $items;
    }

    /**
     * "a", "a and b", "a, b and c".
     *
     * @param  array<int, string>  $items
     */
    private static function listed(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
