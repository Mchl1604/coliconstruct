<?php

namespace Database\Factories;

use App\Models\DetailForm;
use App\Models\DetailFormField;
use App\Support\DetailFieldScope;
use App\Support\DetailFieldType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DetailFormField>
 */
class DetailFormFieldFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = ucwords(fake()->unique()->words(2, true));

        return [
            'form_id' => DetailForm::factory(),
            'field_key' => Str::slug($label, '_'),
            'label' => $label,
            'input_type' => DetailFieldType::Text,
            'scope' => DetailFieldScope::Unit,
            'options' => null,
            'is_required' => false,
            'sort_order' => 0,
        ];
    }

    /**
     * Filled once for the whole project rather than once per unit.
     */
    public function forProject(): static
    {
        return $this->state(fn (): array => ['scope' => DetailFieldScope::Project]);
    }

    public function required(): static
    {
        return $this->state(fn (): array => ['is_required' => true]);
    }

    /**
     * @param  array<int, string>  $options
     */
    public function dropdown(array $options = ['LG', 'Daikin', 'Carrier']): static
    {
        return $this->state(fn (): array => [
            'input_type' => DetailFieldType::Dropdown,
            'options' => $options,
        ]);
    }

    public function number(): static
    {
        return $this->state(fn (): array => ['input_type' => DetailFieldType::Number]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
