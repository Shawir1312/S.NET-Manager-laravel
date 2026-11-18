<span class="badge text-bg-{{ $job->status->badge() }}">{{ $job->status->label() }}</span>
@if ($job->is_simulation)<span class="badge text-bg-warning">SIMULASI</span>@endif
