<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The age an account holder has to be, and the rules that enforce it.
 *
 * Three separate forms open accounts - public registration, the employee
 * dialog and the client dialog in Configuration - and all three ask the same
 * question, so all three read the answer from here. A limit that lived in one
 * of them would quietly not apply to the other two.
 */
class AccountAge
{
    public const MINIMUM_YEARS = 18;

    /**
     * Past this, the date is not a birthdate somebody could have.
     */
    private const MAXIMUM_YEARS = 120;

    public const TOO_YOUNG_MESSAGE = 'The account holder must be at least 18 years old.';

    public const INVALID_MESSAGE = 'Enter a valid date of birth.';

    /**
     * The latest date of birth that still clears the minimum age - also what
     * the date pickers carry as their `max`, so the browser refuses what the
     * server would refuse anyway.
     */
    public static function latestAllowed(): string
    {
        return CarbonImmutable::today()->subYears(self::MINIMUM_YEARS)->toDateString();
    }

    public static function earliestAllowed(): string
    {
        return CarbonImmutable::today()->subYears(self::MAXIMUM_YEARS)->toDateString();
    }

    /**
     * @param  bool  $required  False when editing an account opened before
     *                          birthdates were collected: it has none on file,
     *                          and demanding one would block every other edit.
     * @return array<int, string>
     */
    public static function rules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'date',
            'before_or_equal:'.self::latestAllowed(),
            'after_or_equal:'.self::earliestAllowed(),
        ];
    }

    /**
     * The wording for those rules, keyed for whichever field carries them.
     *
     * @return array<string, string>
     */
    public static function messages(string $field = 'birthdate'): array
    {
        return [
            $field.'.required' => 'Enter the account holder\'s date of birth.',
            $field.'.date' => self::INVALID_MESSAGE,
            $field.'.before_or_equal' => self::TOO_YOUNG_MESSAGE,
            $field.'.after_or_equal' => self::INVALID_MESSAGE,
        ];
    }
}
