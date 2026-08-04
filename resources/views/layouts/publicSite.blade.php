{{--
    The shell for every public page.

    Nothing visible here is written in: the logo, the company name, the footer
    and the contact details all come from SystemContentService, so the Super
    Admin can change any of them from Configuration without a deployment.

    The right-hand side of the header switches on who is reading: a guest is
    offered Login, and a signed-in client gets their bell and their name.
--}}
@php
    // $content is shared by the view composer in AppServiceProvider.
    $viewer = auth()->user();
    $logo = $content->image('branding.logo');
    $footerLogo = $content->image('branding.footer_logo') ?? $logo;
    $favicon = $content->image('branding.favicon');
    $quickLinks = $content->lines('footer.quick_links');
    $socials = collect([
        ['key' => 'contact.facebook', 'icon' => 'bi-facebook', 'label' => 'Facebook'],
        ['key' => 'contact.instagram', 'icon' => 'bi-instagram', 'label' => 'Instagram'],
        ['key' => 'contact.linkedin', 'icon' => 'bi-linkedin', 'label' => 'LinkedIn'],
        ['key' => 'contact.other_social', 'icon' => 'bi-globe', 'label' => 'Website'],
    ])->filter(fn (array $social): bool => $content->has($social['key']));
@endphp
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', $content->siteTitle())</title>

    @if ($favicon)
        <link rel="icon" href="{{ $favicon }}">
    @endif

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/css/theme.css" rel="stylesheet">
    <link href="/css/publicSite.css" rel="stylesheet">
    <link href="/css/notifications.css" rel="stylesheet">
    @stack('styles')
</head>

<body class="public-body">

    <nav class="navbar navbar-expand-lg public-navbar sticky-top">
        <div class="container">

            <a class="navbar-brand d-flex align-items-center gap-2" href="{{ route('landing.home') }}">
                @if ($logo)
                    <img src="{{ $logo }}" alt="{{ $content->get('branding.company_name') }}"
                        class="public-brand-logo">
                @else
                    {{-- A missing logo must not collapse the header. --}}
                    <span class="public-brand-placeholder" aria-hidden="true">
                        {{ mb_substr((string) $content->get('branding.short_name'), 0, 1) }}
                    </span>
                @endif
                <span class="public-brand-name">{{ $content->get('branding.short_name') }}</span>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#publicNav"
                aria-controls="publicNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="publicNav">
                <ul class="navbar-nav mx-lg-auto mb-2 mb-lg-0">
                    @php
                        $navItems = [
                            ['label' => 'Home', 'route' => 'landing.home'],
                            ['label' => 'My Projects', 'route' => 'public.projects'],
                            ['label' => 'About', 'route' => 'public.about'],
                            ['label' => 'Contact Us', 'route' => 'public.contact'],
                        ];
                    @endphp

                    @foreach ($navItems as $item)
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs($item['route']) || request()->routeIs($item['route'] . '.*') ? 'active' : '' }}"
                                href="{{ route($item['route']) }}">
                                {{ $item['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>

                <div class="d-flex align-items-center gap-2 public-nav-actions">
                    @if ($viewer)
                        <x-notification-bell />

                        <div class="dropdown">
                            {{-- The name alone: no avatar, since the system has
                                 no profile pictures on the public side. --}}
                            <button class="btn btn-outline-brand-blue dropdown-toggle" type="button"
                                data-bs-toggle="dropdown" aria-expanded="false">
                                {{ $viewer->fullName() }}
                            </button>

                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <a class="dropdown-item" href="{{ route('profile.edit') }}">Profile</a>
                                </li>
                                @unless ($viewer->isClient())
                                    <li>
                                        <a class="dropdown-item"
                                            href="{{ \App\Support\PortalHome::url($viewer) }}">
                                            My Portal
                                        </a>
                                    </li>
                                @endunless
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="POST" action="{{ route('auth.logout') }}">
                                        @csrf
                                        <button type="submit" class="dropdown-item text-danger">Logout</button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    @else
                        <a class="btn btn-brand-blue px-3" href="{{ route('auth.login') }}">Login</a>
                    @endif
                </div>
            </div>

        </div>
    </nav>

    @if (session('success'))
        <div class="toast-container position-fixed top-0 end-0 p-3">
            <div class="toast align-items-center bg-success text-white border-0" role="alert"
                data-bs-autohide="true" data-bs-delay="3000">
                <div class="toast-body">{{ session('success') }}</div>
            </div>
        </div>
    @endif

    @if (session('error'))
        <div class="toast-container position-fixed top-0 end-0 p-3">
            <div class="toast align-items-center bg-danger text-white border-0" role="alert"
                data-bs-autohide="true" data-bs-delay="3000">
                <div class="toast-body">{{ session('error') }}</div>
            </div>
        </div>
    @endif

    <main>
        @yield('content')
    </main>

    <footer class="public-footer">
        <div class="container">
            <div class="row g-4">

                <div class="col-lg-4">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        @if ($footerLogo)
                            <img src="{{ $footerLogo }}" alt="" class="public-footer-logo" loading="lazy">
                        @endif
                        <span class="public-footer-name">{{ $content->get('branding.company_name') }}</span>
                    </div>

                    <p class="public-footer-text">{{ $content->get('footer.description') }}</p>

                    @if ($socials->isNotEmpty())
                        <div class="d-flex gap-2 mt-3">
                            @foreach ($socials as $social)
                                <a class="public-social" href="{{ $content->get($social['key']) }}"
                                    target="_blank" rel="noopener noreferrer" aria-label="{{ $social['label'] }}">
                                    <i class="bi {{ $social['icon'] }}" aria-hidden="true"></i>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                @if ($quickLinks->isNotEmpty())
                    <div class="col-6 col-lg-3">
                        <h6 class="public-footer-heading">Quick Links</h6>
                        <ul class="public-footer-links">
                            @foreach ($quickLinks as $link)
                                <li>
                                    <a href="{{ $link['description'] ?: '#' }}">{{ $link['title'] }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="col-6 col-lg-5">
                    <h6 class="public-footer-heading">Contact</h6>
                    <ul class="public-footer-contact">
                        @if ($content->has('contact.address'))
                            <li>
                                <i class="bi bi-geo-alt" aria-hidden="true"></i>
                                <span>{{ $content->get('contact.address') }}</span>
                            </li>
                        @endif
                        @if ($content->has('contact.phone'))
                            <li>
                                <i class="bi bi-telephone" aria-hidden="true"></i>
                                <span>{{ $content->get('contact.phone') }}</span>
                            </li>
                        @endif
                        @if ($content->has('contact.email'))
                            <li>
                                <i class="bi bi-envelope" aria-hidden="true"></i>
                                <span>{{ $content->get('contact.email') }}</span>
                            </li>
                        @endif
                        @if ($content->has('contact.office_hours'))
                            <li>
                                <i class="bi bi-clock" aria-hidden="true"></i>
                                <span class="public-prewrap">{{ $content->get('contact.office_hours') }}</span>
                            </li>
                        @endif
                    </ul>
                </div>

            </div>

            <hr class="public-footer-rule">

            <p class="public-footer-copyright mb-0">{{ $content->copyright() }}</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    @auth
        <script>
            window.notificationRoutes = @json([
                'feed' => route('notifications.feed'),
                'readAll' => route('notifications.read-all'),
            ]);
        </script>
        <script src="/js/notifications.js"></script>
    @endauth

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.toast').forEach(function (toastEl) {
                new bootstrap.Toast(toastEl).show();
            });
        });
    </script>

    @stack('scripts')
</body>

</html>
