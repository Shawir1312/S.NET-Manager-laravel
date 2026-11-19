@php
    $user = auth()->user();
    // Urutan & nama menu mengikuti S.NET Manager lama. [label, ikon, route, ability, params, active-pattern]
    $sections = [
        'Utama' => [
            ['Dashboard', 'bi-speedometer2', 'dashboard', null],
        ],
        'Monitoring' => [
            ['User Aktif', 'bi-wifi', 'monitoring.active', 'operate'],
            ['Daftar MAC', 'bi-laptop', 'monitoring.mac', 'operate'],
        ],
        ($user->isAdmin() ? 'Broadband (PPPoE)' : 'Pelanggan') => [
            ['Pelanggan Rumahan', 'bi-house-door', 'customers.index', 'operate', [], 'customers.*'],
            ['Paket PPPoE', 'bi-box', 'pppoe-profiles.index', 'manage-settings'],
            ['Riwayat Pembayaran', 'bi-wallet2', 'payments.index', 'operate'],
            ['Monitor ONT', 'bi-hdd-network', 'ont-monitor.index', 'operate'],
            ['WhatsApp Notifikasi', 'bi-whatsapp', 'whatsapp.index', 'manage-settings', ['tab' => 'inbox']],
            ['Pengaturan PPPoE', 'bi-gear-wide-connected', 'pppoe-settings.edit', 'manage-settings'],
        ],
        // Bagian ONT & ACS memakai mesin ont-autoconfig (wizard, template, verifikasi, rollback).
        'ONT & ACS' => [
            ['Setting ONT Hotspot (Scan SN)', 'bi-qr-code-scan', 'ont-hotspot.index', 'configure-ont'],
            ['Config ONT Manual', 'bi-sliders', 'auto-config', 'configure-ont', ['service' => 'pppoe'], null, 'pppoe'],
            ['Config Hotspot Manual', 'bi-broadcast-pin', 'auto-config', 'configure-ont', ['service' => 'bridge'], null, 'bridge'],
            ['Template ONT', 'bi-file-earmark-text', 'templates.index', 'configure-ont', [], 'templates.*'],
            ['Daftar ONT', 'bi-router', 'devices.index', 'view-devices', [], 'devices.*'],
            ['Riwayat Config ONT', 'bi-clock-history', 'history.index', 'configure-ont', [], 'history.*'],
            ['Model ONT', 'bi-cpu', 'models.index', 'manage-models', [], 'models.*'],
        ],
        'Voucher' => [
            ['Generate Voucher', 'bi-plus-circle', 'vouchers.create', 'operate'],
            ['Daftar Voucher', 'bi-ticket-perforated', 'vouchers.index', 'operate', [], null, 'list'],
            ['Voucher Aktif', 'bi-ticket-detailed', 'vouchers.index', 'operate', ['status' => 'active'], null, 'active'],
        ],
        'Paket' => [
            ['Profil / Paket', 'bi-collection', 'hotspot-profiles.index', 'manage-settings', [], 'hotspot-profiles.*'],
        ],
        'Infrastruktur' => [
            ['Router / NAS', 'bi-router', 'routers.index', 'manage-settings', [], 'routers.*'],
        ],
        'VPN WireGuard' => [
            ['Router / Peer VPN', 'bi-shield-lock', 'wireguard.index', 'manage-settings', [], null, 'routers'],
            ['Port Forwarding (NAT)', 'bi-arrow-left-right', 'wireguard.index', 'manage-settings', ['tab' => 'forwards'], null, 'forwards'],
            ['Server WireGuard', 'bi-gear', 'wireguard.index', 'manage-settings', ['tab' => 'settings'], null, 'settings'],
            ['Log WireGuard', 'bi-journal-text', 'wireguard.index', 'manage-settings', ['tab' => 'logs'], null, 'logs'],
        ],
        'Laporan' => [
            ['Penjualan Hotspot', 'bi-bar-chart-line', 'reports.sales', 'operate'],
            ['Pembayaran PPPoE', 'bi-wallet2', 'payments.index', 'operate'],
            ['Pemakaian Data', 'bi-graph-up-arrow', 'reports.usage', 'operate'],
            ['Laporan Penagihan', 'bi-wallet2', 'reports.penagihan', 'operate'],
        ],
        'Administrasi' => [
            ['Kelola Admin', 'bi-person-gear', 'users.index', 'manage-users', [], 'users.*'],
            ['Config GenieACS', 'bi-hdd-rack', 'settings.genieacs.edit', 'manage-settings'],
            ['Audit Log', 'bi-journal-text', 'settings.audit', 'view-audit'],
            ['Backup / Restore', 'bi-cloud-download', 'settings.backup', 'manage-settings'],
            ['Pengaturan', 'bi-sliders', 'settings.general', 'manage-settings'],
        ],
    ];

    // Menu yang memakai route sama (voucher, WireGuard, wizard ONT) dibedakan dari query string.
    $isActive = function (array $item): bool {
        [, , $route, , $params, $pattern, $variant] = $item + [3 => null, 4 => [], 5 => null, 6 => null];
        if (! request()->routeIs($pattern ?? $route)) {
            return false;
        }

        return match ($route) {
            'vouchers.index' => ($variant === 'active') === (request('status') === 'active'),
            'wireguard.index' => request('tab', 'routers') === $variant || ($variant === 'routers' && request()->routeIs('wireguard.show', 'wireguard.create', 'wireguard.edit')),
            'auto-config' => request('service', 'pppoe') === $variant,
            default => true,
        };
    };
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
