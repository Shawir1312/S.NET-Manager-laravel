<?php

namespace Tests\Support;

use App\Models\Router;
use App\Services\Mikrotik\MikrotikConnection;
use App\Services\Mikrotik\MikrotikConnector;
use App\Services\Mikrotik\MikrotikException;

/**
 * Router MikroTik palsu di memori: menyimpan PPP secret & sesi aktif,
 * mencatat semua perintah. Pasang dengan $this->app->instance(MikrotikConnector::class, $fake).
 */
class FakeMikrotik extends MikrotikConnector
{
    /** @var array<string, array<string, string>> nama secret => atribut */
    public array $secrets = [];

    /** @var list<string> nama user dengan sesi aktif */
    public array $active = [];

    /** @var list<array{0: string, 1: array<string, string>}> */
    public array $commands = [];

    public bool $offline = false;

    /** @var array<string, list<array<string, string>>> jawaban tetap per perintah lain (mis. '/ip/hotspot/ip-binding/print') */
    public array $responses = [];

    public function connect(Router $router): MikrotikConnection
    {
        if ($this->offline) {
            throw new MikrotikException("Gagal terhubung ke API MikroTik {$router->name}.");
        }

        return new class($this) extends MikrotikConnection
        {
            public function __construct(private readonly FakeMikrotik $fake)
            {
                parent::__construct(null);
            }

            public function comm(string $command, array $args = []): array
            {
                $f = $this->fake;
                $f->commands[] = [$command, $args];
                $name = $args['?name'] ?? null;

                return match ($command) {
                    '/ppp/secret/print' => $name === null
                        ? array_map(fn ($k) => ['.id' => $k] + $f->secrets[$k], array_keys($f->secrets))
                        : (isset($f->secrets[$name]) ? [['.id' => $name] + $f->secrets[$name]] : []),
                    '/ppp/secret/add' => [$f->secrets[$args['name']] = $args],
                    '/ppp/secret/set' => (function () use ($f, $args) {
                        $old = $f->secrets[$args['.id']];
                        unset($f->secrets[$args['.id']], $args['.id']);
                        $f->secrets[$args['name'] ?? $old['name']] = array_merge($old, $args);

                        return [];
                    })(),
                    '/ppp/secret/remove' => (function () use ($f, $args) {
                        unset($f->secrets[$args['.id']]);

                        return [];
                    })(),
                    '/ppp/active/print' => $name === null
                        ? array_map(fn ($n) => ['.id' => $n, 'name' => $n, 'address' => '10.10.0.2', 'uptime' => '1h5m'], $f->active)
                        : (in_array($name, $f->active, true) ? [['.id' => $name, 'name' => $name]] : []),
                    '/ppp/active/remove' => (function () use ($f, $args) {
                        $f->active = array_values(array_diff($f->active, [$args['.id']]));

                        return [];
                    })(),
                    '/ppp/profile/print' => [['name' => 'default'], ['name' => '10Mbps'], ['name' => 'isolir']],
                    '/system/identity/print' => [['name' => 'MT-TEST']],
                    default => $f->responses[$command] ?? [],
                };
            }

            public function close(): void {}
        };
    }
}
