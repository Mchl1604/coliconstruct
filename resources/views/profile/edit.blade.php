@extends($layout)

@section('title', 'Profile')

@section('content')
    <section class="public-section">
        <div class="container">

            <div class="mb-4">
                <h1 class="public-section-heading mb-1">Profile</h1>
                <p class="text-secondary mb-0">The details we hold for your account.</p>
            </div>

            <div class="card client-card shadow-sm" style="max-width: 46rem;">
                <div class="card-header bg-white">
                    <h2 class="h5 mb-0 fw-bold">Account Details</h2>
                </div>

                <div class="card-body">
                    <div class="row g-3">
                        @php
                            $details = [
                                ['label' => 'Name', 'value' => $account->fullName()],
                                ['label' => 'Email', 'value' => $account->email],
                                ['label' => 'Role', 'value' => $account->roleLabel()],
                                ['label' => 'Account ID', 'value' => $account->user_code],
                                ['label' => 'Contact Number', 'value' => $account->contact_number],
                                ['label' => 'Status', 'value' => $account->statusLabel()],
                                [
                                    'label' => 'Member Since',
                                    'value' => $account->created_at?->format('M j, Y'),
                                ],
                            ];
                        @endphp

                        @foreach ($details as $detail)
                            <div class="col-sm-6">
                                <div class="public-detail-label">{{ $detail['label'] }}</div>
                                <div class="fw-semibold">{{ $detail['value'] ?: 'Not set' }}</div>
                            </div>
                        @endforeach
                    </div>

                    <hr class="my-4">

                    <p class="text-secondary small mb-0">
                        To change any of these, contact your administrator.
                    </p>
                </div>
            </div>

        </div>
    </section>
@endsection
