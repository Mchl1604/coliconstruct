@extends('layouts.superadminNav')

@push('styles')
    <link href="/css/super-admin/createProject.css" rel="stylesheet">
    <link href="/css/super-admin/createProjectProgress.css" rel="stylesheet">
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h4 class="fw-bold mb
-1">Create New Project</h4>
            <p class="text-secondary small mb-0">Fill in the client information to start creating a new project.</p>

        </div>
    </div>


    <div class="client-progress-wrap">
        <div class="client-progress" aria-label="Project progress tracker">
            <div class="client-progress-step active">
                <div class="client-progress-circle">1</div>
                <div class="client-progress-label">Client Info</div>
            </div>

            <div class="client-progress-line" aria-hidden="true"></div>

            <div class="client-progress-step">
                <div class="client-progress-circle">2</div>
                <div class="client-progress-label">Project Details</div>
            </div>

            <div class="client-progress-line" aria-hidden="true"></div>

            <div class="client-progress-step">
                <div class="client-progress-circle">3</div>
                <div class="client-progress-label">Schedule</div>
            </div>

            <div class="client-progress-line" aria-hidden="true"></div>

            <div class="client-progress-step">
                <div class="client-progress-circle">4</div>
                <div class="client-progress-label">Review</div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-3 create-project-card">
        <div class="card-body p-4">

            <form method="POST">
                @csrf

                <div class="row g-3">

                    <!-- Client Name -->
                    <div class="col-md-5">
                        <label for="surname" class="form-label">Surname</label>
                        <input type="text" name="surname" id="surname" class="form-control" placeholder="Enter surname"
                            required>
                    </div>

                    <div class="col-md-5">
                        <label for="firstname" class="form-label">First Name</label>
                        <input type="text" name="firstname" id="firstname" class="form-control"
                            placeholder="Enter first name" required>
                    </div>

                    <div class="col-md-2">
                        <label for="middleInitial" class="form-label">M.I.</label>
                        <input type="text" name="middle_initial" id="middleInitial" class="form-control text-uppercase"
                            maxlength="1" placeholder="M">
                    </div>

                    <!-- Contact Information -->
                    <div class="col-md-6">
                        <label for="clientEmail" class="form-label">Email Address</label>
                        <input type="email" name="client_email" id="clientEmail" class="form-control"
                            placeholder="Enter email address" required>
                    </div>

                    <div class="col-md-6">
                        <label for="clientPhone" class="form-label">Contact Number</label>
                        <input type="tel" name="client_phone" id="clientPhone" class="form-control"
                            placeholder="09XXXXXXXXX" maxlength="11" pattern="^09\d{9}$"
                            oninput="this.value=this.value.replace(/[^0-9]/g,'')" required>
                    </div>

                    <!-- Address -->
                    <div class="col-12">
                        <label for="clientAddress" class="form-label">Address</label>
                        <textarea name="client_address" id="clientAddress" rows="3" class="form-control"
                            placeholder="Enter complete address" required></textarea>
                    </div>

                </div>

                <div class="d-flex justify-content-end mt-4">
                    <button type="submit" class="btn btn-primary px-4">
                        Next
                    </button>
                </div>

            </form>

        </div>
    </div>
@endsection
