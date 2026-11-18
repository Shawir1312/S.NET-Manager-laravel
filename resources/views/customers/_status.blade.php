@php($tone = ['active' => 'success', 'isolated' => 'warning', 'suspended' => 'danger'][$status] ?? 'secondary')
@php($label = ['active' => 'Aktif', 'isolated' => 'Isolir', 'suspended' => 'Suspend'][$status] ?? $status)
<span class="badge text-bg-{{ $tone }}">{{ $label }}</span>
