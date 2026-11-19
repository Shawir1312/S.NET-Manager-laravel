<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0d6efd">
    <title>@yield('title', 'Beranda') — {{ config('snet.name') }} Lapangan</title>
    <link rel="manifest" href="{{ asset('lapangan.webmanifest') }}">
    <link rel="icon" type="image/png" href="{{ asset('snet/logo.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('snet/logo.png') }}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="{{ asset('snet/app.css') }}?v={{ filemtime(public_path('snet/app.css')) }}">
    <link rel="stylesheet" href="{{ asset('snet/modules.css') }}?v={{ filemtime(public_path('snet/modules.css')) }}">
    <style>
        body { background: var(--bs-tertiary-bg); padding-bottom: calc(72px + env(safe-area-inset-bottom)); }
        .field-top { position: sticky; top: 0; z-index: 1030; background: #0d6efd; color: #fff; padding: 10px 16px; display: flex; align-items: center; gap: 10px; }
        .field-top img { height: 28px; background: #fff; padding: 2px; border-radius: 4px; }
        .field-main { max-width: 960px; margin: 0 auto; padding: 16px; }
        .field-nav { position: fixed; bottom: 0; left: 0; right: 0; z-index: 1030; display: flex; background: var(--bs-body-bg); border-top: 1px solid var(--bs-border-color); padding-bottom: env(safe-area-inset-bottom); }
        .field-nav a { flex: 1; text-align: center; padding: 8px 4px; font-size: .75rem; color: var(--bs-secondary-color); text-decoration: none; }
        .field-nav a i { display: block; font-size: 1.35rem; }
        .field-nav a.active { color: #0d6efd; font-weight: 600; }
        .field-main .page-header { margin-bottom: 16px; }
        .field-main .field-hide { display: none !important; } /* isian lanjutan: di lapangan pakai nilai default */
        .field-main .position-sticky.bottom-0 { bottom: calc(60px + env(safe-area-inset-bottom)) !important; }
    </style>
    @stack('head')
</head>
<body>
@php($u = auth()->user())
<header class="field-top">
    <img src="{{ asset('snet/logo.png') }}" alt="Logo">
    <div class="flex-grow-1 lh-sm">
        <div class="fw-bold">{{ config('snet.name') }} Lapangan</div>
        <small class="opacity-75">{{ $u->name ?: $u->username }}</small>
    </div>
    @if (config('ontconfig.provisioning_mode') !== 'production')
        <span class="badge bg-warning text-dark" title="Konfigurasi ONT tidak dikirim ke perangkat asli">SIMULASI</span>
    @endif
    <a href="{{ route('dashboard') }}" class="btn btn-sm btn-light" title="Dashboard lengkap" aria-label="Dashboard lengkap"><i class="bi bi-grid"></i></a>
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-sm btn-light" title="Logout" aria-label="Logout"><i class="bi bi-box-arrow-right"></i></button></form>
</header>

<main class="field-main">
    @if ($__env->hasSection('page-header'))
        @yield('page-header')
    @elseif (! request()->routeIs('field.home'))
        <h1 class="h5 fw-bold mb-3">@yield('title')</h1>
    @endif
    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-1"></i> {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    @endif
    @yield('content')
</main>

<nav class="field-nav">
    <a href="{{ route('field.home') }}" @class(['active' => request()->routeIs('field.home')])><i class="bi bi-house"></i>Beranda</a>
    @can('configure-ont')
        <a href="{{ route('field.hotspot') }}" @class(['active' => request()->routeIs('field.hotspot')])><i class="bi bi-qr-code-scan"></i>ONT Hotspot</a>
    @endcan
    @can('operate')
        <a href="{{ route('field.customers.create') }}" @class(['active' => request()->routeIs('field.customers.*')])><i class="bi bi-person-plus"></i>Pelanggan</a>
    @endcan
</nav>

<div id="app-progress-bar" class="app-progress-bar"></div>
<div id="toast-container"></div>
<div class="toast-container position-fixed bottom-0 end-0 p-3 mb-5" id="toastArea"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('assets/app.js') }}"></script>
<script src="{{ asset('snet/app.js') }}?v={{ filemtime(public_path('snet/app.js')) }}"></script>
@stack('scripts')
</body>
</html>
