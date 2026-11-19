<?php

namespace App\Services\WireGuard;

use App\Models\WgLog;
use App\Models\WgPortForward;
use App\Models\WgRouter;
use App\Services\Settings\SnetSettings;
use Illuminate\Support\Facades\Process;

/**
 * Hub WireGuard VPS untuk router MikroTik cabang (port dari include/wireguard_functions.php).
 * Perintah `wg`/`iptables` hanya dijalankan di server Linux; di tempat lain cukup tersimpan di DB.
 */
class WireGuardService
{
    public const DEFAULTS = [
        'wg_interface' => 'wg0', 'wg_server_endpoint' => '', 'wg_remote_public_host' => '', 'wg_remote_port_range' => '20000-58000',
        'wg_server_pubkey' => '', 'wg_subnet_prefix' => '10.66.66.', 'wg_listen_port' => '51820', 'wg_dns' => '1.1.1.1, 8.8.8.8', 'wg_mtu' => '1420',
    ];

    public function __construct(private readonly SnetSettings $settings) {}

    /** @return array<string, string> */
    public function config(): array
    {
        $cfg = array_merge(self::DEFAULTS, array_filter($this->settings->all('wireguard'), fn ($v) => $v !== null && $v !== ''));
        if ($cfg['wg_server_endpoint'] === '') {
            $cfg['wg_server_endpoint'] = (parse_url((string) config('app.url'), PHP_URL_HOST) ?: '127.0.0.1').':'.$cfg['wg_listen_port'];
        }

        return $cfg;
    }

    /**
     * Keypair Curve25519 asli (sama dengan `wg genkey | wg pubkey`) tanpa butuh binary wg.
     * Versi lama memakai sha256 sebagai cadangan sehingga public key-nya tidak valid.
     *
     * @return array{private_key: string, public_key: string}
     */
    public static function keypair(): array
    {
        $priv = random_bytes(32);
        $priv[0] = chr(ord($priv[0]) & 248);
        $priv[31] = chr((ord($priv[31]) & 127) | 64);

        return ['private_key' => base64_encode($priv), 'public_key' => base64_encode(sodium_crypto_scalarmult_base($priv))];
    }

    public static function publicKeyOf(string $privateKey): string
    {
        return base64_encode(sodium_crypto_scalarmult_base(base64_decode($privateKey)));
    }

    public function nextTunnelIp(): string
    {
        $prefix = rtrim($this->config()['wg_subnet_prefix'], '.').'.';
        $used = WgRouter::pluck('tunnel_ip')->all();
        foreach (range(2, 254) as $i) {
            if (! in_array($prefix.$i, $used, true)) {
                return $prefix.$i;
            }
        }
        throw new \RuntimeException('Subnet tunnel penuh (253 router).');
    }

