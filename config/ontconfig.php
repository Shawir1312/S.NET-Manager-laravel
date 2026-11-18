<?php

return [
    /*
    | simulation : tidak ada task yang dikirim ke ONT (memakai FakeGenieACSClient).
    | production : task dikirim ke GenieACS asli.
    */
    'provisioning_mode' => env('PROVISIONING_MODE', 'simulation'),

    // Batas waktu menunggu satu task GenieACS selesai. Jika ONT tidak merespons Connection Request,
    // task baru jalan saat periodic inform berikutnya (ONT FiberHome Anda: tiap 100 detik).
    'task_timeout_seconds' => (int) env('ONT_TASK_TIMEOUT', 180),
    'task_poll_interval_ms' => (int) env('ONT_TASK_POLL_MS', 3000),

    // Ambang "inform terakhir baru-baru ini" untuk statistik dashboard (indikasi, bukan bukti online).
    'online_threshold_seconds' => (int) env('ONT_ONLINE_THRESHOLD', 300),

    // Nilai awal pengaturan GenieACS saat seeding pertama kali.
    'genieacs' => [
        'nbi_url' => env('GENIEACS_NBI_URL', 'http://127.0.0.1:7557'),
        'cwmp_url' => env('GENIEACS_CWMP_URL'),
    ],

    // Kunci yang nilainya SELALU disamarkan di audit log, history dan log aplikasi.
    'redact_keys' => [
        'password', 'pppoe_password', 'wifi_password', 'ssid_password', 'keypassphrase',
        'presharedkey', 'secret', 'token', 'api_key', 'authorization', 'username_nbi',
        'connectionrequestpassword', 'wepkey',
        // segmen terakhir path TR-069 yang diakhiri 'key' (mis. X_FH_Radius.Key, WEPKey)
        'key',
    ],
];
