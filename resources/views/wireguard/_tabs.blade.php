@php($tab = request('tab', 'routers'))
<ul class="nav nav-tabs mb-3">
    @foreach (['routers' => ['Router / Peer', 'bi-router'], 'forwards' => ['Port Forwarding', 'bi-arrow-left-right'], 'settings' => ['Server', 'bi-gear'], 'logs' => ['Log', 'bi-journal-text']] as $key => [$label, $icon])
        <li class="nav-item"><a href="{{ route('wireguard.index', $key === 'routers' ? [] : ['tab' => $key]) }}" @class(['nav-link', 'active' => $tab === $key])><i class="bi {{ $icon }} me-1"></i>{{ $label }}</a></li>
    @endforeach
</ul>
