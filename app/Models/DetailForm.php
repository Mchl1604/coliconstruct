<?php

namespace App\Models;

use App\Support\DetailFieldScope;
use Database\Factories\DetailFormFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A detail form: the fields a project type asks for when a project is created,
 * which together are the project's description. Some are filled once for the
 * project and some once per unit - see DetailFieldScope.
 *
 * Built and edited by the Super Admin only - see DetailFormCatalog, the one
 * place that writes these.
 */
class DetailForm extends Model
{
    /** @use HasFactory<DetailFormFactory> */
    use HasFactory;

    protected $table = 'tbl_detail_forms';

    protected $primaryKey = 'form_id';

    protected $fillable = [
        'name',
    ];

    /**
     * Every field, archived ones included, in order.
     */
    public function fields(): HasMany
    {
        return $this->hasMany(DetailFormField::class, 'form_id', 'form_id')
            ->orderBy('sort_order')
            ->orderBy('field_id');
    }

    /**
     * The fields a new or edited entry is filled in with.
     */
    public function activeFields(): HasMany
    {
        return $this->fields()->whereNull('archived_at');
    }

    /**
     * @return Collection<int, DetailFormField>
     */
    public function projectFields(): Collection
    {
        return $this->activeFields->filter(fn (DetailFormField $field): bool => $field->scope === DetailFieldScope::Project)->values();
    }

    /**
     * @return Collection<int, DetailFormField>
     */
    public function unitFields(): Collection
    {
        return $this->activeFields->filter(fn (DetailFormField $field): bool => $field->scope === DetailFieldScope::Unit)->values();
    }

    /**
     * Whether the form asks for a list of units at all.
     */
    public function hasUnits(): bool
    {
        return $this->unitFields()->isNotEmpty();
    }

    public function projectTypes(): HasMany
    {
        return $this->hasMany(ProjectType::class, 'form_id', 'form_id');
    }

    public function projectDetails(): HasMany
    {
        return $this->hasMany(ProjectDetail::class, 'form_id', 'form_id');
    }
}
