<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Portal Pelanggan — S.NET</title>
<link rel="icon" type="image/png" href="{{ asset('snet/logo.png') }}">
<link href="https://fonts.googleapis.com/css2?family=Exo+2:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--red:#D42B2B;--blue:#1B3FA6;--blue-d:#122B7A;--g50:#F8FAFF;--g100:#F0F3FA;--g200:#E0E6F5;--g400:#8A95B8;--g700:#3A4468}
body{font-family:'Exo 2',sans-serif;min-height:100vh;background:var(--g50);display:flex;flex-direction:column}
.bg{position:fixed;inset:0;background:linear-gradient(135deg,#0D1B5E 0%,var(--blue-d) 45%,#8B1414 100%);z-index:0}
.bg::before{content:'';position:absolute;top:-200px;right:-200px;width:600px;height:600px;border-radius:50%;border:1px solid rgba(255,255,255,.05)}
.bg::after{content:'';position:absolute;bottom:-200px;left:-200px;width:700px;height:700px;border-radius:50%;border:1px solid rgba(255,255,255,.05)}
.wrap{position:relative;z-index:1;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px;gap:14px}
.card{background:#fff;border-radius:20px;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(18,43,122,.35);overflow:hidden}
.maint-card{background:rgba(15,23,42,.85);border:1px solid rgba(220,38,38,.4);border-radius:20px;width:100%;max-width:460px;padding:32px 28px;text-align:center;backdrop-filter:blur(12px)}
.maint-icon{font-size:3.5rem;margin-bottom:14px}
.maint-title{color:#FCA5A5;font-size:1.3rem;font-weight:800;margin-bottom:8px}
.maint-body{color:rgba(255,255,255,.65);font-size:.86rem;line-height:1.6;margin-bottom:16px}
.maint-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(220,38,38,.2);border:1px solid rgba(220,38,38,.5);color:#FCA5A5;padding:5px 14px;border-radius:20px;font-size:.75rem;font-weight:700}
.notif-wrap{width:100%;max-width:420px;display:flex;flex-direction:column;gap:8px}
.notif{backdrop-filter:blur(8px);border-radius:12px;padding:12px 14px;border:1px solid rgba(255,255,255,.15);display:flex;gap:10px;align-items:flex-start}
.notif.info{background:rgba(29,78,216,.18);border-color:rgba(29,78,216,.35)}
.notif.warning{background:rgba(217,119,6,.18);border-color:rgba(217,119,6,.35)}
.notif.success{background:rgba(22,163,74,.18);border-color:rgba(22,163,74,.35)}
.notif-icon{font-size:1.1rem;flex-shrink:0;margin-top:1px}
.notif-title{color:#fff;font-weight:700;font-size:.83rem}
.notif-body{color:rgba(255,255,255,.65);font-size:.75rem;margin-top:3px;line-height:1.5}
.hero{background:linear-gradient(135deg,var(--blue-d),var(--blue));padding:28px 28px 22px;text-align:center;position:relative}
.hero::after{content:'';position:absolute;bottom:-1px;left:0;right:0;height:20px;background:#fff;clip-path:ellipse(55% 100% at 50% 100%)}
.hero .logo-box{background:#ffffff;padding:8px 18px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 6px 20px rgba(0,0,0,.15);margin-bottom:12px}
.hero .logo-box img{max-width:170px;max-height:50px;object-fit:contain;display:block}
.hero-t{color:rgba(255,255,255,.8);font-size:.78rem;letter-spacing:.5px;font-weight:600}
.body{padding:28px}
.t1{font-size:1.3rem;font-weight:800;color:var(--blue-d);margin-bottom:3px}
.t2{color:var(--g400);font-size:.82rem;margin-bottom:22px}
.fl{display:block;font-size:.7rem;font-weight:700;color:var(--g700);text-transform:uppercase;letter-spacing:1px;margin-bottom:5px}
.fc{width:100%;padding:11px 14px;border:2px solid var(--g200);border-radius:10px;font-family:'Exo 2',sans-serif;font-size:.92rem;color:var(--g700);background:var(--g50);transition:.2s;outline:none}
.fc:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 4px rgba(27,63,166,.08)}
.fg{margin-bottom:16px}
.sbtn{width:100%;padding:13px;background:linear-gradient(135deg,var(--blue),var(--blue-d));color:#fff;border:none;border-radius:10px;font-family:'Exo 2',sans-serif;font-size:.95rem;font-weight:700;letter-spacing:1px;cursor:pointer;transition:.3s;margin-top:4px}
.sbtn:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(27,63,166,.4)}
.err{background:#FEE2E2;border-left:4px solid var(--red);color:#DC2626;padding:10px 14px;border-radius:8px;font-size:.84rem;margin-bottom:16px}
.foot{text-align:center;padding:14px 28px 20px;font-size:.75rem;color:var(--g400);border-top:1px solid var(--g100)}
.foot a{color:var(--blue);font-weight:600;text-decoration:none}
[data-theme="dark"] .card{background:#1E293B;border:1px solid #334155}
[data-theme="dark"] .hero::after{background:#1E293B}
[data-theme="dark"] .t1{color:#60A5FA}
[data-theme="dark"] .fl{color:#CBD5E1}
[data-theme="dark"] .fc{background:#0F172A;border-color:#334155;color:#F8FAFC}
[data-theme="dark"] .foot{border-top-color:#334155;color:#94A3B8}
</style>
<script>(function(){const t=localStorage.getItem('snet-portal-theme')||'light';if(t==='dark')document.documentElement.setAttribute('data-theme','dark');})();</script>
</head>
<body>
<div class="bg"></div>
<div class="wrap">
@if ($maintenance)
    <div class="maint-card">
        <div class="maint-icon">🔧</div>
        <div class="maint-title">{{ $maintenance->title }}</div>
        @if ($maintenance->body)<div class="maint-body">{!! nl2br(e($maintenance->body)) !!}</div>@endif
        <div class="maint-badge">⛔ Portal Sementara Tidak Tersedia</div>
        <div style="margin-top:18px;color:rgba(255,255,255,.4);font-size:.74rem">Hubungi admin atau teknisi S.NET untuk informasi lebih lanjut</div>
    </div>
@else
    @if ($announcements->isNotEmpty())
        <div class="notif-wrap">
            @foreach ($announcements as $a)
                <div class="notif {{ in_array($a->type, ['success', 'release'], true) ? 'success' : $a->type }}">
                    <span class="notif-icon">{{ ['info' => 'ℹ️', 'warning' => '⚠️', 'success' => '✅', 'release' => '🚀'][$a->type] ?? '📢' }}</span>
                    <div><div class="notif-title">{{ $a->title }}</div>@if ($a->body)<div class="notif-body">{!! nl2br(e($a->body)) !!}</div>@endif</div>
                </div>
            @endforeach
        </div>
    @endif
    <div class="card">
        <div class="hero">
            <div class="logo-box"><img src="{{ asset('snet/logo.png') }}" alt="S.NET"></div>
            <div class="hero-t">Portal Pelanggan — Self-Service WiFi</div>
        </div>
        <div class="body">
            <div class="t1">Masuk Portal</div>
            <div class="t2">Gunakan ID Pelanggan dari teknisi S.NET Anda</div>
            @error('username')<div class="err">⚠️ {{ $message }}</div>@enderror
            <form method="POST" action="{{ route('portal.login.attempt') }}">
                @csrf
                <div class="fg"><label class="fl" for="username">Username Portal</label>
                    <input type="text" id="username" name="username" class="fc" placeholder="Masukkan Username Portal" required autocomplete="username" autofocus value="{{ old('username') }}"></div>
                <div class="fg"><label class="fl" for="password">Password</label>
                    <input type="password" id="password" name="password" class="fc" placeholder="••••••••" required autocomplete="current-password"></div>
                <button type="submit" class="sbtn">🚀 Masuk ke Portal</button>
            </form>
        </div>
        <div class="foot">Lupa password? Hubungi admin atau teknisi S.NET &bull; <a href="{{ route('voucher.check') }}">Cek voucher</a><br>&copy; {{ date('Y') }} {{ config('snet.company') }}</div>
    </div>
@endif
</div>
</body>
</html>
