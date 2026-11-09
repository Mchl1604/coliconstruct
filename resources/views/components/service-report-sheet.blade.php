{{--
    The company's paper Service Report: same order, boxes and tick boxes, with
    only the sections this project's types call for. Read from ServiceReport.
    A blank box prints empty, for writing in on site.
--}}
@props(['project'])

@php
    use App\Support\ServiceReportForms as Forms;

    $report = app(\App\Services\ServiceReport::class)->for($project);
    $header = $report['header'];
    $sections = $report['sections'];
    $checkUps = $sections['check_up']['units'] ?? [];
    $repairs = $sections['repair']['units'] ?? [];
    $hasBoth = $checkUps !== [] && $repairs !== [];

    $tick = fn (string $label, bool $on = false): \Illuminate\Support\HtmlString => new \Illuminate\Support\HtmlString(
        '<span class="sr-tick"><span class="sr-box">'.($on ? '&#10003;' : '').'</span>'.e($label).'</span>'
    );
    $has = fn (array $unit, string $key, string $option): bool => in_array($option, (array) ($unit[$key] ?? []), true);
    // An old unit holds one "Aircon type" value such as "Cassette".
    $isAirconType = fn (array $unit, string $key, string $option): bool => $has($unit, $key, $option)
        || (filled($unit['aircon_type'] ?? null) && str_starts_with($option, (string) $unit['aircon_type']));
    $checklist = fn (array $unit, array $items): \Illuminate\Support\HtmlString => new \Illuminate\Support\HtmlString(
        collect($items)->map(fn (string $label, string $key): string => (string) $tick(mb_strtoupper($label), in_array($key, (array) ($unit['_checklist'] ?? []), true)))->implode('')
    );
    $text = fn (array $unit, string $key): string => is_scalar($unit[$key] ?? null) ? (string) $unit[$key] : '';
@endphp

