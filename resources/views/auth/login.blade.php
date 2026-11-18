<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="APLIKASI S.NET MANAGER">
    <title>Login — {{ config('snet.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('snet/logo.png') }}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="{{ asset('snet/app.css') }}?v={{ filemtime(public_path('snet/app.css')) }}">
    <style>
        body { margin: 0; padding: 0; }
        .theme-toggle-login { position: fixed; top: 15px; right: 15px; }
        .login-logo-box { display: inline-flex; align-items: center; justify-content: center; background: #ffffff !important;
            padding: 8px 18px; border-radius: 12px; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15); margin-bottom: 8px; }
        .login-logo-box img { height: 52px; width: auto; max-width: 100%; object-fit: contain; display: block; }
    </style>
    <script>
        (function () {
            const theme = localStorage.getItem('snet-theme') || 'light';
            if (theme === 'dark') document.documentElement.setAttribute('data-bs-theme', 'dark');
        })();
    </script>
</head>
<body class="login-body">

<button type="button" class="btn btn-outline-secondary theme-toggle theme-toggle-login" title="Ganti Tema" aria-label="Ganti Tema">
    <i class="bi bi-sun-fill sun-icon d-none"></i>
    <i class="bi bi-moon-stars-fill moon-icon"></i>
</button>

<main class="login-card">
    <div class="login-logo">
        <div class="login-logo-box"><img src="{{ asset('snet/logo.png') }}" alt="{{ config('snet.company') }}"></div>
        <h5>{{ config('snet.name') }}</h5>
        <p>{{ config('snet.company') }}</p>
    </div>
    <div class="login-divider"></div>

    @error('email')
        <div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle me-2"></i>{{ $message }}</div>
    @enderror

    <form method="POST" action="{{ route('login.attempt') }}" autocomplete="off" novalidate>
        @csrf
        <div class="mb-3">
            <label class="form-label" for="email"><i class="bi bi-person me-1 text-blue"></i>Username</label>
            <input type="text" class="form-control" id="email" name="email" value="{{ old('email') }}" placeholder="Masukkan username" required autofocus autocomplete="username">
        </div>
        <div class="mb-4">
            <label class="form-label" for="password"><i class="bi bi-lock me-1 text-blue"></i>Password</label>
            <div class="input-group">
                <input type="password" class="form-control" id="password" name="password" placeholder="Masukkan password" required autocomplete="current-password">
                <button class="btn btn-outline-secondary" type="button" id="togglePwd" tabindex="-1" aria-label="Tampilkan password"><i class="bi bi-eye" id="eyeIcon"></i></button>
            </div>
        </div>
        <button type="submit" class="btn btn-primary w-100 btn-lg fw-600"><i class="bi bi-box-arrow-in-right me-2"></i>Login</button>
    </form>

    <div class="text-center mt-4 mb-2">
        <div style="font-size: 0.85rem; font-weight: 600; color: var(--bs-secondary-color); letter-spacing: 0.5px;">
            &copy; {{ date('Y') }} <span style="background: linear-gradient(135deg, #0b5ed7, #20c997); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Shawir</span>
        </div>
        <div style="font-size: 0.7rem; color: var(--bs-tertiary-color); font-weight: 500; margin-top: 2px;">S.NET MANAGER &bull; All Rights Reserved</div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('snet/app.js') }}?v={{ filemtime(public_path('snet/app.js')) }}"></script>
<script>
    document.getElementById('togglePwd')?.addEventListener('click', () => {
        const pwd = document.getElementById('password');
        pwd.type = pwd.type === 'password' ? 'text' : 'password';
        document.getElementById('eyeIcon').className = pwd.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
    });
</script>
</body>
</html>
