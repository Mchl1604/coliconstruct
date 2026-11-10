<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Holiday;
use App\Services\ActivityLogger;
use App\Services\HolidayCalendar;
use App\Support\BusinessTime;
use App\Support\ServerTable;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Configuration -> Project Settings: the days bookings skip. Super Admin only, on the
 * route. What they do to a booking is HolidayCalendar's.
 */
class HolidayController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly HolidayCalendar $holidays,
    ) {}

    /**
     * One page of the list, for serverTable.js.
     */
    public function index(Request $request): JsonResponse
    {
        $table = ServerTable::fromRequest($request);

        $page = $table->paginate(
            Holiday::query(),
            [0 => 'holiday_date', 1 => 'name'],
            fn (Builder $builder, string $word, string $like) => $builder->where('name', 'like', $like),
            [[0, 'asc']],
        );

        return $table->respond(
            $page,
            fn (Holiday $holiday): string => view('super-admin.partials.holiday-row', ['holiday' => $holiday])->render(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'holiday_date' => ['required', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail): void {
                if (Holiday::query()->whereDate('holiday_date', $value)->exists()) {
                    $fail('That date is already listed.');
                }
            }],
            'name' => ['required', 'string', 'max:100'],
        ], [
            'holiday_date.required' => 'Choose a date.',
            'holiday_date.date_format' => 'Choose a date.',
            'name.required' => 'Enter a name.',
            'name.max' => 'Name is too long.',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();

        [$holiday, $split] = DB::transaction(function () use ($data, $request): array {
            $holiday = Holiday::create([
                'holiday_date' => $data['holiday_date'],
                'name' => trim($data['name']),
                'created_by' => $request->user()?->id,
            ]);

            return [$holiday, $this->holidays->splitBookingsOn(CarbonImmutable::parse($data['holiday_date']))];
        });

        $this->activityLogger->record(ActivityLog::HOLIDAY_ADDED, null, sprintf(
            "Added non-working day '%s' on %s%s.",
            $holiday->name,
            $holiday->holiday_date->format(BusinessTime::DATE),
            $split > 0 ? sprintf(' and split %d %s around it', $split, $split === 1 ? 'booking' : 'bookings') : ''
        ));

        return response()->json([
            'message' => $split > 0
                ? sprintf('%s added. %d %s split.', $holiday->name, $split, $split === 1 ? 'booking' : 'bookings')
                : $holiday->name.' added.',
        ]);
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $holiday->delete();

        $this->activityLogger->record(ActivityLog::HOLIDAY_REMOVED, null, sprintf(
            "Removed non-working day '%s' on %s.",
            $holiday->name,
            $holiday->holiday_date->format(BusinessTime::DATE)
        ));

        return response()->json(['message' => $holiday->name.' removed.']);
    }
}
