@php
    $user = auth()->user();
    // Urutan & nama menu mengikuti S.NET Manager lama. [label, ikon, route, ability, params, active-pattern]
    $sections = [
        'Utama' => [
            ['Dashboard', 'bi-speedometer2', 'dashboard', null],
            ['Aplikasi Lapangan (HP)', 'bi-phone', 'field.home', 'operate', [], 'field.*'],
        ],
        'Monitoring' => [
            ['User Aktif', 'bi-wifi', 'monitoring.active', 'operate'],
            ['Daftar MAC', 'bi-laptop', 'monitoring.mac', 'operate'],
        ],
        ($user->isAdmin() ? 'Broadband (PPPoE)' : 'Pelanggan') => [
            ['Pelanggan Rumahan', 'bi-house-door', 'customers.index', 'operate', [], 'customers.*'],
            ['Paket PPPoE', 'bi-box', 'pppoe-profiles.index', 'manage-settings', [], 'pppoe-profiles.*'],
            ['Riwayat Pembayaran', 'bi-wallet2', 'payments.index', 'operate'],
            ['Pengaturan PPPoE', 'bi-gear-wide-connected', 'pppoe-settings.edit', 'manage-settings'],
        ],
        // Bagian ONT & ACS memakai mesin ont-autoconfig (wizard, template, verifikasi, rollback).
        'ONT & ACS' => [
            ['Setting ONT Hotspot (Scan SN)', 'bi-qr-code-scan', 'ont-hotspot.index', 'configure-ont'],
            // Viewer (tanpa hak operate) tetap bisa melihat daftar ONT (read-only).
            $user->can('operate')
                ? ['Monitor ONT', 'bi-hdd-network', 'ont-monitor.index', 'operate', [], 'ont-monitor.*|devices.*']
                : ['Daftar ONT', 'bi-hdd-network', 'devices.index', 'view-devices', [], 'devices.*'],
            ['Config ONT Manual', 'bi-sliders', 'auto-config', 'configure-ont'],
            ['Template ONT', 'bi-file-earmark-text', 'templates.index', 'configure-ont', [], 'templates.*'],
            ['Riwayat Config ONT', 'bi-clock-history', 'history.index', 'configure-ont', [], 'history.*'],
            ['Model ONT', 'bi-cpu', 'models.index', 'manage-models', [], 'models.*'],
        ],
        'Voucher Hotspot' => [
            ['Generate Voucher', 'bi-plus-circle', 'vouchers.create', 'operate'],
            ['Daftar Voucher', 'bi-ticket-perforated', 'vouchers.index', 'operate'],
            ['Paket Hotspot', 'bi-collection', 'hotspot-profiles.index', 'manage-settings', [], 'hotspot-profiles.*'],
        ],
        'Jaringan' => [
            ['Router / NAS', 'bi-router', 'routers.index', 'manage-settings', [], 'routers.*'],
            ['VPN WireGuard', 'bi-shield-lock', 'wireguard.index', 'manage-settings', [], 'wireguard.*'],
        ],
        'Laporan' => [
            ['Penjualan Hotspot', 'bi-bar-chart-line', 'reports.sales', 'operate'],
            ['Pemakaian Data', 'bi-graph-up-arrow', 'reports.usage', 'operate'],
            ['Penagihan Reseller', 'bi-receipt', 'reports.penagihan', 'operate', [], 'reports.penagihan*'],
        ],
        'Administrasi' => [
            ['Kelola Admin', 'bi-person-gear', 'users.index', 'manage-users', [], 'users.*'],
            ['WhatsApp Notifikasi', 'bi-whatsapp', 'whatsapp.index', 'manage-settings', ['tab' => 'inbox'], 'whatsapp.*'],
            ['Config GenieACS', 'bi-hdd-rack', 'settings.genieacs.edit', 'manage-settings'],
            ['Audit Log', 'bi-journal-text', 'settings.audit', 'view-audit'],
            ['Backup / Restore', 'bi-cloud-download', 'settings.backup', 'manage-settings'],
            ['Status Sistem', 'bi-activity', 'system.index', 'manage-users'],
            ['Pengaturan', 'bi-sliders', 'settings.general', 'manage-settings'],
        ],
    ];

    $isActive = fn (array $item): bool => request()->routeIs(...explode('|', $item[5] ?? $item[2]));
@endphp
<aside id="sidebar">
    <div class="sidebar-brand d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2">
            <img src="{{ asset('snet/logo.png') }}" alt="{{ config('snet.company') }}">
            <div class="sidebar-brand-text" style="line-height:1.2; font-size:1.1rem; padding-top:4px;">{{ config('snet.name') }}</div>
        </div>
        <button type="button" class="sidebar-close-btn d-md-none" id="sidebar-close-btn" title="Tutup Menu"><i class="bi bi-x-lg"></i></button>
    </div>

    <ul class="sidebar-nav">
        @foreach ($sections as $title => $items)
            @php($visible = array_filter($items, fn ($i) => Route::has($i[2]) && ($i[3] === null || $user->can($i[3]))))
            @continue($visible === [])
            <li class="nav-label">{{ $title }}</li>
            @foreach ($visible as $item)
                <li>
                    <a href="{{ route($item[2], $item[4] ?? []) }}" class="nav-link {{ $isActive($item) ? 'active' : '' }}">
                        <i class="bi {{ $item[1] }}"></i> {{ $item[0] }}
                    </a>
                </li>
            @endforeach
        @endforeach
    </ul>

    <div class="sidebar-footer">
        <div>{{ config('snet.name') }} v{{ config('snet.version') }}</div>
        <div>&copy; {{ date('Y') }} {{ config('snet.company') }}</div>
    </div>
</aside>
