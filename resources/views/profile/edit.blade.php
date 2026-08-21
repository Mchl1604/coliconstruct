{{--
    The signed-in account's own profile.

    One page for every role, inside whichever shell the reader already lives
    in. What it offers narrows by role rather than branching into separate
    pages: a client gets their details and their password, everyone who runs
    the work also gets a picture, and the two technician roles additionally get
    specialties they may ask to change.

    Each form owns a named error bag, so a rejected password does not light up
    the personal information fields beside it.
--}}
@extends($layout)

@section('title', 'Profile')

@push('styles')
    <link href="/css/profile.css" rel="stylesheet">
@endpush

@section('content')
    @php
        $isPublicShell = $layout === 'layouts.publicSite';
    @endphp

    <div class="{{ $isPublicShell ? 'container py-5' : 'container-fluid px-0' }}">

        <div class="mb-4">
            <h1 class="h4 fw-bold mb-1">My Profile</h1>
            <p class="text-secondary small mb-0">
                Your details, and the only place they can be changed.
            </p>
        </div>

        <div class="row g-4 profile-layout">

            {{-- ------------------------------------------ Identity column --}}
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 rounded-3 h-100">
                    <div class="card-body text-center p-4">

                        <img src="{{ $account->avatarUrl() }}" class="user-avatar user-avatar-xl mb-3"
                            alt="Your profile picture">

                        <h2 class="h5 fw-bold mb-1">{{ $account->fullName() }}</h2>
                        <p class="text-secondary small mb-3">{{ $account->roleLabel() }}</p>

                        {{-- Every account sets its own picture, clients
                             included, and shows the default avatar until it
                             does. --}}
                        <form method="POST" action="{{ route('profile.photo.update') }}"
                            enctype="multipart/form-data" class="mb-2" data-photo-form>
                            @csrf

                            <label class="form-label small fw-semibold" for="profilePhoto">
                                {{ $account->profile_photo_path ? 'Change Picture' : 'Upload Picture' }}
                            </label>

                            <input type="file" class="form-control form-control-sm mb-2" id="profilePhoto"
                                name="profile_photo" accept="image/*" required>

                            <div class="form-text text-start mb-2">
                                JPG, PNG or WEBP, up to 5 MB. Square images work best.
                            </div>

                            @error('profile_photo', 'photo')
                                <div class="alert alert-danger text-start small py-2">{{ $message }}</div>
                            @enderror

                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-upload me-1" aria-hidden="true"></i>
                                {{ $account->profile_photo_path ? 'Change Picture' : 'Upload Picture' }}
                            </button>
                        </form>

                        @if ($account->profile_photo_path)
                            <form method="POST" action="{{ route('profile.photo.destroy') }}">
                                @csrf
                                @method('DELETE')

                                <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                                    <i class="bi bi-trash me-1" aria-hidden="true"></i>
                                    Remove Picture
                                </button>
                            </form>
                        @endif

                        <hr class="my-4">

                        <dl class="profile-facts text-start mb-0">
                            <div>
                                <dt>Account ID</dt>
                                <dd>{{ $account->user_code }}</dd>
                            </div>
                            <div>
                                <dt>Status</dt>
                                <dd>
                                    <span class="badge {{ $account->statusBadgeClass() }}">
                                        {{ $account->statusLabel() }}
                                    </span>
                                </dd>
                            </div>
                            <div>
                                <dt>Date of Birth</dt>
                                {{-- Blank on an account opened before the
                                     birthdate was collected. --}}
                                <dd>{{ $account->birthdate?->format('M j, Y') ?? 'Not set' }}</dd>
                            </div>
                            <div>
                                <dt>Member Since</dt>
                                <dd>{{ $account->created_at?->format('M j, Y') ?? '—' }}</dd>
                            </div>
                        </dl>

                        <p class="text-secondary small mb-0 mt-3 text-start">
                            Role, status and date of birth can only be changed by an administrator.
                        </p>
                    </div>
                </div>
            </div>

            {{-- ---------------------------------------------- Forms column --}}
            <div class="col-lg-8">

                {{-- ---------------------------- Personal information ---- --}}
                <div class="card shadow-sm border-0 rounded-3 mb-4">
                    <div class="card-header bg-white">
                        <h2 class="h6 fw-bold mb-0">
                            <i class="bi bi-person-vcard me-1 text-primary" aria-hidden="true"></i>
                            Personal Information
                        </h2>
                    </div>

                    <div class="card-body">
                        <form method="POST" action="{{ route('profile.information') }}" novalidate>
                            @csrf
                            @method('PUT')

                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold mb-1" for="firstName">
                                        First Name <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control @error('first_name', 'information') is-invalid @enderror"
                                        id="firstName" name="first_name" maxlength="100" required
                                        value="{{ old('first_name', $account->first_name) }}">
                                    @error('first_name', 'information')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold mb-1" for="middleName">
                                        Middle Name
                                    </label>
                                    <input type="text" class="form-control @error('middle_name', 'information') is-invalid @enderror"
                                        id="middleName" name="middle_name" maxlength="100" placeholder="Optional"
                                        value="{{ old('middle_name', $account->middle_name) }}">
                                    @error('middle_name', 'information')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold mb-1" for="lastName">
                                        Last Name <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control @error('last_name', 'information') is-invalid @enderror"
                                        id="lastName" name="last_name" maxlength="100" required
                                        value="{{ old('last_name', $account->last_name) }}">
                                    @error('last_name', 'information')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold mb-1" for="contactNumber">
                                        Contact Number <span class="text-danger">*</span>
                                    </label>
                                    {{-- Eleven digits and nothing else, the same rule
                                         User::CONTACT_NUMBER_RULE applies on the server.
                                         registerForm.js strips anything that is not a
                                         digit as it is typed or pasted. --}}
                                    <input type="text"
                                        class="form-control @error('contact_number', 'information') is-invalid @enderror"
                                        id="contactNumber" name="contact_number" inputmode="numeric"
                                        data-digits-only
                                        maxlength="{{ \App\Models\User::CONTACT_NUMBER_LENGTH }}"
                                        placeholder="09171234567" autocomplete="tel"
                                        value="{{ old('contact_number', $account->contact_number) }}" required>
                                    <div class="form-text">11 digits, numbers only.</div>
                                    @error('contact_number', 'information')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-8">
                                    <label class="form-label small fw-semibold mb-1" for="emailAddress">
                                        Email Address <span class="text-danger">*</span>
                                    </label>
                                    <input type="email" class="form-control @error('email', 'information') is-invalid @enderror"
                                        id="emailAddress" name="email" maxlength="255" required
                                        value="{{ old('email', $account->email) }}">
                                    <div class="form-text">
                                        Your sign-in address. Changing it requires a code sent to the new address.
                                    </div>
                                    @error('email', 'information')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            <div class="mt-3">
                                <button type="submit" class="btn btn-primary px-4">Save Changes</button>
                            </div>
                        </form>
                    </div>

                    {{-- A change of email waiting on its code. The account keeps
                         the old address until this is confirmed. --}}
                    @if ($pendingEmail)
                        <div class="card-footer bg-light border-top">
                            <div class="d-flex align-items-start gap-2 mb-3">
                                <i class="bi bi-envelope-check text-primary mt-1" aria-hidden="true"></i>
                                <div>
                                    <div class="fw-semibold small">Confirm your new email address</div>
                                    <div class="text-secondary small">
                                        Code sent to <strong>{{ $pendingEmail }}</strong>.
                                        Sign in with {{ $account->email }} until confirmed.
                                    </div>
                                </div>
                            </div>

                            <div class="row g-2 align-items-start">
                                <div class="col-sm-5">
                                    <form method="POST" action="{{ route('profile.email.verify') }}"
                                        class="d-flex gap-2">
                                        @csrf
                                        <input type="text" name="code" class="form-control text-center"
                                            style="letter-spacing:.4rem; font-weight:600;" inputmode="numeric"
                                            autocomplete="one-time-code" maxlength="6" placeholder="000000" required
                                            aria-label="Verification code">
                                        <button type="submit" class="btn btn-primary flex-shrink-0">Confirm</button>
                                    </form>
                                    @error('code', 'emailChange')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-sm-7 d-flex gap-2 align-items-center">
                                    <form method="POST" action="{{ route('profile.email.resend') }}">
                                        @csrf
                                        <button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none"
                                            @disabled($emailRetryAfter > 0)>
                                            {{ $emailRetryAfter > 0 ? 'Resend code in ' . $emailRetryAfter . 's' : 'Resend code' }}
                                        </button>
                                    </form>

                                    <span class="text-secondary">&middot;</span>

                                    <form method="POST" action="{{ route('profile.email.cancel') }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                            class="btn btn-link btn-sm p-0 text-decoration-none text-danger">
                                            Cancel change
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- ------------------------------------- Specialties ----
                     The panel shows what this technician is approved for and
                     nothing else. Changing the list is a separate act with an
                     administrator's decision attached to it, so it lives behind
                     an Edit button and a dialog rather than sitting open on the
                     page as a permanently half-filled form. --}}
                @if ($showsSpecialties)
                    <div class="card shadow-sm border-0 rounded-3 mb-4">
                        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h2 class="h6 fw-bold mb-0">
                                <i class="bi bi-tools me-1 text-primary" aria-hidden="true"></i>
                                Specialties
                            </h2>

                            <div class="d-flex align-items-center gap-2">
                                @if ($pendingRequest)
                                    <span class="badge bg-warning text-dark">Pending Approval</span>
                                @endif

                                {{-- While a request is outstanding there is
                                     nothing to edit: an administrator is being
                                     asked to decide on exactly one proposal, and
                                     a second would replace it silently. --}}
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal" data-bs-target="#specialtiesModal"
                                    @disabled($pendingRequest || $allSkills->isEmpty())
                                    title="{{ $pendingRequest
                                        ? 'A change is already waiting for an administrator to decide.'
                                        : ($allSkills->isEmpty()
                                            ? 'No specialties have been set up yet.'
                                            : 'Request a change to your specialties') }}">
                                    <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>
                                    Edit
                                </button>
                            </div>
                        </div>

                        <div class="card-body">
                            <div class="profile-section-label">Approved Specialties</div>

                            <div class="d-flex flex-wrap gap-2 mt-2 {{ $pendingRequest ? 'mb-4' : 'mb-0' }}"
                                data-approved-specialties>
                                @forelse ($approvedSkills as $skill)
                                    <span class="profile-chip">
                                        <i class="bi bi-check2" aria-hidden="true"></i>
                                        {{ $skill->skill_name }}
                                    </span>
                                @empty
                                    <span class="text-muted small">No specialties assigned yet.</span>
                                @endforelse
                            </div>

                            @if ($pendingRequest)
                                {{-- What has been asked for, set apart so it
                                     reads as "not yet true". --}}
                                <div class="profile-pending-panel">
                                    <div class="profile-section-label mb-2">Pending Changes</div>

                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                        @foreach ($pendingRequest->additions() as $name)
                                            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                                                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ $name }}
                                            </span>
                                        @endforeach

                                        @foreach ($pendingRequest->removals() as $name)
                                            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">
                                                <i class="bi bi-dash-lg me-1" aria-hidden="true"></i>{{ $name }}
                                            </span>
                                        @endforeach
                                    </div>

                                    <p class="text-secondary small mb-0">
                                        Submitted
                                        {{ $pendingRequest->created_at?->diffForHumans() }}. Your current
                                        specialties stay active until it is decided.
                                    </p>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- The editor. Posts to the same endpoint the open form
                         used to, so the approval rules are unchanged: nothing
                         here alters a specialty, it asks for one. --}}
                    @unless ($pendingRequest)
                        <div class="modal fade" id="specialtiesModal" tabindex="-1"
                            aria-labelledby="specialtiesModalLabel" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                                <div class="modal-content">
                                    <form method="POST" action="{{ route('profile.specialties.request') }}">
                                        @csrf

                                        <div class="modal-header">
                                            <h5 class="modal-title" id="specialtiesModalLabel">Edit Specialties</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                aria-label="Close"></button>
                                        </div>

                                        <div class="modal-body">
                                            <p class="text-secondary small">
                                                Select the specialties you should hold. Nothing changes until an
                                                administrator approves it.
                                            </p>

                                            @error('skill_ids', 'specialties')
                                                <div class="alert alert-danger small py-2">{{ $message }}</div>
                                            @enderror

                                            {{-- The approved specialty catalogue, which is
                                                 the Project Types list - a technician can
                                                 only ever hold a specialty the company
                                                 actually offers. --}}
                                            <div class="row g-2">
                                                @foreach ($allSkills as $skill)
                                                    @php
                                                        $isApproved = $approvedSkills->contains('skill_id', $skill->skill_id);
                                                    @endphp

                                                    <div class="col-sm-6 col-xl-4">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox"
                                                                name="skill_ids[]" value="{{ $skill->skill_id }}"
                                                                id="skill{{ $skill->skill_id }}"
                                                                @checked(collect(old('skill_ids', $approvedSkills->pluck('skill_id')->all()))->contains($skill->skill_id))>
                                                            <label class="form-check-label" for="skill{{ $skill->skill_id }}">
                                                                {{ $skill->skill_name }}
                                                                @if ($isApproved)
                                                                    <span class="text-success small">(approved)</span>
                                                                @endif
                                                            </label>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>

                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                Cancel
                                            </button>
                                            <button type="submit" class="btn btn-primary" @disabled($allSkills->isEmpty())>
                                                <i class="bi bi-send me-1" aria-hidden="true"></i>
                                                Submit for Approval
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endunless
                @endif

                {{-- --------------------------------- Change password ---- --}}
                <div class="card shadow-sm border-0 rounded-3">
                    <div class="card-header bg-white">
                        <h2 class="h6 fw-bold mb-0">
                            <i class="bi bi-shield-lock me-1 text-primary" aria-hidden="true"></i>
                            Change Password
                        </h2>
                    </div>

                    <div class="card-body">
                        <form method="POST" action="{{ route('profile.password') }}" novalidate>
                            @csrf
                            @method('PUT')

                            <div class="row g-3">
                                {{-- Each field carries the show/hide eye the sign-in
                                     screens have, driven by the same passwordField.js:
                                     the eye is a wrapper marked `data-password-field`
                                     with a `data-password-toggle` button inside it, and
                                     nothing about the fields themselves changes. The
                                     invalid-feedback sits outside the input group,
                                     which Bootstrap will not render from inside one. --}}
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold mb-1" for="currentPassword">
                                        Current Password <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group" data-password-field>
                                        <input type="password"
                                            class="form-control border-end-0 @error('current_password', 'password') is-invalid @enderror"
                                            id="currentPassword" name="current_password"
                                            autocomplete="current-password" required>
                                        <button class="btn btn-outline-secondary border-start-0" type="button"
                                            data-password-toggle aria-label="Show password" aria-pressed="false"
                                            tabindex="-1">
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                    @error('current_password', 'password')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold mb-1" for="newPassword">
                                        New Password <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group" data-password-field>
                                        <input type="password"
                                            class="form-control border-end-0 @error('password', 'password') is-invalid @enderror"
                                            id="newPassword" name="password" minlength="8" maxlength="72"
                                            autocomplete="new-password" data-password-new required>
                                        <button class="btn btn-outline-secondary border-start-0" type="button"
                                            data-password-toggle aria-label="Show password" aria-pressed="false"
                                            tabindex="-1">
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                    <div class="form-text" data-password-match>At least 8 characters.</div>
                                    @error('password', 'password')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold mb-1" for="confirmPassword">
                                        Confirm New Password <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group" data-password-field>
                                        <input type="password" class="form-control border-end-0"
                                            id="confirmPassword" name="password_confirmation" minlength="8"
                                            maxlength="72" autocomplete="new-password" data-password-confirm
                                            required>
                                        <button class="btn btn-outline-secondary border-start-0" type="button"
                                            data-password-toggle aria-label="Show password" aria-pressed="false"
                                            tabindex="-1">
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3">
                                <button type="submit" class="btn btn-primary px-4">Update Password</button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>

    @push('scripts')
        {{-- The show/hide eye on every password field, and the live "do these
             two match" indication between the new password and its
             confirmation. The same file the sign-in screens use. --}}
        <script src="/js/passwordField.js"></script>
        {{-- Keeps the contact number to digits only. --}}
        <script src="/js/registerForm.js"></script>
    @endpush
@endsection
