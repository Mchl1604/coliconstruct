<?php

namespace App\Support;

/**
 * Whether a detail form field is filled once for the project or once for
 * every unit on it - the trouble description against each aircon's brand.
 */
enum DetailFieldScope: string
{
    case Project = 'project';
    case Unit = 'unit';

    public function label(): string
    {
        return match ($this) {
            self::Project => 'Project',
            self::Unit => 'Per unit',
        };
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
