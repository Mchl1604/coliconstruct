<?php

namespace App\Support;

/**
 * The units a material is counted in.
 *
 * A fixed list rather than free text, so "m", "meter" and "mtrs" are one unit
 * and a project's quantities can be added up. Counted units take whole numbers
 * only - half an aircon unit is a typo - while measured ones allow two
 * decimals, because 2.5 m of copper tube is an ordinary amount.
 */
enum MaterialUnit: string
{
    case Unit = 'unit';
    case Piece = 'pc';
    case Set = 'set';
    case Pair = 'pair';
    case Box = 'box';
    case Pack = 'pack';
    case Roll = 'roll';
    case Length = 'length';
    case Sheet = 'sheet';
    case Bag = 'bag';
    case Can = 'can';
    case Tube = 'tube';
    case Tank = 'tank';
    case Meter = 'm';
    case Foot = 'ft';
    case Kilogram = 'kg';
    case Liter = 'L';
    case Gallon = 'gal';

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'Unit',
            self::Piece => 'Piece',
            self::Set => 'Set',
            self::Pair => 'Pair',
            self::Box => 'Box',
            self::Pack => 'Pack',
            self::Roll => 'Roll',
            self::Length => 'Length',
            self::Sheet => 'Sheet',
            self::Bag => 'Bag',
            self::Can => 'Can',
            self::Tube => 'Tube',
            self::Tank => 'Tank',
            self::Meter => 'Meter',
            self::Foot => 'Foot',
            self::Kilogram => 'Kilogram',
            self::Liter => 'Liter',
            self::Gallon => 'Gallon',
        };
    }

    /**
     * The most any one entry or plan line may carry. Well inside the
     * decimal(12,2) columns, and far past any real job.
     */
    public const MAX_QUANTITY = 999999.99;

    /**
     * Whether a quantity in this unit may have a fractional part.
     */
    public function allowsFractions(): bool
    {
        return in_array($this, [self::Meter, self::Foot, self::Kilogram, self::Liter, self::Gallon], true);
    }

    /**
     * What is wrong with a quantity in this unit, or null when nothing is.
     */
    public function quantityProblem(float|int|string|null $quantity): ?string
    {
        if (! is_numeric($quantity) || (float) $quantity <= 0) {
            return 'Enter a quantity above 0.';
        }

        if ((float) $quantity > self::MAX_QUANTITY) {
            return 'That quantity is too large.';
        }

        $hundredths = round((float) $quantity * 100);

        if (abs($hundredths - (float) $quantity * 100) > 0.0001) {
            return 'Use at most 2 decimals.';
        }

        if (! $this->allowsFractions() && fmod($hundredths, 100) !== 0.0) {
            return sprintf('Whole numbers only for %s.', strtolower($this->label()));
        }

        return null;
    }

    /**
     * A quantity as a person reads it: "12", "2.5", "1,200".
     */
    public function format(float|int|string|null $quantity): string
    {
        $value = round((float) $quantity, 2);
        $formatted = number_format($value, 2);

        return str_contains($formatted, '.')
            ? rtrim(rtrim($formatted, '0'), '.')
            : $formatted;
    }

    /**
     * Every unit as value => "Label (value)", for a select.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $unit) {
            $options[$unit->value] = sprintf('%s (%s)', $unit->label(), $unit->value);
        }

        return $options;
    }

    /**
     * The values that take fractions, for the browser's own check.
     *
     * @return array<int, string>
     */
    public static function fractionalValues(): array
    {
        return array_values(array_map(
            fn (self $unit): string => $unit->value,
            array_filter(self::cases(), fn (self $unit): bool => $unit->allowsFractions())
        ));
    }
}
