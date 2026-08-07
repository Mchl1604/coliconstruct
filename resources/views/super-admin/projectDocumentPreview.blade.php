@extends('layouts.superadminNav')

@section('title', $title . ' - ' . $project->reference_no)

@section('content')
    <div class="container-fluid py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h2 class="fw-bold mb-1">
                    {{ $title }} Preview
                </h2>
                <div class="text-muted">
                    {{ $document->document_name }}
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('super-admin.projects.show', $project->project_id) }}"
                    class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
                    Back to Project
                </a>

                <a href="{{ asset($document->document_path) }}" class="btn btn-outline-secondary" target="_blank"
                    rel="noopener noreferrer">
                    Open Original File
                </a>

                <a href="{{ asset($document->document_path) }}" class="btn btn-primary"
                    download="{{ $document->document_name }}">
                    <i class="bi bi-download me-1" aria-hidden="true"></i>
                    Download
                </a>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                @if ($previewType === 'image')
                    <img src="{{ $documentUrl }}" alt="{{ $document->document_name }}" class="img-fluid w-100 rounded border">
                @elseif ($previewType === 'pdf')
                    <iframe src="{{ $documentUrl }}" class="w-100 border rounded" style="height: 80vh;"
                        title="{{ $document->document_name }}"></iframe>
                @elseif ($previewType === 'docx')
                    <div id="docx-viewer" class="border rounded bg-white p-3" style="min-height: 80vh;"></div>
                    <div id="docx-loading" class="text-muted mt-3">
                        Loading document preview...
                    </div>
                @else
                    <div class="alert alert-warning mb-0">
                        This file type cannot be previewed in the browser. Use the open original file button instead.
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if ($previewType === 'docx')
        <script src="https://unpkg.com/docx-preview@0.3.7/dist/docx-preview.min.js"></script>
        <script>
            (async function() {
                const container = document.getElementById('docx-viewer');
                const loading = document.getElementById('docx-loading');

                try {
                    const response = await fetch(@json($documentUrl));
                    const buffer = await response.arrayBuffer();

                    await window.docx.renderAsync(buffer, container, null, {
                        className: 'docx-preview'
                    });

                    if (loading) {
                        loading.remove();
                    }
                } catch (error) {
                    if (container) {
                        container.innerHTML = '<div class="alert alert-danger mb-0">Unable to render the DOCX preview. Use the open original file button instead.</div>';
                    }

                    if (loading) {
                        loading.remove();
                    }
                }
            })();
        </script>
    @endif
@endsection