<?php

namespace App\Models;

use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A holiday or other non-working day. See HolidayCalendar.
 */
class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use HasFactory;

    protected $table = 'tbl_holidays';

    protected $primaryKey = 'holiday_id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'holiday_date',
        'name',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
        ];
    }
}