    /** @return list<string> CIDR jaringan yang valid & dinormalisasi */
    public static function normalizeSubnets(?string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,\s;]+/', trim((string) $raw)) ?: [] as $t) {
            [$ip, $mask] = array_pad(explode('/', $t, 2), 2, '32');
            $mask = (int) $mask;
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $mask < 0 || $mask > 32) {
                continue;
            }
            $net = long2ip(ip2long($ip) & ($mask === 0 ? 0 : (~((1 << (32 - $mask)) - 1) & 0xFFFFFFFF)));
            $out[] = "{$net}/{$mask}";
        }

        return array_values(array_unique($out));
    }

    /** Subnet tunnel + LAN cabang lain (untuk allowed-address & rute antar cabang). */
    private function allowedFor(WgRouter $router): array
    {
        $allowed = [rtrim($this->config()['wg_subnet_prefix'], '.').'.0/24'];
        foreach (WgRouter::where('id', '!=', $router->id)->pluck('lan_subnets') as $lans) {
            array_push($allowed, ...self::normalizeSubnets($lans));
        }

        return array_values(array_unique($allowed));
    }

    /** Nama interface khusus aplikasi ini — tidak bentrok dengan `wg-snet` dari S.NET lama / VPN lain. */
    public const MIKROTIK_IFACE = 'wg-snetmgr';

    /**
     * Skrip RouterOS v7 yang aman ditempel berulang kali: semua objek yang pernah dibuat skrip ini
     * (ditandai komentar "S.NET-MGR", atau cocok dengan kunci/IP tunnel router ini) dihapus dulu, lalu dibuat ulang.
     * Aturan firewall diletakkan di atas tanpa `place-before=0` (yang gagal bila daftar aturan masih kosong).
     */
    public function mikrotikScript(WgRouter $router): string
    {
        $c = $this->config();
        [$host, $port] = array_pad(explode(':', $c['wg_server_endpoint']), 2, $c['wg_listen_port']);
        $net = rtrim($c['wg_subnet_prefix'], '.').'.0';
        $allowed = $this->allowedFor($router);
        $lans = self::normalizeSubnets($router->lan_subnets);
        $if = self::MIKROTIK_IFACE;
        $top = fn (string $rule) => ":if ([:len [/ip firewall filter find dynamic=no]] > 0) do={/ip firewall filter add {$rule} place-before=[:pick [/ip firewall filter find dynamic=no] 0]} else={/ip firewall filter add {$rule}}";

        $lines = [
            '# S.NET MANAGER — WireGuard MikroTik RouterOS v7',
            "# Router: {$router->name} | Tunnel: {$router->tunnel_ip}/24 | ".now()->format('Y-m-d H:i'),
            '# Aman ditempel ulang: konfigurasi lama dari skrip ini dihapus dulu, interface VPN lain tidak disentuh.',
            ...($c['wg_server_pubkey'] === '' ? ['# PERINGATAN: Server Public Key belum diisi di Pengaturan Server WireGuard — isi dulu lalu salin ulang skrip ini.'] : []),
            '',
            '# 1. Bersihkan konfigurasi lama dari skrip ini',
            "/interface wireguard peers remove [find public-key=\"{$c['wg_server_pubkey']}\"]",
            "/interface wireguard remove [find private-key=\"{$router->private_key}\"]",
            "/interface wireguard remove [find name=\"{$if}\"]",
            "/ip address remove [find address=\"{$router->tunnel_ip}/24\"]",
            '/ip route remove [find comment="S.NET-MGR route"]',
            '/ip firewall filter remove [find comment~"^S.NET-MGR"]',
            '/ip firewall nat remove [find comment~"^S.NET-MGR"]',
            "/ip firewall nat remove [find comment=\"Masquerade WireGuard VPS to LAN & ONT\" src-address=\"{$net}/24\"]",
        ];
        foreach ($lans as $lan) {
            $lines[] = "/ip firewall nat remove [find comment=\"Masquerade to {$lan}\" dst-address=\"{$lan}\"]";
        }
        array_push($lines,
            '',
            '# 2. Interface, IP & peer ke VPS',
            "/interface wireguard add name={$if} listen-port={$router->listen_port} private-key=\"{$router->private_key}\" comment=\"S.NET-MGR tunnel\"",
            "/ip address add address={$router->tunnel_ip}/24 interface={$if} network={$net} comment=\"S.NET-MGR IP VPN\"",
            "/interface wireguard peers add interface={$if} public-key=\"{$c['wg_server_pubkey']}\" endpoint-address={$host} endpoint-port={$port} allowed-address=".implode(',', $allowed).' persistent-keepalive=25s comment="S.NET-MGR VPS hub"',
        );
        foreach (array_slice($allowed, 1) as $lan) {
            $lines[] = "/ip route add dst-address={$lan} gateway={$if} comment=\"S.NET-MGR route\"";
        }
        array_push($lines,
            '',
            '# 3. Izinkan akses dari VPS (diletakkan paling atas) & NAT ke LAN/ONT',
            $top("chain=input in-interface={$if} action=accept comment=\"S.NET-MGR allow input\""),
            $top("chain=forward in-interface={$if} action=accept comment=\"S.NET-MGR allow forward\""),
            "/ip firewall nat add chain=srcnat src-address={$net}/24 action=masquerade comment=\"S.NET-MGR masquerade VPS\"",
        );
        foreach ($lans as $lan) {
            $lines[] = "/ip firewall nat add chain=srcnat dst-address={$lan} action=masquerade comment=\"S.NET-MGR masquerade {$lan}\"";
        }

        return implode("\n", $lines)."\n";
    }

    public function clientConf(WgRouter $router): string
    {
        $c = $this->config();

        return "[Interface]\nPrivateKey = {$router->private_key}\nAddress = {$router->tunnel_ip}/32\nDNS = {$c['wg_dns']}\nMTU = {$c['wg_mtu']}\n\n"
            ."[Peer]\nPublicKey = {$c['wg_server_pubkey']}\nEndpoint = {$c['wg_server_endpoint']}\nAllowedIPs = ".implode(', ', $this->allowedFor($router))."\nPersistentKeepalive = 25\n";
    }

    // ---------- sinkron ke kernel Linux ----------

    private function run(string $cmd): bool
    {
        return PHP_OS_FAMILY === 'Linux' && Process::run($cmd)->successful();
    }

    public function syncPeer(WgRouter $router): bool
    {
        $iface = escapeshellarg($this->config()['wg_interface']);
        $allowed = implode(',', [$router->tunnel_ip.'/32', ...self::normalizeSubnets($router->lan_subnets)]);
        foreach (self::normalizeSubnets($router->lan_subnets) as $lan) {
            $this->run('sudo -n ip route replace '.escapeshellarg($lan).' dev '.$iface);
        }
        $ok = $this->run("sudo -n wg set {$iface} peer ".escapeshellarg($router->public_key).' allowed-ips '.escapeshellarg($allowed).' persistent-keepalive 25')
            && $this->run("sudo -n wg-quick save {$iface}");
        $this->log('peer_sync', $router, $ok ? 'OK' : 'wg tidak tersedia / gagal');

        return $ok;
    }

    public function removePeer(WgRouter $router): void
    {
        $iface = escapeshellarg($this->config()['wg_interface']);
        $this->run("sudo -n wg set {$iface} peer ".escapeshellarg($router->public_key).' remove') && $this->run("sudo -n wg-quick save {$iface}");
        $this->log('peer_removed', $router);
    }

    /** @return array<string, array{endpoint: string, handshake: int, rx: int, tx: int}> per public key */
    public function peerStatus(): array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return [];
        }
        $out = Process::run('sudo -n wg show '.escapeshellarg($this->config()['wg_interface']).' dump')->output();
        $status = [];
        foreach (array_slice(explode("\n", trim($out)), 1) as $line) {
            $p = explode("\t", $line);
            if (count($p) >= 7) {
                $status[$p[0]] = ['endpoint' => $p[2], 'handshake' => (int) $p[4], 'rx' => (int) $p[5], 'tx' => (int) $p[6]];
            }
        }

        return $status;
    }

    public function applyPortForward(WgPortForward $pf, bool $add = true): void
    {
        $ip = escapeshellarg($pf->target_ip ?: $pf->router->tunnel_ip);
        $proto = $pf->protocol === 'udp' ? 'udp' : 'tcp';
        $op = $add ? '-I' : '-D';
        $nat = $add ? '-A' : '-D';
        foreach ([
            "sudo -n iptables {$op} INPUT -p {$proto} --dport {$pf->public_port} -j ACCEPT",
            "sudo -n iptables {$op} FORWARD -p {$proto} -d {$ip} --dport {$pf->target_port} -j ACCEPT",
            "sudo -n iptables -t nat {$nat} PREROUTING -p {$proto} --dport {$pf->public_port} -j DNAT --to-destination {$ip}:{$pf->target_port}",
            "sudo -n iptables -t nat {$nat} POSTROUTING -p {$proto} -d {$ip} --dport {$pf->target_port} -j MASQUERADE",
        ] as $cmd) {
            $this->run($cmd);
        }
        $this->log($add ? 'port_forward_added' : 'port_forward_removed', $pf->router, "{$proto} {$pf->public_port} -> {$pf->target_port}");
    }

    /** @return array{success: bool, message: string} */
    public function testPort(WgRouter $router, int $port): array
    {
        $conn = @fsockopen($router->tunnel_ip, $port, $errno, $errstr, 2);
        if ($conn) {
            fclose($conn);

            return ['success' => true, 'message' => "Port {$port} terbuka."];
        }

        return ['success' => false, 'message' => "Port {$port} tidak merespons ({$errstr})."];
    }

    /** Host publik untuk akses remote (port forwarding): host khusus atau host dari endpoint. */
    public function remoteHost(): string
    {
        $c = $this->config();

        return $c['wg_remote_public_host'] ?: explode(':', $c['wg_server_endpoint'])[0];
    }

    /** Peer dianggap online bila handshake terakhir < 3 menit. */
    public static function isConnected(?array $status): bool
    {
        return $status !== null && $status['handshake'] > 0 && time() - $status['handshake'] < 180;
    }

    /** @return array{isWgInstalled: bool, isWgRunning: bool, serviceStatus: string, wgShowOutput: string} */
    public function serverStatus(): array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return ['isWgInstalled' => false, 'isWgRunning' => false, 'serviceStatus' => 'unknown', 'wgShowOutput' => ''];
        }
        $iface = $this->config()['wg_interface'];
        $status = trim(Process::run(['systemctl', 'is-active', "wg-quick@{$iface}"])->output()) ?: 'unknown';
        $installed = Process::run(['which', 'wg'])->successful();

        return ['isWgInstalled' => $installed || $status === 'active', 'isWgRunning' => $status === 'active', 'serviceStatus' => $status,
            'wgShowOutput' => Process::run(['sudo', '-n', 'wg', 'show'])->output()];
    }

    /** @return array{success: bool, status: string, message?: string} */
    public function restartService(): array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return ['success' => false, 'status' => 'unknown', 'message' => 'Server ini bukan Linux.'];
        }
        $iface = $this->config()['wg_interface'];
        $r = Process::run(['sudo', '-n', 'systemctl', 'restart', "wg-quick@{$iface}"]);
        $status = trim(Process::run(['systemctl', 'is-active', "wg-quick@{$iface}"])->output());
        $this->log('service_restarted', null, $status);

        return ['success' => $r->successful() && $status === 'active', 'status' => $status, 'message' => trim($r->errorOutput())];
    }

    public function ping(string $ip): string
    {
        $r = Process::timeout(10)->run(['ping', '-c', '3', PHP_OS_FAMILY === 'Darwin' ? '-t' : '-w', '5', $ip]);

        return trim($r->output()."\n".$r->errorOutput()) ?: 'Tidak ada output ping.';
    }

    /** Daftarkan ulang semua peer & NAT dari database ke kernel. @return array{success: bool, count: int, errors: list<string>} */
    public function syncAll(): array
    {
        $errors = [];
        $count = 0;
        foreach (WgRouter::with('portForwards')->get() as $router) {
            $this->syncPeer($router) ? $count++ : $errors[] = "Gagal sinkron peer {$router->name}.";
            $router->portForwards->each(fn (WgPortForward $pf) => $this->applyPortForward($pf));
        }

        return ['success' => $errors === [], 'count' => $count, 'errors' => $errors];
    }

    public function log(string $event, ?WgRouter $router = null, ?string $details = null): void
    {
        WgLog::create(['event' => $event, 'router_id' => $router?->id, 'router_name' => $router?->name, 'details' => $details, 'created_at' => now()]);
    }
}
