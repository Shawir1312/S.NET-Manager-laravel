@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0 small">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif
