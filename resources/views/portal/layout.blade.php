<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Portal Pelanggan') — S.NET</title>
<link rel="icon" type="image/png" href="{{ asset('snet/logo.png') }}">
<link href="https://fonts.googleapis.com/css2?family=Exo+2:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('snet/portal.css') }}?v={{ filemtime(public_path('snet/portal.css')) }}">
<script>(function(){const t=localStorage.getItem('snet-portal-theme')||'light';if(t==='dark')document.documentElement.setAttribute('data-theme','dark');})();</script>
@stack('head')
</head>
<body>
@yield('header')
<div class="wrap">
    @if (session('status'))<div class="alert aok">✅ {{ session('status') }}</div>@endif
    @if ($errors->any())<div class="alert aerr">⚠️ {{ $errors->first() }}</div>@endif
    @yield('content')
</div>
<script>
function tpw(elId,btn){const el=document.getElementById(elId);const shown=el.dataset.show==='1';el.textContent=shown?'••••••••':el.dataset.val;el.dataset.show=shown?'0':'1';btn.textContent=shown?'👁':'🙈';}
function cpTxt(t,btn){navigator.clipboard.writeText(t).then(()=>{const o=btn.textContent;btn.textContent='✓';setTimeout(()=>btn.textContent=o,1600);});}
function syncPortalThemeUI(theme){const m=document.querySelector('.p-moon-icon'),s=document.querySelector('.p-sun-icon');if(m&&s){m.style.display=theme==='dark'?'none':'inline';s.style.display=theme==='dark'?'inline':'none';}}
function togglePortalTheme(){const next=(document.documentElement.getAttribute('data-theme')||'light')==='dark'?'light':'dark';document.documentElement.setAttribute('data-theme',next);localStorage.setItem('snet-portal-theme',next);syncPortalThemeUI(next);}
syncPortalThemeUI(document.documentElement.getAttribute('data-theme')||'light');
</script>
@stack('scripts')
</body>
</html>
