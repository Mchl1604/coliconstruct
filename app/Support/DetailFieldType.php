<?php

namespace App\Support;

/**
 * The kinds of field a detail form can hold.
 *
 * A fixed list, so the wizard knows how to draw each one and the server how
 * to check it. Dropdown and Checkboxes take a list of options; the rest do
 * not.
 */
enum DetailFieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case LongText = 'textarea';
    case Dropdown = 'select';
    case Checkboxes = 'checkboxes';
    case YesNo = 'yes_no';
    case Date = 'date';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Number => 'Number',
            self::LongText => 'Long text',
            self::Dropdown => 'Dropdown',
            self::Checkboxes => 'Checkboxes',
            self::YesNo => 'Yes / No',
            self::Date => 'Date',
        };
    }

    public function hasOptions(): bool
    {
        return $this === self::Dropdown || $this === self::Checkboxes;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
