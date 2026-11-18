<?php

use App\Models\GenieAcsSetting;
use App\Services\GenieACS\Exceptions\GenieACSException;
use App\Services\GenieACS\GenieACSService;
use App\Services\Hotspot\VoucherService;
use App\Services\Network\OntRemoteService;
use App\Services\ONT\OntLookupService;
use App\Services\Pppoe\BillingService;
use App\Services\Settings\BackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('ont:sync', function () {
    // S.NET: semua server GenieACS aktif, ONT dicatat milik server masing-masing.
    $failed = 0;
    foreach (GenieAcsSetting::where('is_active', true)->get() as $server) {
        GenieACSService::use($server);
        try {
            $r = app(OntLookupService::class)->syncAll();
            $this->info("{$server->name}: {$r['synced']} ONT disinkronkan.");
        } catch (GenieACSException $e) {
            $this->error("{$server->name}: {$e->getMessage()}");
            $failed++;
        }
    }

    return $failed ? 1 : 0;
})->purpose('Sinkronkan daftar ONT dari GenieACS ke database aplikasi');

// Jalankan lewat cron: * * * * * php artisan schedule:run
Schedule::command('ont:sync')->everyFifteenMinutes()->withoutOverlapping();

// ---- S.NET (pengganti cron/*.php lama) ----

Artisan::command('snet:auto-isolir', function (BillingService $billing) {
    $r = $billing->runAutoIsolir();
    $this->info("Diisolir: {$r['isolated']} | Dibuka isolirnya: {$r['reactivated']}");
})->purpose('Isolir pelanggan PPPoE yang menunggak & buka isolir yang sudah lunas');

Artisan::command('snet:reminders', function (BillingService $billing) {
    $r = $billing->sendReminders();
    $this->info("Pengingat terkirim: H-3 {$r['reminder_h3']} | H-1 {$r['reminder_h1']} | H-0 {$r['reminder_h0']}");
})->purpose('Kirim pengingat tagihan WhatsApp H-3, H-1 dan hari H');

Schedule::command('snet:auto-isolir')->hourly()->withoutOverlapping();
Schedule::command('snet:reminders')->dailyAt('08:00')->withoutOverlapping();

Artisan::command('snet:vouchers-expire', function (VoucherService $vouchers) {
    $r = $vouchers->expire();
    $this->info("Voucher baru aktif: {$r['activated']} | kedaluwarsa: {$r['expired']}");
})->purpose('Aktifkan voucher yang mulai dipakai & kedaluwarsakan yang habis masa berlakunya');

Schedule::command('snet:vouchers-expire')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('snet:ont-remote-cleanup', function (OntRemoteService $remotes) {
    $this->info('Remote kedaluwarsa ditutup: '.$remotes->cleanupExpired());
})->purpose('Tutup port-forward remote ONT yang sudah lewat 15 menit');

Schedule::command('snet:ont-remote-cleanup')->everyMinute()->withoutOverlapping();

Artisan::command('snet:backup', function (BackupService $backups) {
    $this->info('Backup dibuat: '.$backups->create());
})->purpose('Backup database (simpan 14 terakhir)');

Schedule::command('snet:backup')->dailyAt('02:00')->withoutOverlapping();