<div class="sr-sheet" data-service-report-sheet>
    <x-sheet-letterhead />

    <div class="sr-title">SERVICE REPORT</div>

    <table class="sr-table sr-header">
        <colgroup>
            <col style="width: 52%">
            <col style="width: 24%">
            <col style="width: 24%">
        </colgroup>
        <tr>
            <td><span class="sr-label">CLIENT NAME:</span> {{ $header['client_name'] }}</td>
            <td><span class="sr-label">PROJECT REF NO:</span> {{ $header['reference_no'] }}</td>
            <td><span class="sr-label">DATE:</span> {{ $header['date'] }}</td>
        </tr>
        <tr>
            <td><span class="sr-label">ADDRESS:</span> {{ $header['address'] }}</td>
            <td><span class="sr-label">CONTACT PERSON:</span> {{ $header['contact_person'] }}</td>
            <td><span class="sr-label">CONTACT NO:</span> {{ $header['contact_no'] }}</td>
        </tr>
    </table>

    @foreach (range(0, max(count($checkUps), count($repairs), 1) - 1) as $row)
        @if ($checkUps !== [] || $repairs !== [])
            <div class="sr-equipment-row {{ $hasBoth ? 'is-pair' : '' }} sr-keep">
                @foreach ([['title' => 'EQUIPMENT CHECK-UP:', 'units' => $checkUps, 'last' => 'findings', 'lastLabel' => 'FINDINGS:'], ['title' => 'EQUIPMENT REPAIR:', 'units' => $repairs, 'last' => 'remarks', 'lastLabel' => 'REMARKS:']] as $block)
                    @if ($block['units'] !== [])
                        @php $unit = $block['units'][$row] ?? null; @endphp
                        <div class="sr-equipment">
                            @if ($unit !== null)
                                <div class="sr-equipment-title">{{ $tick($block['title'], true) }}</div>
                                <table class="sr-table">
                                    <tr>
                                        <td colspan="2" class="sr-types">
                                            <span class="sr-label">TYPE OF EQUIPMENT:</span>
                                            <span class="sr-label sr-sub">AIRCONDITIONER</span>
                                            <div class="sr-grid sr-grid-4">
                                            @foreach (Forms::EQUIPMENT_TYPES as $option)
                                                {{ $tick(mb_strtoupper($option), $has($unit, 'equipment_type', $option)) }}
                                            @endforeach
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><span class="sr-label">BRAND:</span> {{ $text($unit, 'brand') }}</td>
                                        <td><span class="sr-label">AREA SERVE:</span> {{ $text($unit, 'area_served') }}</td>
                                    </tr>
                                    <tr>
                                        <td><span class="sr-label">MODEL:</span> {{ $text($unit, 'model') }}</td>
                                        <td><span class="sr-label">SERIAL NO:</span> {{ $text($unit, 'serial_no') }}</td>
                                    </tr>
                                    <tr>
                                        <td colspan="2" class="sr-tall"><span class="sr-label">TROUBLE DESCRIPTION:</span> {!! nl2br(e($text($unit, 'trouble_description'))) !!}</td>
                                    </tr>
                                    <tr>
                                        <td colspan="2" class="sr-tall"><span class="sr-label">{{ $block['lastLabel'] }}</span> {!! nl2br(e($text($unit, $block['last']))) !!}</td>
                                    </tr>
                                </table>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    @endforeach

    @foreach ($sections['cleaning']['units'] ?? [] as $unit)
        <div class="sr-divider"></div>
        <div class="sr-keep">
            <table class="sr-table sr-aircon-top">
                <colgroup>
                    <col style="width: 25%">
                    <col style="width: 15%">
                    <col style="width: 15%">
                    <col style="width: 45%">
                </colgroup>
                <tr>
                    <td class="sr-section-name">{{ $tick('AIR-CON GENERAL CLEANING:', true) }}</td>
                    <td><span class="sr-label">AREA SERVE:</span><br>{{ $text($unit, 'area_served') }}</td>
                    <td><span class="sr-label">EQUIP. TAGGING:</span><br>{{ $text($unit, 'equipment_tagging') }}</td>
                    <td>
                        <span class="sr-label">BRANDS:</span>
                        <div class="sr-grid sr-grid-4">
                            @foreach (Forms::BRANDS as $option)
                                {{ $tick(mb_strtoupper($option), $has($unit, 'brand', $option)) }}
                            @endforeach
                        </div>
                    </td>
                </tr>
            </table>
            <table class="sr-table sr-joined">
                <colgroup>
                    <col style="width: 28%">
                    <col style="width: 26%">
                    <col style="width: 26%">
                    <col style="width: 20%">
                </colgroup>
                <tr>
                    <td>
                        <span class="sr-label">TYPE OF AIRCON</span>
                        @foreach (Forms::SYSTEM_TYPES as $option)
                            {{ $tick(mb_strtoupper($option), $isAirconType($unit, 'system_type', $option)) }}
                        @endforeach
                        <div class="sr-grid sr-grid-2">
                            @foreach (Forms::MOUNTING_TYPES as $option)
                                {{ $tick(mb_strtoupper($option), $isAirconType($unit, 'mounting_type', $option)) }}
                            @endforeach
                        </div>
                    </td>
                    <td>
                        <span class="sr-label">INDOOR UNIT CHECKLIST</span>
                        <div class="sr-grid sr-grid-2">{{ $checklist($unit, Forms::INDOOR_CHECKLIST) }}</div>
                    </td>
                    <td>
                        <span class="sr-label">OUTDOOR UNIT CHECKLIST</span>
                        <div class="sr-grid sr-grid-2">{{ $checklist($unit, Forms::OUTDOOR_CHECKLIST) }}</div>
                    </td>
                    <td><span class="sr-label">REMARKS:</span> {!! nl2br(e($text($unit, 'remarks'))) !!}</td>
                </tr>
            </table>
        </div>
    @endforeach

    @foreach ($sections['installation']['units'] ?? [] as $unit)
        <div class="sr-divider"></div>
        <div class="sr-keep">
            <table class="sr-table sr-aircon-top">
                <colgroup>
                    <col style="width: 25%">
                    <col style="width: 15%">
                    <col style="width: 15%">
                    <col style="width: 45%">
                </colgroup>
                <tr>
                    <td class="sr-section-name">
                        <div class="sr-label">START UP DATA</div>
                        <div>{{ $tick('AIR-CON INSTALLATION', $has($unit, 'work', 'Installation')) }}</div>
                        <div>{{ $tick('AIR-CON RELOCATION', $has($unit, 'work', 'Relocation')) }}</div>
                    </td>
                    <td><span class="sr-label">AREA SERVE:</span><br>{{ $text($unit, 'area_served') }}</td>
                    <td><span class="sr-label">EQUIP. TAGGING:</span><br>{{ $text($unit, 'equipment_tagging') }}</td>
                    <td>
                        <span class="sr-label">BRANDS:</span>
                        <div class="sr-grid sr-grid-4">
                            @foreach (Forms::BRANDS as $option)
                                {{ $tick(mb_strtoupper($option), $has($unit, 'brand', $option)) }}
                            @endforeach
                        </div>
                    </td>
                </tr>
            </table>
            <table class="sr-table sr-joined">
                <colgroup>
                    <col style="width: 36%">
                    <col style="width: 32%">
                    <col style="width: 32%">
                </colgroup>
                <tr>
                    <td>
                        <span class="sr-label">TYPE OF AIRCON</span>
                        @foreach (Forms::SYSTEM_TYPES as $option)
                            {{ $tick(mb_strtoupper($option), $isAirconType($unit, 'system_type', $option)) }}
                        @endforeach
                        <div class="sr-grid sr-grid-2">
                            @foreach (Forms::MOUNTING_TYPES as $option)
                                {{ $tick(mb_strtoupper($option), $isAirconType($unit, 'mounting_type', $option)) }}
                            @endforeach
                        </div>
                    </td>
                    <td>
                        <span class="sr-label">INDOOR UNIT CHECKLIST</span>
                        <div class="sr-grid sr-grid-2">{{ $checklist($unit, Forms::INDOOR_CHECKLIST) }}</div>
                    </td>
                    <td>
                        <span class="sr-label">OUTDOOR UNIT CHECKLIST</span>
                        <div class="sr-grid sr-grid-2">{{ $checklist($unit, Forms::OUTDOOR_CHECKLIST) }}</div>
                    </td>
                </tr>
            </table>
            <table class="sr-table sr-joined">
                <colgroup>
                    <col style="width: 20%">
                    <col style="width: 80%">
                </colgroup>
                <tr>
                    <td class="sr-tall"><span class="sr-label">MODEL:</span><br>{{ $text($unit, 'model') }}</td>
                    <td rowspan="3" class="sr-materials">
                        <div class="sr-label">MATERIAL PROFILE</div>
                        <div class="sr-material-groups">
                            @foreach ([
                                ['COPPER PIPE SIZES:', 'copper_pipe_sizes', Forms::COPPER_PIPE_SIZES, 3],
                                ['PIPE INSULATION SIZE THK', 'pipe_insulation_size', Forms::PIPE_INSULATION_SIZES, 3],
                                ['COMM. LINE WIRE SIZE', 'comm_line_wire_size', Forms::COMM_LINE_WIRE_SIZES, 2],
                                ['SUPPLY LOAD (L1,L2,L3)', 'supply_load', Forms::SUPPLY_LOADS, 2],
                                ['GROUND', 'ground', Forms::GROUNDS, 1],
                                ['BREAKER', 'breaker', Forms::BREAKERS, 1],
                            ] as [$groupLabel, $groupKey, $groupOptions, $groupColumns])
                                <div class="sr-material-group">
                                    <div class="sr-label">{{ $groupLabel }}</div>
                                    <div class="sr-grid sr-grid-{{ $groupColumns }}">
                                        @foreach ($groupOptions as $option)
                                            {{ $tick($option, $has($unit, $groupKey, $option)) }}
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </td>
                </tr>
                <tr>
                    <td class="sr-tall"><span class="sr-label">INDOOR SERIAL NO:</span><br>{{ $text($unit, 'indoor_serial_no') }}</td>
                </tr>
                <tr>
                    <td class="sr-tall"><span class="sr-label">OUTDOOR SERIAL NO:</span><br>{{ $text($unit, 'outdoor_serial_no') }}</td>
                </tr>
            </table>
        </div>
    @endforeach
    @foreach ([['DUCTING FABRICATION:', 'ducting_fabrication'], ['DUCTING INSTALLATION:', 'ducting_installation']] as [$ductingTitle, $ductingSection])
        @foreach ($sections[$ductingSection]['units'] ?? [] as $unit)
            <div class="sr-divider"></div>
            <div class="sr-keep">
                <table class="sr-table sr-aircon-top">
                    <colgroup>
                        <col style="width: 25%">
                        <col style="width: 25%">
                        <col style="width: 25%">
                        <col style="width: 25%">
                    </colgroup>
                    <tr>
                        <td class="sr-section-name">{{ $tick($ductingTitle, true) }}</td>
                        <td><span class="sr-label">AREA SERVE:</span><br>{{ $text($unit, 'area_served') }}</td>
                        <td><span class="sr-label">DUCT SIZE:</span><br>{{ $text($unit, 'duct_size') }}</td>
                        <td><span class="sr-label">LENGTH (M):</span><br>{{ $text($unit, 'length_m') }}</td>
                    </tr>
                </table>
                <table class="sr-table sr-joined">
                    <colgroup>
                        <col style="width: 75%">
                        <col style="width: 25%">
                    </colgroup>
                    <tr>
                        <td>
                            <span class="sr-label">DUCT MATERIAL</span>
                            <div class="sr-grid sr-grid-4">
                                @foreach (Forms::DUCT_MATERIALS as $option)
                                    {{ $tick(mb_strtoupper($option), $has($unit, 'duct_material', $option)) }}
                                @endforeach
                            </div>
                        </td>
                        <td>
                            <span class="sr-label">INSULATED</span>
                            <div class="sr-grid sr-grid-2">
                                {{ $tick('YES', $has($unit, 'insulated', 'Yes')) }}
                                {{ $tick('NO', $has($unit, 'insulated', 'No')) }}
                            </div>
                        </td>
                    </tr>
                </table>
                <table class="sr-table sr-joined">
                    <tr>
                        <td class="sr-tall"><span class="sr-label">SCOPE OF WORK:</span> {!! nl2br(e($text($unit, 'scope_of_work'))) !!}</td>
                    </tr>
                </table>
            </div>
        @endforeach
    @endforeach
    <div class="sr-divider"></div>
    <table class="sr-table sr-signatories sr-keep">
        <colgroup>
            <col style="width: 32%">
            <col style="width: 68%">
        </colgroup>
        <tr>
            <td>
                <div class="sr-label sr-signatory-label">PERFORMED BY:</div>
                <div class="sr-signatory-name">{{ $project->report_performed_by }}</div>
                <div class="sr-signatory-role">AC TECH / SERVICE ENGINEER</div>
            </td>
            <td rowspan="2">
                <span class="sr-label sr-signatory-label">REMARKS:</span>
                {!! nl2br(e((string) $project->report_remarks)) !!}
            </td>
        </tr>
        <tr>
            <td>
                <div class="sr-label sr-signatory-label">WITNESSED AND ACCEPTED BY:</div>
                <div class="sr-signatory-name">{{ $project->report_witnessed_by }}</div>
                <div class="sr-signatory-role">CLIENT / REPRESENTATIVE</div>
            </td>
        </tr>
    </table>
</div>
