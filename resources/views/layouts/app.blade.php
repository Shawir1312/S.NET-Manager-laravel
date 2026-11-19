<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="S.NET RADIUS Hotspot Voucher Management System">
    <title>@yield('title', 'Dashboard') — {{ config('snet.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('snet/logo.png') }}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    {{-- Tampilan asli S.NET Manager; modules.css hanya komponen mesin ONT (wizard, timeline). --}}
    <link rel="stylesheet" href="{{ asset('snet/app.css') }}?v={{ filemtime(public_path('snet/app.css')) }}">
    <link rel="stylesheet" href="{{ asset('snet/modules.css') }}?v={{ filemtime(public_path('snet/modules.css')) }}">
    <link rel="stylesheet" href="{{ asset('snet/print.css') }}" media="print">
    <script>
        (function () {
            const theme = localStorage.getItem('snet-theme') || 'light';
            if (theme === 'dark') document.documentElement.setAttribute('data-bs-theme', 'dark');
        })();
    </script>
    @stack('head')
</head>
<body>
<div id="app-progress-bar" class="app-progress-bar"></div>

<div id="page-loader" aria-hidden="true">
    <div class="loader-card">
        <div class="loader-spinner-wrapper">
            <div class="loader-spinner-orbit outer"></div>
            <div class="loader-spinner-orbit inner"></div>
            <div class="loader-logo-circle">
                <img src="{{ asset('snet/logo.png') }}" alt="Logo" class="loader-center-logo">
            </div>
        </div>
        <div class="loader-content">
            <div class="loader-title fw-bold" id="page-loader-title">Memuat Halaman...</div>
            <div class="loader-subtitle" id="page-loader-subtitle">Menyiapkan data, mohon tunggu</div>
            <div class="loader-dots"><span></span><span></span><span></span></div>
        </div>
    </div>
</div>

<div id="sidebar-backdrop"></div>

@include('partials.sidebar')

@php($admin = auth()->user())
<nav id="topbar">
    <div class="topbar-left">
        <button type="button" id="sidebar-toggle" title="Toggle Sidebar"><i class="bi bi-list"></i></button>
        <span class="topbar-title d-flex align-items-center gap-2">
            <img src="{{ asset('snet/logo.png') }}" class="d-inline-block d-md-none" style="height:24px; background-color:#ffffff; padding:2px; border-radius:3px;" alt="Logo">
            <span class="d-none d-sm-inline">@yield('title', 'Dashboard')</span>
        </span>
    </div>
    <div class="topbar-right">
        @if (config('ontconfig.provisioning_mode') !== 'production')
            <span class="badge bg-warning text-dark me-1" title="Konfigurasi ONT tidak dikirim ke perangkat asli"><i class="bi bi-cone-striped"></i> <span class="d-none d-sm-inline">ONT </span>SIMULASI</span>
        @endif
        <button type="button" class="btn btn-sm btn-icon border-0 me-2 theme-toggle" title="Ganti Tema">
            <i class="bi bi-sun-fill sun-icon d-none" style="font-size:1.05rem;"></i>
            <i class="bi bi-moon-stars-fill moon-icon" style="font-size:1.05rem;"></i>
        </button>
        <div class="dropdown">
            <div class="topbar-user" data-bs-toggle="dropdown">
                <div class="topbar-avatar">{{ mb_strtoupper(mb_substr($admin->name ?: $admin->username, 0, 1)) }}</div>
                <div class="topbar-user-info d-none d-md-block">
                    <div class="topbar-user-name">{{ $admin->name ?: $admin->username }}</div>
                    <small>{{ $admin->isAdmin() ? 'Super Admin' : $admin->role->label() }}</small>
                </div>
                <i class="bi bi-chevron-down" style="font-size:.7rem;color:var(--gray-500)"></i>
            </div>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="min-width:180px;">
                <li><h6 class="dropdown-header">{{ $admin->username ?: $admin->email }}</h6></li>
                @can('manage-settings')
                    <li><a class="dropdown-item" href="{{ route('settings.general') }}"><i class="bi bi-gear me-2"></i>Pengaturan</a></li>
                @endcan
                <li><hr class="dropdown-divider"></li>
                <li><form method="POST" action="{{ route('logout') }}">@csrf<button class="dropdown-item text-danger" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Logout</button></form></li>
            </ul>
        </div>
    </div>
</nav>

<main id="main-content">
    {{-- Section kosong = halaman menaruh page-header sendiri di konten (port markup S.NET lama). --}}
    @if ($__env->hasSection('page-header'))
        @yield('page-header')
    @else
        <div class="page-header">
            <div>
                <h1 class="page-title">@yield('title', 'Dashboard')</h1>
                @hasSection('subtitle')<p class="page-subtitle">@yield('subtitle')</p>@endif
            </div>
            @hasSection('actions')<div class="d-flex flex-wrap gap-2">@yield('actions')</div>@endif
        </div>
    @endif

    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-1"></i> {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @yield('content')
</main>

<div id="toast-container"></div>
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastArea"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
{{-- OntApp (api/toast/konfirmasi hapus) dimuat sebelum JS S.NET agar konfirmasi hapus menahan loader. --}}
<script src="{{ asset('assets/app.js') }}"></script>
<script src="{{ asset('snet/app.js') }}?v={{ filemtime(public_path('snet/app.js')) }}"></script>
@include('partials.job-progress')
@stack('scripts')
</body>
</html>
