<?php

namespace App\Models;

use App\Support\DetailFieldScope;
use App\Support\DetailFieldType;
use Database\Factories\DetailFormFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field of a detail form. field_key never changes once the field exists,
 * because saved project details are keyed on it.
 */
class DetailFormField extends Model
{
    /** @use HasFactory<DetailFormFieldFactory> */
    use HasFactory;

    protected $table = 'tbl_detail_form_fields';

    protected $primaryKey = 'field_id';

    protected $fillable = [
        'form_id',
        'field_key',
        'label',
        'input_type',
        'scope',
        'options',
        'is_required',
        'sort_order',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'input_type' => DetailFieldType::class,
            'scope' => DetailFieldScope::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(DetailForm::class, 'form_id', 'form_id');
    }

    /**
     * @return array<int, string>
     */
    public function optionList(): array
    {
        return array_values(array_filter((array) $this->options, fn ($option): bool => is_string($option) && $option !== ''));
    }

    /**
     * @return array{field_id: int, field_key: string, label: string, input_type: string, scope: string, options: array<int, string>, is_required: bool}
     */
    public function toBuilderArray(): array
    {
        return [
            'field_id' => $this->field_id,
            'field_key' => $this->field_key,
            'label' => $this->label,
            'input_type' => $this->input_type->value,
            'scope' => $this->scope->value,
            'options' => $this->optionList(),
            'is_required' => $this->is_required,
        ];
    }
}
