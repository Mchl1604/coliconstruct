{{-- One row of Project Settings' holidays, rendered by HolidayController::index()
     for serverTable.js. --}}
<tr>
    <td class="text-nowrap">{{ $holiday->holiday_date->format(\App\Support\BusinessTime::DATE) }}</td>
    <td>{{ $holiday->name }}</td>
    <td class="text-center">
        <button type="button" class="btn btn-sm btn-outline-danger py-1 px-2" title="Remove"
            data-holiday-remove="{{ $holiday->holiday_id }}" data-holiday-name="{{ $holiday->name }}">
            <i class="bi bi-trash" aria-hidden="true"></i>
        </button>
    </td>
</tr>
