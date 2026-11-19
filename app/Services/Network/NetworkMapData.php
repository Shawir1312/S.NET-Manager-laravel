<?php

namespace App\Services\Network;

use App\Models\Odp;
use App\Models\PppoeCustomer;
use App\Models\Router;
use App\Services\Pppoe\CustomerLiveStatus;
use Illuminate\Support\Facades\DB;

/**
 * Data peta satu cabang: server (router) → ODP → pelanggan, dengan status koneksi langsung.
 * Pelanggan "online" = sesi PPPoE aktif (MikroTik /ppp/active atau radacct).
 * ODP "up" bila ada pelanggannya yang online; "down" bila punya pelanggan tetapi semuanya putus (kemungkinan kabel ODP putus).
 */
class NetworkMapData
{
    public function __construct(private readonly CustomerLiveStatus $live) {}

    /**
     * @param  bool  $public  halaman tanpa login: tanpa IP, username, alamat, no. HP
     * @return array<string, mixed>
     */
    public function build(Router $router, bool $public = false): array
    {
        $online = collect($this->live->pppoeSessions())->keys()
            ->merge(DB::table('radacct')->whereNull('acctstoptime')->pluck('username'))->flip();

        $customers = PppoeCustomer::where('router_id', $router->id)->whereNotNull('latitude')->whereNotNull('longitude')
            ->get(['id', 'full_name', 'pppoe_username', 'status', 'odp_id', 'latitude', 'longitude', 'ont_sn']);

        $rows = $customers->map(fn (PppoeCustomer $c) => [
            'id' => $c->id,
            'name' => $c->full_name,
            'username' => $public ? null : $c->pppoe_username,
            'odp_id' => $c->odp_id,
            'lat' => $c->latitude, 'lng' => $c->longitude,
            'status' => $c->status === 'isolated' ? 'isolated' : ($online->has($c->pppoe_username) ? 'online' : 'offline'),
        ])->values();

        $odps = Odp::where('router_id', $router->id)->orderBy('name')->get()->map(function (Odp $o) use ($rows) {
            $mine = $rows->where('odp_id', $o->id)->where('status', '!=', 'isolated');

            return [
                'id' => $o->id, 'name' => $o->name, 'lat' => $o->latitude, 'lng' => $o->longitude, 'capacity' => $o->capacity,
                'total' => $mine->count(), 'online' => $mine->where('status', 'online')->count(),
                'status' => $mine->isEmpty() ? 'empty' : ($mine->contains('status', 'online') ? 'up' : 'down'),
            ];
        })->values();

        return [
            'server' => ['id' => $router->id, 'name' => $router->name, 'lat' => $router->latitude, 'lng' => $router->longitude],
            'odps' => $odps,
            'customers' => $rows,
            'summary' => [
                'online' => $rows->where('status', 'online')->count(),
                'offline' => $rows->where('status', 'offline')->count(),
                'isolated' => $rows->where('status', 'isolated')->count(),
                'odp_down' => $odps->where('status', 'down')->count(),
                'unplaced' => PppoeCustomer::where('router_id', $router->id)->where(fn ($q) => $q->whereNull('latitude')->orWhereNull('longitude'))->count(),
            ],
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
