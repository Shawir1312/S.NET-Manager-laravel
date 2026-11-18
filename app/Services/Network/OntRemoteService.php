<?php

namespace App\Services\Network;

use App\Models\OntDevice;
use App\Models\OntRemote;
use App\Models\WgPortForward;
use App\Services\Settings\SnetSettings;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Akses web GUI ONT sementara (15 menit) lewat port-forward iptables di VPS.
 * Port dari ajax/ont_remote.php; perbedaan: IP tujuan harus IP ONT yang tercatat dari GenieACS.
 */
class OntRemoteService
{
    public const MINUTES = 15;

    public function __construct(private readonly SnetSettings $settings) {}

    public function publicHost(): string
    {
        return $this->settings->get('wireguard', 'wg_remote_public_host') ?: (parse_url((string) config('app.url'), PHP_URL_HOST) ?: '127.0.0.1');
    }

    public function start(OntDevice $device, int $targetPort = 80): OntRemote
    {
        $ip = (string) $device->ip_address;
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('IP ONT belum diketahui. Refresh data ONT dulu.');
        }
        if ($active = OntRemote::where('ont_sn', $device->serial_number)->where('is_active', true)->where('expires_at', '>', now())->first()) {
            return $active;
        }

        [$min, $max] = array_map('intval', explode('-', $this->settings->get('wireguard', 'wg_remote_port_range', '20000-58000')) + [1 => 58000]);
        $used = OntRemote::where('is_active', true)->pluck('public_port')->merge(WgPortForward::pluck('public_port'))->all();
        do {
            $port = random_int(max($min, 1025), max($max, $min + 1000));
        } while (in_array($port, $used, true));

        $remote = OntRemote::create(['ont_sn' => $device->serial_number, 'ont_name' => $device->product_class, 'ont_ip' => $ip,
            'target_port' => $targetPort, 'public_port' => $port, 'expires_at' => now()->addMinutes(self::MINUTES), 'is_active' => true]);
        $this->firewall('-I', $remote);

        return $remote;
    }

    public function close(OntRemote $remote): void
    {
        $this->firewall('-D', $remote);
        $remote->update(['is_active' => false]);
    }

    public function cleanupExpired(): int
    {
        $expired = OntRemote::where('is_active', true)->where('expires_at', '<=', now())->get();
        $expired->each(fn (OntRemote $r) => $this->close($r));

        return $expired->count();
    }

    public function url(OntRemote $remote): string
    {
        return 'http://'.$this->publicHost().':'.$remote->public_port;
    }

    /** Hanya berjalan di server Linux dengan sudo iptables (sama seperti S.NET lama). */
    private function firewall(string $op, OntRemote $r): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return;
        }
        $ip = escapeshellarg($r->ont_ip);
        $insert = $op === '-I' ? '-I' : '-D';
        $pos = $op === '-I' ? ' 1' : '';
        foreach ([
            "sudo iptables {$insert} INPUT{$pos} -p tcp --dport {$r->public_port} -j ACCEPT",
            "sudo iptables {$insert} FORWARD{$pos} -p tcp -d {$ip} --dport {$r->target_port} -j ACCEPT",
            "sudo iptables -t nat {$insert} PREROUTING{$pos} -p tcp --dport {$r->public_port} -j DNAT --to-destination {$ip}:{$r->target_port}",
            "sudo iptables -t nat {$insert} POSTROUTING{$pos} -p tcp -d {$ip} --dport {$r->target_port} -j MASQUERADE",
        ] as $cmd) {
            Process::run($cmd);
        }
    }
}
