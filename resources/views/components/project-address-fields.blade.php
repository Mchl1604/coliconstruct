@props([
    // The project being edited; null in the create wizard.
    'project' => null,
])

{{--
    A project's address, part by part, restricted to Luzon - see LuzonLocations
    and ProjectAddress. Shared by the create wizard and the Edit Details dialog.

    The lists for what is already chosen are drawn here, so a saved or refused
    form opens filled in. addressFields.js fetches the next list as choices
    change. A project whose old address could not be fully read shows it above
    the fields, to re-enter from.
--}}
@php
    $province = old('address_province', $project?->address_province);
    $municipality = old('address_municipality', $project?->address_municipality);
    $barangay = old('address_barangay', $project?->address_barangay);
    $province = is_string($province) ? $province : null;
    $municipality = is_string($municipality) ? $municipality : null;
    $barangay = is_string($barangay) ? $barangay : null;
    $municipalities = \App\Support\LuzonLocations::municipalities($province);
    $barangays = \App\Support\LuzonLocations::barangays($province, $municipality);
@endphp

<div data-address-fields data-options-url="{{ route('super-admin.projects.address-options') }}">
    @if ($project?->address && ! $project->address_barangay)
        <p class="form-text mt-0 mb-2">Current: {{ $project->address }}</p>
    @endif

    <div class="row g-3">
        <div class="col-md-4">
            <label for="addressProvince" class="form-label">Province</label>
            <select name="address_province" id="addressProvince" @class(['form-select', 'is-invalid' => $errors->has('address_province')])
                data-address-province required>
                <option value="">Choose province</option>
                @foreach (\App\Support\LuzonLocations::provinces() as $option)
                    <option value="{{ $option }}" @selected($option === $province)>{{ $option }}</option>
                @endforeach
            </select>
            @error('address_province')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="col-md-4">
            <label for="addressMunicipality" class="form-label">City / Municipality</label>
            <select name="address_municipality" id="addressMunicipality" @class(['form-select', 'is-invalid' => $errors->has('address_municipality')])
                data-address-municipality data-empty-label="Choose province first"
                data-ready-label="Choose city / municipality" required>
                <option value="">{{ $municipalities === [] ? 'Choose province first' : 'Choose city / municipality' }}</option>
                @foreach ($municipalities as $option)
                    <option value="{{ $option }}" @selected($option === $municipality)>{{ $option }}</option>
                @endforeach
            </select>
            @error('address_municipality')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="col-md-4">
            <label for="addressBarangay" class="form-label">Barangay</label>
            <select name="address_barangay" id="addressBarangay" @class(['form-select', 'is-invalid' => $errors->has('address_barangay')])
                data-address-barangay data-empty-label="Choose municipality first"
                data-ready-label="Choose barangay" required>
                <option value="">{{ $barangays === [] ? 'Choose municipality first' : 'Choose barangay' }}</option>
                @foreach ($barangays as $option)
                    <option value="{{ $option }}" @selected($option === $barangay)>{{ $option }}</option>
                @endforeach
            </select>
            @error('address_barangay')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="col-md-8">
            <label for="addressStreet" class="form-label">Street</label>
            <input type="text" name="address_street" id="addressStreet" maxlength="255"
                @class(['form-control', 'is-invalid' => $errors->has('address_street')])
                value="{{ old('address_street', $project?->address_street) }}" data-address-street required>
            @error('address_street')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="col-md-4">
            <label for="addressHouseNo" class="form-label">House No. (optional)</label>
            <input type="text" name="address_house_no" id="addressHouseNo" maxlength="50"
                @class(['form-control', 'is-invalid' => $errors->has('address_house_no')])
                value="{{ old('address_house_no', $project?->address_house_no) }}" data-address-house-no>
            @error('address_house_no')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <p class="form-text text-danger mb-0 d-none" role="alert" data-address-error>Couldn't load this list. Try again.</p>
</div>
