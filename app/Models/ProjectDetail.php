<?php

namespace App\Models;

use App\Support\DetailFieldScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One filled-in part of a detail form on a project: the type's
 * once-per-project fields (scope "project"), or one unit (scope "unit").
 *
 * Reads on its own: labels holds the form's labels as they were when it was
 * saved, so a field renamed or archived later still shows here as it was.
 */
class ProjectDetail extends Model
{
    protected $table = 'tbl_project_details';

    protected $primaryKey = 'detail_id';

    protected $fillable = [
        'project_id',
        'type_id',
        'form_id',
        'scope',
        'values',
        'labels',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'scope' => DetailFieldScope::class,
            'values' => 'array',
        ];
    }

    /**
     * The field labels as saved, keyed by field_key in form order.
     *
     * Stored as a list of [key, label] pairs rather than a JSON object,
     * because MySQL's JSON type re-sorts an object's keys - and the order is
     * the form's order, which is how the details read.
     */
    protected function labels(): Attribute
    {
        return Attribute::make(
            get: function (?string $stored): array {
                $decoded = json_decode((string) $stored, true);

                if (! is_array($decoded)) {
                    return [];
                }

                if (! array_is_list($decoded)) {
                    return $decoded;
                }

                $labels = [];

                foreach ($decoded as $pair) {
                    if (is_array($pair) && count($pair) === 2) {
                        $labels[(string) $pair[0]] = (string) $pair[1];
                    }
                }

                return $labels;
            },
            set: fn (array $labels): string => json_encode(
                array_map(fn ($key, $label): array => [(string) $key, (string) $label], array_keys($labels), $labels)
            ),
        );
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function projectType(): BelongsTo
    {
        return $this->belongsTo(ProjectType::class, 'type_id', 'type_id');
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(DetailForm::class, 'form_id', 'form_id');
    }

    /**
     * The filled-in fields as label and display value, in the order the form
     * had them when saved. Blank fields are left out.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function readableValues(): array
    {
        $values = (array) $this->values;
        $rows = [];

        foreach ((array) $this->labels as $key => $label) {
            $value = $values[$key] ?? null;

            if (is_array($value)) {
                $value = implode(', ', array_filter($value, fn ($item): bool => is_string($item) && $item !== ''));
            }

            if ($value === null || $value === '') {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'Yes' : 'No';
            }

            $rows[] = ['label' => (string) $label, 'value' => (string) $value];
        }

        return $rows;
    }
}
