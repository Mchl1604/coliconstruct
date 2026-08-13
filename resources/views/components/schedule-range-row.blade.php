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
    // Stored and submitted as Y-m-d, which is what the server reads and what
    // every date comparison in schedule.js is written against. What a person
    // sees is flatpickr's alt input, which says "Aug 25, 2026".
    $startsOn = $schedule?->startsOn()?->format('Y-m-d');
    $endsOn = $schedule?->endsOn()?->format('Y-m-d');
    $startTime = $isPartialDay ? $schedule->start_datetime->format('H:i') : null;
    $endTime = $isPartialDay ? ($schedule->end_datetime ?? $schedule->start_datetime)->format('H:i') : null;
@endphp

{{-- Scheduling mode belongs to the individual row, so each one carries its own
     selector and its own pair of field groups. The group that is not in use is
     hidden AND disabled, so the browser neither validates nor submits it. --}}
<div class="schedule-range-row" data-range-row
    data-partial-day-allowed="{{ $partialDayAllowed ? '1' : '0' }}">

    @if ($named && $schedule)
        <input type="hidden" name="{{ $field('schedule_id') }}" value="{{ $schedule->schedule_id }}">
    @endif

    <div class="schedule-range-head">
        {{-- Numbered by CSS counter rather than by index, so a row added after
             a removal still reads in order without any renumbering. --}}
        <span class="schedule-range-index"></span>

        <div class="schedule-range-head-actions">
            @if ($partialDayAllowed)
                <label class="visually-hidden">Scheduling mode</label>
                <select class="form-select form-select-sm schedule-range-mode"
                    @if ($named) name="{{ $field('scheduling_mode') }}" @endif data-range-mode>
                    <option value="{{ \App\Models\Schedule::MODE_DATE_BASED }}" @selected(! $isPartialDay)>
                        Date-Based
                    </option>
                    <option value="{{ \App\Models\Schedule::MODE_PARTIAL_DAY }}" @selected($isPartialDay)>
                        Partial Day
                    </option>
                </select>
            @endif

            <button type="button" class="schedule-range-remove" data-remove-range
                title="Remove this schedule" aria-label="Remove this schedule">
                <i class="bi bi-trash3" aria-hidden="true"></i>
            </button>
        </div>
    </div>

    <div class="schedule-range-fields">
        <div class="schedule-range-field" data-range-date-based @if ($isPartialDay) hidden @endif>
            <label class="schedule-range-label">Start date</label>
            <input type="text" class="form-control schedule-range-input"
                @if ($named) name="{{ $field('start_date') }}" @endif
                value="{{ $isPartialDay ? '' : $startsOn }}" data-range-start
                placeholder="Select start date"
                @if ($isPartialDay) disabled @else required @endif>
        </div>

        <div class="schedule-range-arrow" data-range-date-based @if ($isPartialDay) hidden @endif
            aria-hidden="true">
            <i class="bi bi-arrow-right"></i>
        </div>

        <div class="schedule-range-field" data-range-date-based @if ($isPartialDay) hidden @endif>
            <label class="schedule-range-label">End date</label>
            <input type="text" class="form-control schedule-range-input"
                @if ($named) name="{{ $field('end_date') }}" @endif
                value="{{ $isPartialDay ? '' : $endsOn }}" data-range-end
                placeholder="Select end date"
                @if ($isPartialDay) disabled @else required @endif>
        </div>

        <div class="schedule-range-field" data-range-partial-day @unless ($isPartialDay) hidden @endunless>
            <label class="schedule-range-label">Date</label>
            <input type="text" class="form-control schedule-range-input"
                @if ($named) name="{{ $field('project_date') }}" @endif
                value="{{ $isPartialDay ? $startsOn : '' }}" data-range-project-date
                placeholder="Select date"
                @if ($isPartialDay) required @else disabled @endif>
        </div>

        <div class="schedule-range-field" data-range-partial-day @unless ($isPartialDay) hidden @endunless>
            <label class="schedule-range-label">Start time</label>
            <select class="form-select schedule-range-input"
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

        <div class="schedule-range-field" data-range-partial-day @unless ($isPartialDay) hidden @endunless>
            <label class="schedule-range-label">End time</label>
            <select class="form-select schedule-range-input"
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
