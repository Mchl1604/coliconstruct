@props(['report'])

{{-- Whether the office has resolved an incident, beside its type wherever a
     report is listed. Nothing for a progress report. --}}
@if ($review = $report->reviewStatus())
    <span {{ $attributes->merge(['class' => 'badge '.$review['badge']]) }}>{{ $review['label'] }}</span>
@endif
