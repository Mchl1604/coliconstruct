{{-- What every calendar and date picker reads: the first day this account may
     book, and the non-working days. Before datePicker.js, which marks them in
     every picker. The server asks both again when a booking is saved. --}}
<script>
    window.bookingCalendar = {
        earliest: @json(\App\Models\Schedule::earliestBookable(auth()->user())->toDateString()),
        holidays: @json((object) app(\App\Services\HolidayCalendar::class)->all()),
        dayKey: function (date) {
            return date.getFullYear() + "-"
                + String(date.getMonth() + 1).padStart(2, "0") + "-"
                + String(date.getDate()).padStart(2, "0");
        },
        // The day's name when nobody works it, otherwise null.
        nameOf: function (date) {
            return this.holidays[this.dayKey(date)] || null;
        },
        // For a flatpickr `disable` list. A date the field already holds stays,
        // or a booking saved before the day was added would lose its date the
        // moment the picker opened on it.
        blocks: function (input) {
            const calendar = this;

            return function (date) {
                return calendar.nameOf(date) !== null && calendar.dayKey(date) !== input.value;
            };
        },
    };
</script>
