@props([
    'schedule' => null,
    'index' => null,
    'workingHours' => [],
    'partialDayAllowed' => false,
    'mayOverrideLock' => false,
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

    // How much of this booking may still change. Asked of the same service the
    // save is validated by, so a field this row leaves open is a field the
    // server will accept - see ScheduleModeRules::editabilityOf().
    $lockState = $schedule?->lockState() ?? \App\Models\Schedule::LOCK_FUTURE;
    $rules = app(\App\Services\ScheduleModeRules::class);
    // A row with no saved booking behind it is a new one, and a new booking
    // cannot be promised for a day that has gone - so its floor is today at
    // both ends rather than no floor at all, which is what used to leave last
    // month clickable in the pickers.
    $limits = $schedule
        ? $rules->editabilityOf($schedule, (bool) $mayOverrideLock)
        : $rules->limitsForNewRange();

    // A booking that has ended and that this reader may not correct: drawn as
    // the record it is, with nothing on it to fill in.
    $isReadOnly = ! $limits['editable'];
    $startFrozen = $isReadOnly || $limits['startFrozen'];
    // Handed to the date pickers as their minimum, so an unacceptable date
    // cannot be picked in the first place.
    $earliestStart = $limits['earliestStart']?->format('Y-m-d');
    $earliestEnd = $limits['earliestEnd']?->format('Y-m-d');

    // A locked row gets no date picker - there is nothing on it to pick - and
    // with no picker there is no alt input turning 2026-08-03 into Aug 3, 2026.
    // So it is formatted here instead, in flatpickr's own altFormat, and the
    // machine value is not missed: a locked row submits nothing at all.
    $display = fn (?string $value): ?string => $value && $isReadOnly
        ? \Carbon\CarbonImmutable::parse($value)->format('M j, Y')
        : $value;
@endphp

{{-- Scheduling mode belongs to the individual row, so each one carries its own
     selector and its own pair of field groups. The group that is not in use is
     hidden AND disabled, so the browser neither validates nor submits it.

     A row also carries where it sits relative to today. A booking that has
     ended is the project's record of work that happened and is drawn read-only;
     one that is under way has its start frozen, because those days are worked.
     See Schedule::lockState(). --}}
<div class="schedule-range-row schedule-range-{{ $lockState }} {{ $isReadOnly ? 'is-locked' : '' }}"
    data-range-row
    data-lock-state="{{ $lockState }}"
    {{-- Carried on the row as well as in the hidden field, so a row can still
         be found by id after its hidden field has gone - which is how a locked
         row that was deleted is told apart from one merely edited. --}}
    data-schedule-id="{{ $schedule?->schedule_id }}"
    data-locked="{{ $isReadOnly ? '1' : '0' }}"
    data-start-frozen="{{ $startFrozen ? '1' : '0' }}"
    data-earliest-start="{{ $earliestStart }}"
    data-earliest-end="{{ $earliestEnd }}"
    data-partial-day-allowed="{{ $partialDayAllowed ? '1' : '0' }}">

    {{-- A locked row submits nothing at all - not even its id. Its fields are
         disabled, so an id alone would reach the server as a range with no
         dates on it; and the server keeps a booking that has ended whether or
         not the form mentions it, which is what makes leaving it out safe. --}}
    @if ($named && $schedule && ! $isReadOnly)
        <input type="hidden" name="{{ $field('schedule_id') }}" value="{{ $schedule->schedule_id }}">
    @endif

    <div class="schedule-range-head">
        {{-- Numbered by CSS counter rather than by index, so a row added after
             a removal still reads in order without any renumbering. --}}
        <span class="schedule-range-index"></span>

        {{-- Which of the three this row is, said on the row rather than left
             to be inferred from the dates. --}}
        @if ($schedule && $lockState !== \App\Models\Schedule::LOCK_FUTURE)
            <span class="schedule-range-flag schedule-range-flag-{{ $lockState }}">
                <i class="bi {{ $lockState === \App\Models\Schedule::LOCK_LOCKED ? 'bi-lock-fill' : 'bi-play-fill' }}"
                    aria-hidden="true"></i>
                {{ $lockState === \App\Models\Schedule::LOCK_LOCKED ? 'Ended' : 'In progress' }}
            </span>
        @endif

        <div class="schedule-range-head-actions">
            @if ($partialDayAllowed)
                <label class="visually-hidden">Scheduling mode</label>
                <select class="form-select form-select-sm schedule-range-mode"
                    @if ($named) name="{{ $field('scheduling_mode') }}" @endif data-range-mode
                    @disabled($isReadOnly)>
                    <option value="{{ \App\Models\Schedule::MODE_DATE_BASED }}" @selected(! $isPartialDay)>
                        Date-Based
                    </option>
                    <option value="{{ \App\Models\Schedule::MODE_PARTIAL_DAY }}" @selected($isPartialDay)>
                        Partial Day
                    </option>
                </select>
            @endif

            {{-- A booking that has ended is not deleted by dropping it out of
                 the form: the server keeps it whatever this page submits. The
                 control is absent rather than disabled so nothing invites a
                 click that cannot work. --}}
            @unless ($isReadOnly)
                <button type="button" class="schedule-range-remove" data-remove-range
                    title="Remove this schedule" aria-label="Remove this schedule">
                    <i class="bi bi-trash3" aria-hidden="true"></i>
                </button>
            @endunless
        </div>
    </div>

    @if ($isReadOnly)
        <p class="schedule-range-locked-note">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            This date range has already ended. Super Admin access is required to make changes.
        </p>
    @elseif ($startFrozen && $schedule)
        <p class="schedule-range-locked-note">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            This schedule is under way. Its start date is fixed; the end date can still be moved.
        </p>
    @endif

    <div class="schedule-range-fields">
        <div class="schedule-range-field" data-range-date-based @if ($isPartialDay) hidden @endif>
            <label class="schedule-range-label">Start date</label>
            <input type="text" class="form-control schedule-range-input"
                @if ($named) name="{{ $field('start_date') }}" @endif
                value="{{ $isPartialDay ? '' : $display($startsOn) }}" data-range-start
                placeholder="Select start date"
                {{-- Readonly rather than disabled where the start is merely
                     frozen: the server compares the submitted start against the
                     stored one, and a disabled field submits nothing. --}}
                @if ($isPartialDay || $isReadOnly) disabled @else required @endif
                @readonly($startFrozen)>
        </div>

        <div class="schedule-range-arrow" data-range-date-based @if ($isPartialDay) hidden @endif
            aria-hidden="true">
            <i class="bi bi-arrow-right"></i>
        </div>

        <div class="schedule-range-field" data-range-date-based @if ($isPartialDay) hidden @endif>
            <label class="schedule-range-label">End date</label>
            <input type="text" class="form-control schedule-range-input"
                @if ($named) name="{{ $field('end_date') }}" @endif
                value="{{ $isPartialDay ? '' : $display($endsOn) }}" data-range-end
                placeholder="Select end date"
                @if ($isPartialDay || $isReadOnly) disabled @else required @endif>
        </div>

        <div class="schedule-range-field" data-range-partial-day @unless ($isPartialDay) hidden @endunless>
            <label class="schedule-range-label">Date</label>
            <input type="text" class="form-control schedule-range-input"
                @if ($named) name="{{ $field('project_date') }}" @endif
                value="{{ $isPartialDay ? $display($startsOn) : '' }}" data-range-project-date
                placeholder="Select date"
                @if ($isPartialDay && ! $isReadOnly) required @else disabled @endif
                @readonly($startFrozen)>
        </div>

        <div class="schedule-range-field" data-range-partial-day @unless ($isPartialDay) hidden @endunless>
            <label class="schedule-range-label">Start time</label>
            <select class="form-select schedule-range-input"
                @if ($named) name="{{ $field('start_time') }}" @endif data-range-start-time
                @if ($isPartialDay && ! $isReadOnly) required @else disabled @endif>
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
                @if ($isPartialDay && ! $isReadOnly) required @else disabled @endif>
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
