@extends('layouts.authShell')

@section('title', 'Sign In')

@section('card')
    <img src="/img/coliconstructlogor.png" alt="Coliconstruct" width="72" class="mb-3">
    <h3 class="mb-1">Welcome Back</h3>
    <p class="text-muted mb-4">Sign in to Coliconstruct Engineering Services</p>

    @if ($errors->any())
        <div class="alert alert-danger text-start" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('auth.login.attempt') }}">
        @csrf

        <div class="mb-3 text-start">
            <label class="form-label" for="email">Email</label>
            <input type="email" id="email" name="email" class="form-control" placeholder="you@email.com"
                value="{{ old('email') }}" required autofocus autocomplete="username">
        </div>

        <x-password-input name="password" label="Password" placeholder="••••••••" />

        <div class="text-start mb-4">
            <a href="{{ route('auth.password.request') }}" class="small text-decoration-none">
                Forgot password?
            </a>
        </div>

        <div class="d-grid mb-3">
            <button type="submit" class="btn btn-primary btn-lg">Sign In</button>
        </div>

        <div class="text-muted small">
            Don't have an account?
            <a href="{{ route('auth.register') }}" class="text-decoration-none">Register here</a>
        </div>
    </form>
@endsection
