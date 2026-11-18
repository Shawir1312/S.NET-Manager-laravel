<?php

namespace App\Services\Mikrotik;

use App\Models\Router;

/**
 * Membuka koneksi API ke router MikroTik. Di tes, kelas ini diganti FakeMikrotik
 * lewat container sehingga tidak ada socket yang dibuka.
 */
class MikrotikConnector
{
    /**
     * @throws MikrotikException bila router tidak bisa dihubungi
     */
    public function connect(Router $router): MikrotikConnection
    {
        $api = new RouterosAPI;
        $api->debug = false;
        $api->timeout = 3;
        $api->attempts = 1;
        $api->delay = 0;

        if (! $api->connect($router->ip_address, $router->api_user, (string) $router->api_password, (int) $router->api_port)) {
            throw new MikrotikException("Gagal terhubung ke API MikroTik {$router->name} ({$router->ip_address}:{$router->api_port}).");
        }

        return new MikrotikConnection($api);
    }

    /**
     * Jalankan $callback dengan koneksi terbuka, lalu tutup koneksi.
     *
     * @template T
     *
     * @param  callable(MikrotikConnection): T  $callback
     * @return T
     */
    public function with(Router $router, callable $callback): mixed
    {
        $conn = $this->connect($router);
        try {
            return $callback($conn);
        } finally {
            $conn->close();
        }
    }
}
