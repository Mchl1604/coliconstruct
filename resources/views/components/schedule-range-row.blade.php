@props([
    'schedule' => null,
    'index' => null,
    'workingHours' => [],
    'partialDayAllowed' => false,
])

@php
    // The <template> copy carries no index: schedule.js names those inputs as
    // it clones them, which is how the page has always added a row.
    $named = $index !== null;
    $field = fn (string $name): string => $named ? "ranges[{$index}][{$name}]" : '';

    $isPartialDay = $schedule?->isPartialDay() ?? false;
    $startsOn = $schedule?->startsOn()?->format('Y-m-d');
    $endsOn = $schedule?->endsOn()?->format('Y-m-d');
    $startTime = $isPartialDay ? $schedule->start_datetime->format('H:i') : null;
    $endTime = $isPartialDay ? ($schedule->end_datetime ?? $schedule->start_datetime)->format('H:i') : null;
@endphp

{{-- Scheduling mode belongs to the individual row, so each one carries its own
     selector and its own pair of field groups. The group that is not in use is
     hidden AND disabled, so the browser neither validates nor submits it. --}}
<div class="schedule-range-row row g-2 align-items-end mb-2" data-range-row
    data-partial-day-allowed="{{ $partialDayAllowed ? '1' : '0' }}">

    @if ($named && $schedule)
        <input type="hidden" name="{{ $field('schedule_id') }}" value="{{ $schedule->schedule_id }}">
    @endif

    @if ($partialDayAllowed)
        <div class="col-12 col-lg-3">
            <label class="form-label small mb-1">Scheduling Mode</label>
            <select class="form-select form-select-sm" @if ($named) name="{{ $field('scheduling_mode') }}" @endif
                data-range-mode>
                <option value="{{ \App\Models\Schedule::MODE_DATE_BASED }}" @selected(! $isPartialDay)>Date-Based
                </option>
                <option value="{{ \App\Models\Schedule::MODE_PARTIAL_DAY }}" @selected($isPartialDay)>Partial Day
                </option>
            </select>
        </div>
    @endif

    <div class="{{ $partialDayAllowed ? 'col-12 col-lg-7' : 'col-10' }}">
        <div class="row g-2">
            <div class="col-6" data-range-date-based @if ($isPartialDay) hidden @endif>
                <label class="form-label small mb-1">Start Date</label>
                <input type="date" class="form-control form-control-sm"
                    @if ($named) name="{{ $field('start_date') }}" @endif
                    value="{{ $isPartialDay ? '' : $startsOn }}" data-range-start
                    @if ($isPartialDay) disabled @else required @endif>
            </div>

            <div class="col-6" data-range-date-based @if ($isPartialDay) hidden @endif>
                <label class="form-label small mb-1">End Date</label>
                <input type="date" class="form-control form-control-sm"
                    @if ($named) name="{{ $field('end_date') }}" @endif
                    value="{{ $isPartialDay ? '' : $endsOn }}" data-range-end
                    @if ($isPartialDay) disabled @else required @endif>
            </div>

            <div class="col-4" data-range-partial-day @unless ($isPartialDay) hidden @endunless>
                <label class="form-label small mb-1">Date</label>
                <input type="date" class="form-control form-control-sm"
                    @if ($named) name="{{ $field('project_date') }}" @endif
                    value="{{ $isPartialDay ? $startsOn : '' }}" data-range-project-date
                    @if ($isPartialDay) required @else disabled @endif>
            </div>

            <div class="col-4" data-range-partial-day @unless ($isPartialDay) hidden @endunless>
                <label class="form-label small mb-1">Start Time</label>
                <select class="form-select form-select-sm"
                    @if ($named) name="{{ $field('start_time') }}" @endif data-range-start-time
                    @if ($isPartialDay) required @else disabled @endif>
                    <option value="">Select</option>
                    @foreach ($workingHours as $hour)
                        <option value="{{ $hour['value'] }}" @selected($startTime === $hour['value'])>
                            {{ $hour['label'] }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-4" data-range-partial-day @unless ($isPartialDay) hidden @endunless>
                <label class="form-label small mb-1">End Time</label>
                <select class="form-select form-select-sm"
                    @if ($named) name="{{ $field('end_time') }}" @endif data-range-end-time
                    @if ($isPartialDay) required @else disabled @endif>
                    <option value="">Select</option>
                    @foreach ($workingHours as $hour)
                        <option value="{{ $hour['value'] }}" @selected($endTime === $hour['value'])>
                            {{ $hour['label'] }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <div class="{{ $partialDayAllowed ? 'col-12 col-lg-2' : 'col-2' }}">
        <button type="button" class="btn btn-sm btn-outline-danger w-100" data-remove-range
            title="Remove schedule">
            <i class="bi bi-trash"></i>
        </button>
    </div>
</div>
