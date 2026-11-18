<?php

namespace App\Services\Provisioning;

use App\Services\ONT\Mappers\WANMapper;

/** WAN yang akan dikonfigurasi: instance yang sudah ada, atau instance baru (#WCD#/#I#). */
final class WanTarget
{
    public function __construct(
        public readonly string $slot,
        public readonly int|string $wcd,
        public readonly int|string $i,
        public readonly bool $isNew,
        public readonly string $root = 'InternetGatewayDevice',
    ) {}

    /** WAN baru. $wcd = null -> WANConnectionDevice baru (#WCD#); angka -> instance di WCD yang sudah ada. */
    public static function new(string $slot, string $root, ?int $wcd = null): self
    {
        return new self($slot, $wcd ?? ProvisioningPlan::MARK_WCD, ProvisioningPlan::MARK_I, true, $root);
    }

    public static function fromPath(string $path, string $root): ?self
    {
        if (! preg_match('/WANConnectionDevice\.(\d+)\.(WANIPConnection|WANPPPConnection)\.(\d+)$/', $path, $m)) {
            return null;
        }

        return new self($m[2] === 'WANPPPConnection' ? 'ppp' : 'ip', (int) $m[1], (int) $m[3], false, $root);
    }

    /** @return array{wcd: int|string, i: int|string} */
    public function vars(): array
    {
        return ['wcd' => $this->wcd, 'i' => $this->i];
    }

    public function path(): string
    {
        return "{$this->root}.WANDevice.1.WANConnectionDevice.{$this->wcd}.".WANMapper::objectName($this->slot).".{$this->i}";
    }
}
