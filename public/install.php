<?php

/**
 * S.NET MANAGER — Installer web.
 *
 * Buka https://domain-anda/install.php, isi koneksi MySQL & akun admin, klik Install.
 * Installer menulis .env, membuat APP_KEY, menjalankan migrasi + seed, membuat admin,
 * lalu mengunci dirinya (storage/app/installed.lock). Setelah terpasang, file ini menolak dijalankan lagi.
 */

declare(strict_types=1);
use App\Enums\Role;
use App\Models\User;
use App\Services\Settings\SnetSettings;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Output\BufferedOutput;

const MIN_PHP = '8.3.0';
$base = dirname(__DIR__);
$lockFile = $base.'/storage/app/installed.lock';
$envFile = $base.'/.env';

session_name('snet_installer');
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));

function h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/**
 * Sudah terpasang = ada lock file, atau .env berisi APP_KEY (pemasangan manual).
 * .env yang ditulis installer tapi gagal di tengah jalan ditandai SNET_INSTALLING=1 agar installer bisa diulang.
 */
function isInstalled(string $lockFile, string $envFile): bool
{
    if (is_file($lockFile)) {
        return true;
    }
    $env = is_file($envFile) ? (string) file_get_contents($envFile) : '';

    return preg_match('/^APP_KEY=\S+/m', $env) === 1 && ! str_contains($env, 'SNET_INSTALLING=1');
}

