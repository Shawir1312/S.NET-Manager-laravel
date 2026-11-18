<?php

namespace Database\Seeders;

use App\Models\GenieAcsSetting;
use Illuminate\Database\Seeder;

class GenieAcsSettingSeeder extends Seeder
{
    public function run(): void
    {
        if (GenieAcsSetting::query()->exists()) {
            return;
        }

        GenieAcsSetting::create([
            'nbi_url' => config('ontconfig.genieacs.nbi_url'),
            'cwmp_url' => config('ontconfig.genieacs.cwmp_url'),
            'connect_timeout' => 5,
            'request_timeout' => 30,
            'connection_request_timeout' => 10,
            'verify_tls' => true,
            'is_active' => true,
        ]);
    }
}
