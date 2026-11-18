@if (in_array((int) $pf->target_port, [80, 8080], true))
<a href="http://{{ $vpsHost }}:{{ $pf->public_port }}" target="_blank" class="btn btn-xs btn-outline-info"><i class="bi bi-box-arrow-up-right me-1"></i> Buka Webfig</a>
@elseif ((int) $pf->target_port === 8291)
<span class="text-success small fw-bold font-monospace">Winbox: {{ $vpsHost }}:{{ $pf->public_port }}</span>
@elseif ((int) $pf->target_port === 8728)
<span class="text-primary small fw-semibold">MikroTik API</span>
@elseif ((int) $pf->target_port === 22)
<span class="text-dark small font-monospace">ssh -p {{ $pf->public_port }} admin@{{ $vpsHost }}</span>
@else
<span class="text-muted small">Port {{ $pf->target_port }}</span>
@endif