/** @return list<array{label: string, ok: bool, hint: string}> */
function requirements(string $base): array
{
    $checks = [['label' => 'PHP >= '.MIN_PHP.' (terpasang '.PHP_VERSION.')', 'ok' => version_compare(PHP_VERSION, MIN_PHP, '>='), 'hint' => 'Ganti versi PHP website ke 8.3 atau lebih baru.']];
    foreach (['pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'dom', 'xml', 'ctype', 'fileinfo', 'curl', 'sodium', 'bcmath'] as $ext) {
        $checks[] = ['label' => "Ekstensi PHP {$ext}", 'ok' => extension_loaded($ext), 'hint' => "Aktifkan ekstensi {$ext} di pengaturan PHP."];
    }
    foreach (['', 'storage', 'storage/app', 'storage/framework', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $dir) {
        $path = $base.($dir === '' ? '' : '/'.$dir);
        $checks[] = ['label' => 'Folder '.($dir === '' ? '(root aplikasi, untuk .env)' : $dir).' bisa ditulis', 'ok' => is_dir($path) && is_writable($path), 'hint' => "chown/chmod folder {$path} agar bisa ditulis user web server."];
    }
    $checks[] = ['label' => 'Dependensi Composer (folder vendor)', 'ok' => is_file($base.'/vendor/autoload.php'), 'hint' => 'Klik tombol "Install Dependensi" di bawah, atau jalankan: composer install --no-dev'];

    return $checks;
}

/**
 * Cek file/perintah di luar folder aplikasi. Dengan open_basedir aktif (Anti-XSS aaPanel) PHP tidak boleh
 * menyentuh /etc, /usr, dll., jadi pengecekan dilakukan lewat perintah `test` (proses terpisah, tidak terkena open_basedir).
 */
function sysFile(string $path, string $flag = 'f'): bool
{
    if ((string) ini_get('open_basedir') === '') {
        return $flag === 'x' ? @is_executable($path) : @is_file($path);
    }

    return sh(['test', '-'.$flag, $path], 5)[0] === 0;
}

function phpCli(): string
{
    return sysFile(PHP_BINDIR.'/php', 'x') ? PHP_BINDIR.'/php' : 'php';
}

function findComposer(string $base): ?array
{
    if (is_file($base.'/composer.phar')) {
        return [phpCli(), $base.'/composer.phar'];
    }
    foreach (array_merge(explode(PATH_SEPARATOR, (string) getenv('PATH')), ['/usr/local/bin', '/usr/bin', '/opt/homebrew/bin']) as $dir) {
        if ($dir !== '' && sysFile($dir.'/composer', 'x')) {
            return [$dir.'/composer'];
        }
    }

    return null;
}

/** @return array{0: bool, 1: string} */
function runComposer(string $base): array
{
    if (! function_exists('proc_open')) {
        return [false, 'Fungsi proc_open dinonaktifkan di PHP. Jalankan manual di terminal: cd '.$base.' && composer install --no-dev --optimize-autoloader'];
    }
    $composer = findComposer($base);
    if ($composer === null) {
        return [false, 'Composer tidak ditemukan di server. Upload paket yang sudah berisi folder vendor, atau pasang composer lalu jalankan: composer install --no-dev --optimize-autoloader'];
    }
    @set_time_limit(0);
    $home = $base.'/storage/app/composer-home';
    @mkdir($home, 0775, true);
    $proc = proc_open(array_merge($composer, ['install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress']),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $base, ['COMPOSER_HOME' => $home, 'HOME' => $home, 'PATH' => (string) getenv('PATH')]);
    if (! is_resource($proc)) {
        return [false, 'Gagal menjalankan composer.'];
    }
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    $code = proc_close($proc);

    return [$code === 0 && is_file($base.'/vendor/autoload.php'), trim($out)];
}

const SETUP_BIN = '/usr/local/sbin/snet-setup';

/** Jalankan perintah (array, tanpa shell). @return array{0: int, 1: string} [exit code, output] */
function sh(array $cmd, int $timeout = 900): array
{
    if (! function_exists('proc_open')) {
        return [127, 'proc_open dinonaktifkan'];
    }
    @set_time_limit($timeout + 30);
    $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin']);
    if (! is_resource($proc)) {
        return [127, 'gagal menjalankan '.$cmd[0]];
    }
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

    return [proc_close($proc), trim((string) $out)];
}

function serviceActive(string $unit): bool
{
    return sh(['systemctl', 'is-active', '--quiet', $unit], 10)[0] === 0;
}

function hasBin(string ...$paths): bool
{
    foreach ($paths as $p) {
        if (sysFile($p, 'x')) {
            return true;
        }
    }

    return false;
}

function portOpen(string $host, int $port): bool
{
    $fp = @fsockopen($host, $port, $errno, $errstr, 2);
    if (is_resource($fp)) {
        fclose($fp);

        return true;
    }

    return false;
}

/** Web user boleh menjalankan pemasang server (root) lewat sudo tanpa password? */
function canAutoSetup(): bool
{
    return PHP_OS_FAMILY === 'Linux' && sysFile(SETUP_BIN) && sh(['sudo', '-n', SETUP_BIN, 'check'], 15)[0] === 0;
}

/**
 * Status service server yang dibutuhkan S.NET MANAGER.
 *
 * @return list<array{key: string, label: string, ok: bool, detail: string, step: string}>
 */
function services(string $base): array
{
    if (PHP_OS_FAMILY !== 'Linux') {
        return [];
    }
    $envInstalled = is_file($base.'/.env');

    return [
        ['key' => 'mysql', 'label' => 'MySQL / MariaDB', 'step' => 'mysql',
            'ok' => portOpen('127.0.0.1', 3306) || serviceActive('mysql') || serviceActive('mariadb'),
            'detail' => 'Database aplikasi & FreeRADIUS'],
        ['key' => 'freeradius', 'label' => 'FreeRADIUS', 'step' => 'freeradius',
            'ok' => hasBin('/usr/sbin/freeradius') && ($envInstalled ? serviceActive('freeradius') : true),
            'detail' => 'Login hotspot & PPPoE (UDP 1812/1813)'],
        ['key' => 'wireguard', 'label' => 'WireGuard VPN', 'step' => 'wireguard',
            'ok' => hasBin('/usr/bin/wg') && serviceActive('wg-quick@wg0'),
            'detail' => 'Tunnel ke router MikroTik & remote ONT'],
        ['key' => 'whatsapp', 'label' => 'Layanan WhatsApp (Node.js)', 'step' => 'whatsapp',
            'ok' => hasBin('/usr/bin/node', '/usr/local/bin/node') && serviceActive('snet-wa'),
            'detail' => 'Notifikasi tagihan & chat (snet-wa)'],
        ['key' => 'queue', 'label' => 'Queue Worker', 'step' => 'queue',
            'ok' => sysFile('/etc/systemd/system/snet-queue.service') && ($envInstalled ? serviceActive('snet-queue') : true),
            'detail' => 'Menjalankan proses config ONT di latar belakang'],
        ['key' => 'cron', 'label' => 'Cron Scheduler', 'step' => 'cron',
            'ok' => sysFile('/etc/cron.d/snet-manager'),
            'detail' => 'Voucher kadaluarsa, isolir, pengingat WA, backup'],
        ['key' => 'sudo', 'label' => 'Izin sistem aplikasi (sudo)', 'step' => 'sudoers',
            'ok' => canAutoSetup(),
            'detail' => 'Restart FreeRADIUS/WireGuard, iptables, pasang otomatis'],
    ];
}

/** Pasang komponen yang belum ada lewat sudo snet-setup. @return array{0: bool, 1: string} */
function autoSetup(array $steps): array
{
    $log = [];
    $ok = true;
    foreach (array_unique($steps) as $step) {
        [$code, $out] = sh(['sudo', '-n', SETUP_BIN, $step]);
        $log[] = $out !== '' ? $out : "[snet-setup] {$step}: selesai (kode {$code})";
        $ok = $ok && $code === 0;
    }

    return [$ok, implode("\n", $log)];
}

/** Ganti / tambah KEY=value di isi .env. */
function setEnv(string $content, string $key, string $value): string
{
    // Kutip ganda dengan escape \\ \" \$ (tanpa interpolasi ${...}) — aman untuk password berisi karakter apa pun.
    $quoted = preg_match('/^[A-Za-z0-9_.:\/@\-]*$/', $value) === 1 ? $value : '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    $line = $key.'='.$quoted;
    $pattern = '/^#?\s*'.preg_quote($key, '/').'=.*$/m';

    return preg_match($pattern, $content) === 1 ? preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $content, 1) : rtrim($content)."\n".$line."\n";
}

/** @return array<string, string> pesan error per field */
function validate(array $in): array
{
    $e = [];
    if (! filter_var($in['app_url'], FILTER_VALIDATE_URL)) {
        $e['app_url'] = 'URL aplikasi tidak valid (contoh: https://dash.domain.com).';
    }
    foreach (['db_host' => 'Host database', 'db_name' => 'Nama database', 'db_user' => 'User database', 'admin_name' => 'Nama admin', 'admin_username' => 'Username admin'] as $k => $label) {
        if (trim($in[$k]) === '') {
            $e[$k] = "{$label} wajib diisi.";
        }
    }
    if (! preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $in['db_name'])) {
        $e['db_name'] = 'Nama database hanya boleh huruf, angka, _ dan -.';
    }
    if (! ctype_digit($in['db_port']) || (int) $in['db_port'] < 1 || (int) $in['db_port'] > 65535) {
        $e['db_port'] = 'Port tidak valid.';
    }
    if (! preg_match('/^[A-Za-z0-9._\-]{3,64}$/', $in['admin_username'])) {
        $e['admin_username'] = 'Username 3-64 karakter: huruf, angka, titik, _ atau -.';
    }
    if (! filter_var($in['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $e['admin_email'] = 'Email admin tidak valid.';
    }
    if (strlen($in['admin_password']) < 8 || ! preg_match('/[A-Za-z]/', $in['admin_password']) || ! preg_match('/\d/', $in['admin_password'])) {
        $e['admin_password'] = 'Password minimal 8 karakter dan berisi huruf serta angka.';
    } elseif ($in['admin_password'] !== $in['admin_password_confirmation']) {
        $e['admin_password_confirmation'] = 'Konfirmasi password tidak sama.';
    }

    return $e;
}

/**
 * Jalankan instalasi. @return array{0: bool, 1: string} [sukses, log]
 */
function install(string $base, string $envFile, string $lockFile, array $in): array
{
    @set_time_limit(0);
    $log = [];

    // 1. Koneksi MySQL (+ buat database bila belum ada & diizinkan).
    try {
        $dsn = "mysql:host={$in['db_host']};port={$in['db_port']};charset=utf8mb4";
        $pdo = new PDO($dsn, $in['db_user'], $in['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
        if ($in['create_db']) {
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$in['db_name'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
        $pdo->exec('USE `'.$in['db_name'].'`');
        $log[] = '✔ Terhubung ke MySQL '.$pdo->getAttribute(PDO::ATTR_SERVER_VERSION).", database `{$in['db_name']}` siap.";
    } catch (PDOException $ex) {
        return [false, 'Koneksi database gagal: '.$ex->getMessage()."\nPeriksa host, port, nama database, user & password. Pastikan user punya hak akses ke database tersebut."];
    }

    // 2. Tulis .env (cadangkan yang lama bila ada).
    $template = is_file($base.'/.env.example') ? (string) file_get_contents($base.'/.env.example') : '';
    if (is_file($envFile)) {
        @copy($envFile, $envFile.'.backup-'.date('YmdHis'));
    }
    $env = $template;
    foreach ([
        'APP_NAME' => 'S.NET MANAGER', 'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_URL' => rtrim($in['app_url'], '/'),
        'APP_TIMEZONE' => $in['timezone'], 'LOG_LEVEL' => 'warning',
        'DB_CONNECTION' => 'mysql', 'DB_HOST' => $in['db_host'], 'DB_PORT' => $in['db_port'],
        'DB_DATABASE' => $in['db_name'], 'DB_USERNAME' => $in['db_user'], 'DB_PASSWORD' => $in['db_pass'],
        'PROVISIONING_MODE' => $in['provisioning_mode'],
        // AdminUserSeeder melewati email ini karena admin dibuat oleh installer; password tidak disimpan di .env.
        'ADMIN_NAME' => $in['admin_name'], 'ADMIN_EMAIL' => $in['admin_email'], 'ADMIN_PASSWORD' => '',
        'SNET_INSTALLING' => '1',
    ] as $k => $v) {
        $env = setEnv($env, $k, (string) $v);
    }
    try {
        require_once $base.'/vendor/autoload.php';
        Dotenv\Dotenv::parse($env); // pastikan .env valid sebelum ditulis (Laravel berhenti diam-diam bila .env rusak)
    } catch (Throwable $ex) {
        return [false, 'Isi .env tidak valid: '.$ex->getMessage()];
    }
    if (file_put_contents($envFile, $env) === false) {
        return [false, "Gagal menulis {$envFile}. Pastikan folder aplikasi bisa ditulis web server."];
    }
    @chmod($envFile, 0640);
    $log[] = '✔ File .env dibuat (APP_KEY baru).';

    // 3. Boot Laravel lalu migrasi, admin, seed. Cache config lama (dari paket) akan mengabaikan .env baru.
    @unlink($base.'/bootstrap/cache/config.php');
    try {
        $app = require $base.'/bootstrap/app.php';
        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();
        $out = new BufferedOutput;

        $kernel->call('config:clear', [], $out);
        if ($kernel->call('migrate', ['--force' => true], $out) !== 0) {
            throw new RuntimeException("Migrasi gagal:\n".$out->fetch());
        }
        $log[] = '✔ Tabel database dibuat (migrasi).';

        $userModel = User::class;
        $user = $userModel::where('email', $in['admin_email'])->orWhere('username', $in['admin_username'])->first() ?? new $userModel;
        $user->forceFill([
            'name' => $in['admin_name'], 'username' => $in['admin_username'], 'email' => $in['admin_email'],
            'password' => $in['admin_password'], 'role' => Role::Admin, 'is_active' => true,
        ])->save();
        $log[] = "✔ Akun admin {$in['admin_username']} siap.";

        if ($kernel->call('db:seed', ['--force' => true], $out) !== 0) {
            throw new RuntimeException("Seeder gagal:\n".$out->fetch());
        }
        $log[] = '✔ Data awal (model ONT, template, server GenieACS) dibuat.';

        // Kunci publik server WireGuard yang dibuat skrip setup → isi otomatis Pengaturan WireGuard.
        if (is_readable($pubFile = $base.'/storage/app/wg_server_public.key') && strlen($pub = trim((string) file_get_contents($pubFile))) === 44) {
            $host = (string) parse_url($in['app_url'], PHP_URL_HOST);
            $app->make(SnetSettings::class)->put('wireguard', ['wg_server_pubkey' => $pub, 'wg_server_endpoint' => $host.':51820']);
            $log[] = '✔ Kunci server WireGuard diisi otomatis.';
        }

        try {
            $kernel->call('storage:link', [], $out);
        } catch (Throwable) {
            // symlink bisa dilarang hosting; tidak wajib.
        }
        $kernel->call('optimize:clear', [], $out);
    } catch (Throwable $ex) {
        return [false, implode("\n", $log)."\n✘ ".$ex->getMessage()];
    }

    // 4. Hapus penanda proses & kunci installer.
    file_put_contents($envFile, preg_replace('/^SNET_INSTALLING=1\R?/m', '', (string) file_get_contents($envFile)));
    @mkdir(dirname($lockFile), 0775, true);
    file_put_contents($lockFile, 'Terpasang '.date('c').' oleh '.$in['admin_username']."\n");
    $log[] = '✔ Installer dikunci.';
    @unlink($base.'/storage/app/setup-db.json'); // kredensial sementara dari skrip setup tidak dibutuhkan lagi

    // 5. Sambungkan service server ke database baru (FreeRADIUS SQL, queue worker, WhatsApp).
    if (canAutoSetup()) {
        [$okSetup, $setupLog] = autoSetup(['post-install']);
        $log[] = ($okSetup ? '✔' : '!').' Service server disambungkan ke database:';
        $log[] = $setupLog;
    }

    return [true, implode("\n", $log)];
}

// ---------------------------------------------------------------- Controller sederhana
$installed = isInstalled($lockFile, $envFile);
$scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
$detectedUrl = $scheme.'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

$input = [
    'app_url' => $detectedUrl, 'timezone' => 'Asia/Jakarta', 'provisioning_mode' => 'simulation',
    'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => 'snet_manager', 'db_user' => '', 'db_pass' => '', 'create_db' => true,
    'admin_name' => 'Administrator', 'admin_username' => 'admin', 'admin_email' => '', 'admin_password' => '', 'admin_password_confirmation' => '',
];
if (is_readable($dbFile = $base.'/storage/app/setup-db.json') && is_array($pre = json_decode((string) file_get_contents($dbFile), true))) {
    $input = array_merge($input, ['db_host' => (string) ($pre['host'] ?? '127.0.0.1'), 'db_port' => (string) ($pre['port'] ?? '3306'),
        'db_name' => (string) ($pre['database'] ?? 'snet_manager'), 'db_user' => (string) ($pre['username'] ?? ''), 'db_pass' => (string) ($pre['password'] ?? '')]);
}
$errors = [];
$result = null;
$composerMsg = null;
$setupResult = null;

if (! $installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (! hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors['_'] = 'Sesi installer kedaluwarsa, muat ulang halaman.';
    } elseif (($_POST['action'] ?? '') === 'composer') {
        $composerMsg = runComposer($base);
    } elseif (($_POST['action'] ?? '') === 'autosetup') {
        $_SESSION['auto_tried'] = true;
        $missing = array_column(array_filter(services($base), fn ($s) => ! $s['ok'] && $s['step'] !== 'sudoers'), 'step');
        if (! is_file($base.'/vendor/autoload.php')) {
            array_unshift($missing, 'composer');
        }
        $setupResult = canAutoSetup() ? ($missing ? autoSetup($missing) : [true, 'Semua service sudah terpasang.'])
            : [false, 'Izin otomatis belum ada. Jalankan perintah root di bawah sekali saja.'];
    } else {
        foreach ($input as $k => $default) {
            $input[$k] = is_bool($default) ? isset($_POST[$k]) : trim((string) ($_POST[$k] ?? ''));
        }
        $input['db_pass'] = (string) ($_POST['db_pass'] ?? '') !== '' || ! isset($pre['password']) ? (string) ($_POST['db_pass'] ?? '') : (string) $pre['password'];
        $input['admin_password'] = (string) ($_POST['admin_password'] ?? '');
        $input['admin_password_confirmation'] = (string) ($_POST['admin_password_confirmation'] ?? '');
        $input['provisioning_mode'] = in_array($input['provisioning_mode'], ['simulation', 'production'], true) ? $input['provisioning_mode'] : 'simulation';
        $input['timezone'] = in_array($input['timezone'], ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'], true) ? $input['timezone'] : 'Asia/Jakarta';
        $errors = validate($input);
        if (! array_filter(requirements($base), fn ($c) => ! $c['ok']) && $errors === []) {
            $result = install($base, $envFile, $lockFile, $input);
            if ($result[0]) {
                $installed = true;
                $_SESSION = [];
            }
        } elseif ($errors === []) {
            $errors['_'] = 'Lengkapi persyaratan server yang bertanda merah terlebih dahulu.';
        }
    }
}

$services = $installed ? [] : services($base);
$servicesMissing = array_filter($services, fn ($s) => ! $s['ok']);
$autoSetup = $services !== [] && canAutoSetup();
$autoRun = $autoSetup && ($servicesMissing !== [] || ! is_file($base.'/vendor/autoload.php')) && empty($_SESSION['auto_tried']) && $_SERVER['REQUEST_METHOD'] === 'GET';
$reqs = requirements($base);
$reqOk = ! array_filter($reqs, fn ($c) => ! $c['ok']);
$vendorMissing = ! is_file($base.'/vendor/autoload.php');
$appUrl = $result[0] ?? false ? rtrim($input['app_url'], '/') : $detectedUrl;
$field = fn (string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$err = fn (string $k) => isset($errors[$k]) ? '<div class="invalid-feedback">'.h($errors[$k]).'</div>' : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Install — S.NET MANAGER</title>
    <link rel="icon" type="image/png" href="snet/logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: linear-gradient(135deg, #0d47a1 0%, #1565c0 45%, #c62828 100%); min-height: 100vh; font-family: Inter, system-ui, sans-serif; padding: 24px 12px; }
        .inst-card { max-width: 860px; margin: 0 auto; border: 0; border-radius: 18px; box-shadow: 0 20px 60px rgba(0,0,0,.25); }
        .inst-logo { height: 52px; }
        .sec-title { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: #1565c0; border-bottom: 2px solid #e3eaf6; padding-bottom: 6px; margin: 22px 0 14px; }
        .req-item { font-size: .85rem; }
        pre.log { background: #0f172a; color: #e2e8f0; border-radius: 10px; padding: 14px; font-size: .8rem; white-space: pre-wrap; max-height: 320px; overflow: auto; }
    </style>
</head>
<body>
<div class="card inst-card">
    <div class="card-body p-4 p-md-5">
        <div class="text-center mb-4">
            <img src="snet/logo.png" alt="S.NET" class="inst-logo mb-2">
            <h1 class="h4 fw-bold mb-0">Instalasi S.NET MANAGER</h1>
            <div class="text-muted small">RADIUS Hotspot · PPPoE · ONT GenieACS · WireGuard</div>
        </div>

<?php if ($installed) { ?>
        <?php if ($result[0] ?? false) { ?>
            <div class="alert alert-success"><i class="bi bi-check-circle-fill me-1"></i><strong>Instalasi berhasil!</strong> Silakan login dengan akun admin yang baru dibuat.</div>
            <pre class="log"><?= h($result[1]) ?></pre>
            <?php if (sysFile('/etc/cron.d/snet-manager')) { ?>
                <div class="alert alert-light border small"><i class="bi bi-check2-all text-success me-1"></i>Cron scheduler, FreeRADIUS, WireGuard, WhatsApp &amp; queue worker sudah dipasang dan disambungkan otomatis oleh skrip setup server.</div>
            <?php } else { ?>
                <div class="sec-title">Langkah terakhir di server (sekali saja)</div>
                <p class="small mb-1">Jalankan pemasang service otomatis (cron, FreeRADIUS, WireGuard, WhatsApp, queue worker) sebagai root:</p>
                <pre class="log">sudo bash <?= h($base) ?>/scripts/setup-server.sh</pre>
            <?php } ?>
            <p class="small mb-3"><i class="bi bi-shield-lock me-1"></i>Demi keamanan, hapus file <code>public/install.php</code> (installer sudah terkunci otomatis).</p>
            <a href="<?= h($appUrl) ?>/login" class="btn btn-primary btn-lg w-100"><i class="bi bi-box-arrow-in-right me-1"></i> Buka Halaman Login</a>
        <?php } else { ?>
            <div class="alert alert-info mb-3"><i class="bi bi-lock-fill me-1"></i>Aplikasi <strong>sudah terpasang</strong>. Installer dikunci agar konfigurasi tidak bisa ditimpa.</div>
            <p class="small text-muted">Untuk instal ulang dari nol, hapus file <code>storage/app/installed.lock</code> dan <code>.env</code> di server (data di database tidak ikut terhapus), lalu buka halaman ini lagi.</p>
            <a href="<?= h($detectedUrl) ?>/login" class="btn btn-primary w-100">Ke Halaman Login</a>
        <?php } ?>
<?php } else { ?>
        <?php if (isset($errors['_'])) { ?><div class="alert alert-danger"><?= h($errors['_']) ?></div><?php } ?>
        <?php if ($result && ! $result[0]) { ?>
            <div class="alert alert-danger"><i class="bi bi-x-circle-fill me-1"></i><strong>Instalasi gagal.</strong> Perbaiki masalah di bawah lalu klik Install lagi.</div>
            <pre class="log"><?= h($result[1]) ?></pre>
        <?php } ?>

        <div class="sec-title"><i class="bi bi-clipboard-check me-1"></i> 1. Persyaratan Server</div>
        <div class="row g-1">
            <?php foreach ($reqs as $r) { ?>
            <div class="col-md-6 req-item">
                <i class="bi <?= $r['ok'] ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?> me-1"></i><?= h($r['label']) ?>
                <?php if (! $r['ok']) { ?><div class="text-danger small ms-4"><?= h($r['hint']) ?></div><?php } ?>
            </div>
            <?php } ?>
        </div>
        <?php if ($vendorMissing) { ?>
            <form method="POST" class="mt-3" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').innerHTML = 'Memasang dependensi… (1–5 menit)';">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="composer">
                <button class="btn btn-warning"><i class="bi bi-box-seam me-1"></i> Install Dependensi (composer install)</button>
            </form>
            <?php if ($composerMsg) { ?><pre class="log mt-2"><?= h(($composerMsg[0] ? "✔ Berhasil\n" : "✘ Gagal\n").$composerMsg[1]) ?></pre><?php } ?>
        <?php } ?>

        <div class="sec-title"><i class="bi bi-hdd-stack me-1"></i> Service Server</div>
        <?php if (PHP_OS_FAMILY !== 'Linux') { ?>
            <div class="alert alert-secondary small mb-0"><i class="bi bi-info-circle me-1"></i>Server ini bukan Linux (<?= h(PHP_OS_FAMILY) ?>) — pengecekan &amp; pemasangan service otomatis hanya berjalan di VPS Ubuntu/Debian. Instalasi aplikasi tetap bisa dilanjutkan.</div>
        <?php } else { ?>
            <div class="row g-2">
                <?php foreach ($services as $sv) { ?>
                <div class="col-md-6">
                    <div class="d-flex align-items-start gap-2 p-2 border rounded <?= $sv['ok'] ? 'border-success-subtle bg-success-subtle' : 'border-danger-subtle bg-danger-subtle' ?>">
                        <i class="bi <?= $sv['ok'] ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?> mt-1"></i>
                        <div><div class="fw-semibold small"><?= h($sv['label']) ?> <span class="badge <?= $sv['ok'] ? 'bg-success' : 'bg-danger' ?>"><?= $sv['ok'] ? 'Terpasang' : 'Belum' ?></span></div><div class="text-muted" style="font-size:.75rem;"><?= h($sv['detail']) ?></div></div>
                    </div>
                </div>
                <?php } ?>
            </div>
            <?php if ($setupResult) { ?>
                <div class="alert <?= $setupResult[0] ? 'alert-success' : 'alert-warning' ?> mt-3 mb-2 small"><?= $setupResult[0] ? '✔ Pemasangan otomatis selesai.' : '! Sebagian komponen gagal dipasang — lihat log.' ?></div>
                <pre class="log"><?= h($setupResult[1]) ?></pre>
            <?php } ?>
            <?php if ($servicesMissing !== [] || $vendorMissing) { ?>
                <?php if ($autoSetup) { ?>
                    <form method="POST" id="autoSetupForm" class="mt-3" onsubmit="const b = this.querySelector('button'); b.disabled = true; b.innerHTML = '<span class=\'spinner-border spinner-border-sm me-2\'></span>Memasang service… (bisa 3–10 menit, jangan tutup halaman)';">
                        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="autosetup">
                        <button class="btn btn-success w-100"><i class="bi bi-magic me-1"></i> Pasang Otomatis Service yang Belum Ada</button>
                    </form>
                    <?php if ($autoRun) { ?><script>document.addEventListener('DOMContentLoaded', () => document.querySelector('#autoSetupForm button').click());</script><?php } ?>
                <?php } else { ?>
                    <div class="alert alert-warning small mt-3 mb-2">
                        <i class="bi bi-terminal me-1"></i><strong>Satu langkah sekali saja:</strong> web server tidak punya hak root untuk memasang paket sistem.
                        Login SSH ke VPS (atau buka Terminal di aaPanel) lalu jalankan perintah ini — semua service dipasang &amp; dikonfigurasi otomatis.
                        Setelah itu installer ini bisa memasang/memperbaiki service sendiri.
                    </div>
                    <div class="input-group">
                        <input class="form-control font-monospace small" id="setupCmd" readonly value="sudo bash <?= h($base) ?>/scripts/setup-server.sh">
                        <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('setupCmd').value); this.innerText = 'Tersalin';">Salin</button>
                        <a class="btn btn-primary" href="install.php"><i class="bi bi-arrow-repeat"></i> Cek Ulang</a>
                    </div>
                <?php } ?>
            <?php } ?>
        <?php } ?>

        <form method="POST" autocomplete="off" onsubmit="const b = this.querySelector('#btnInstall'); b.disabled = true; b.innerHTML = '<span class=\'spinner-border spinner-border-sm me-2\'></span>Menginstal… jangan tutup halaman';">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">

            <div class="sec-title"><i class="bi bi-globe me-1"></i> 2. Aplikasi</div>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">URL Aplikasi</label><input class="form-control<?= $field('app_url') ?>" name="app_url" value="<?= h($input['app_url']) ?>" required><?= $err('app_url') ?><div class="form-text">Terdeteksi otomatis dari alamat yang Anda buka.</div></div>
                <div class="col-md-3"><label class="form-label">Zona Waktu</label><select class="form-select" name="timezone">
                    <?php foreach (['Asia/Jakarta' => 'WIB', 'Asia/Makassar' => 'WITA', 'Asia/Jayapura' => 'WIT'] as $tz => $l) { ?><option value="<?= $tz ?>" <?= $input['timezone'] === $tz ? 'selected' : '' ?>><?= $l ?> (<?= $tz ?>)</option><?php } ?>
                </select></div>
                <div class="col-md-3"><label class="form-label">Mode Config ONT</label><select class="form-select" name="provisioning_mode">
                    <option value="simulation" <?= $input['provisioning_mode'] === 'simulation' ? 'selected' : '' ?>>Simulasi (aman)</option>
                    <option value="production" <?= $input['provisioning_mode'] === 'production' ? 'selected' : '' ?>>Produksi (kirim ke ONT)</option>
                </select></div>
            </div>

            <div class="sec-title"><i class="bi bi-database me-1"></i> 3. Database MySQL / MariaDB</div>
            <div class="row g-3">
                <div class="col-md-8"><label class="form-label">Host</label><input class="form-control<?= $field('db_host') ?>" name="db_host" value="<?= h($input['db_host']) ?>" required><?= $err('db_host') ?></div>
                <div class="col-md-4"><label class="form-label">Port</label><input class="form-control<?= $field('db_port') ?>" name="db_port" value="<?= h($input['db_port']) ?>" required><?= $err('db_port') ?></div>
                <div class="col-md-4"><label class="form-label">Nama Database</label><input class="form-control<?= $field('db_name') ?>" name="db_name" value="<?= h($input['db_name']) ?>" required><?= $err('db_name') ?></div>
                <div class="col-md-4"><label class="form-label">User</label><input class="form-control<?= $field('db_user') ?>" name="db_user" value="<?= h($input['db_user']) ?>" required autocomplete="off"><?= $err('db_user') ?></div>
                <div class="col-md-4"><label class="form-label">Password</label><input class="form-control" type="password" name="db_pass" autocomplete="new-password" placeholder="<?= isset($pre['password']) ? 'Terisi otomatis dari setup server' : '' ?>"></div>
                <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="create_db" id="create_db" <?= $input['create_db'] ? 'checked' : '' ?>><label class="form-check-label small" for="create_db">Buat database otomatis bila belum ada (butuh hak CREATE)</label></div></div>
            </div>

            <div class="sec-title"><i class="bi bi-person-badge me-1"></i> 4. Akun Super Admin</div>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Nama Lengkap</label><input class="form-control<?= $field('admin_name') ?>" name="admin_name" value="<?= h($input['admin_name']) ?>" required><?= $err('admin_name') ?></div>
                <div class="col-md-6"><label class="form-label">Username</label><input class="form-control<?= $field('admin_username') ?>" name="admin_username" value="<?= h($input['admin_username']) ?>" required autocomplete="off"><?= $err('admin_username') ?></div>
                <div class="col-md-12"><label class="form-label">Email</label><input class="form-control<?= $field('admin_email') ?>" type="email" name="admin_email" value="<?= h($input['admin_email']) ?>" required><?= $err('admin_email') ?></div>
                <div class="col-md-6"><label class="form-label">Password</label><input class="form-control<?= $field('admin_password') ?>" type="password" name="admin_password" required minlength="8" autocomplete="new-password"><?= $err('admin_password') ?><div class="form-text">Min. 8 karakter, huruf &amp; angka.</div></div>
                <div class="col-md-6"><label class="form-label">Ulangi Password</label><input class="form-control<?= $field('admin_password_confirmation') ?>" type="password" name="admin_password_confirmation" required minlength="8" autocomplete="new-password"><?= $err('admin_password_confirmation') ?></div>
            </div>

            <button id="btnInstall" class="btn btn-primary btn-lg w-100 mt-4" <?= $reqOk ? '' : 'disabled' ?>><i class="bi bi-rocket-takeoff me-1"></i> Install Sekarang</button>
            <?php if (! $reqOk) { ?><div class="text-danger small text-center mt-2">Lengkapi persyaratan server yang bertanda merah terlebih dahulu.</div><?php } ?>
        </form>
<?php } ?>
    </div>
</div>
<p class="text-center text-white-50 small mt-3">S.NET MANAGER · PT Network Inovation Solutions</p>
</body>
</html>
