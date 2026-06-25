<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="/css/theme.css" rel="stylesheet">
  <style>
    body { background: #f8f9fa; }
  </style>
</head>
<body>

@if (session('success'))
  <div class="toast-container position-fixed top-0 end-0 p-3">
    <div class="toast align-items-center bg-success text-white border-0" role="alert" data-bs-autohide="true" data-bs-delay="3000">
      <div class="toast-body">{{ session('success') }}</div>
    </div>
  </div>
@endif

@if (session('error'))
  <div class="toast-container position-fixed top-0 end-0 p-3">
    <div class="toast align-items-center bg-danger text-white border-0" role="alert" data-bs-autohide="true" data-bs-delay="3000">
      <div class="toast-body">{{ session('error') }}</div>
    </div>
  </div>
@endif

<div class="d-flex align-items-center justify-content-center vh-100">
  <div class="card shadow-sm" style="width:420px;">
    <div class="card-body p-5 text-center">
      <img src="/img/coliconstructlogor.png" alt="Logo" width="72" class="mb-3">
      <h3 class="mb-1">Welcome Back</h3>
      <p class="text-muted mb-4">Sign in to Coliconstruct Engineering Services</p>

      <form method="POST" action="{{ route('auth.login') }}">
        @csrf

        <div class="mb-3 text-start">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" placeholder="you@email.com" required>
        </div>

        <div class="mb-4 text-start">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control" placeholder="••••••••" required>
          
        </div>
        <div class="mb-4 text-start">
          <a href="" class="text-decoration-none">Forgot password?</a>
        </div>

        <div class="d-grid mb-3">
          <button type="submit" class="btn btn-primary btn-lg">Sign In</button>
        </div>

        <div class="text-muted small">Don't have an account? <a href="" class="text-decoration-none">Register here</a></div>

      </form>
      <div>
        Temporary Access
        <a href="{{ route('super-admin.dashboard') }}">super-admin</a>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const toastElList = document.querySelectorAll('.toast');
  toastElList.forEach(function (toastEl) {
    const toast = new bootstrap.Toast(toastEl);
    toast.show();
  });
});
</script>

</body>
</html>