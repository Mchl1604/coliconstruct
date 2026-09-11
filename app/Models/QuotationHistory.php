<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a project's quotation amount.
 *
 * Written by QuotationChange inside the same transaction as the amount it
 * describes, so a save that rolls back leaves no row claiming it happened.
 */
class QuotationHistory extends Model
{
    /**
     * created_at is set explicitly and there is nothing to update: a change
     * is a fact about a moment, not a record that changes.
     */
    public $timestamps = false;

    protected $table = 'tbl_quotation_history';

    protected $primaryKey = 'quotation_history_id';

    protected $fillable = [
        'project_id',
        'previous_amount',
        'new_amount',
        'file_replaced',
        'actor_id',
        'actor_name',
        'actor_role',
        'created_at',
    ];

    protected $casts = [
        'previous_amount' => 'decimal:2',
        'new_amount' => 'decimal:2',
        'file_replaced' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
