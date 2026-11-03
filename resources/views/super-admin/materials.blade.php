@extends('layouts.superadminNav')

@section('title', 'Materials')

@push('styles')
    <link href="/css/materials.css" rel="stylesheet">
    <link href="/css/inventory.css" rel="stylesheet">
@endpush

@section('content')
    @include('super-admin.partials.materials-panel')

    {{-- The dialogs, outside the card like every other page's. --}}
    @include('super-admin.partials.material-modal')

    <x-confirm-dialog />
@endsection

@push('scripts')
    <script>
        window.inventoryRoutes = {
            materials: @json(route('inventory.materials.index')),
            materialTypes: @json(route('inventory.materials.types.index')),
            brands: @json(route('inventory.materials.brands.index')),
        };
    </script>
    <script src="/js/confirmDialog.js"></script>
    <script src="/js/super-admin/materials.js"></script>
@endpush
