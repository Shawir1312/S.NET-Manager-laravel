# ONT AUTO CONFIGURATION — Rancangan Sistem

Versi rancangan: 1.0 (Phase 1) · Stack: PHP 8.3 · Laravel · MySQL · Bootstrap 5 · Chart.js

---

## 1. Arsitektur

```
 Browser (Admin / Teknisi / Viewer)
   │  HTML + Bootstrap 5 + Fetch API (AJAX), CSRF token
   ▼
 Laravel (PHP 8.3)
   ├─ Web routes (Blade)          → halaman, wizard
   ├─ API routes /api/* (session + Sanctum, rate-limited, policy)
   ├─ Controllers  → tipis, hanya validasi + panggil Service
   ├─ Services
   │    ├─ GenieACS/GenieACSService     (satu-satunya pintu ke NBI)
   │    ├─ ONT/ONTManager, ONTDetector  (pilih driver)
   │    ├─ ONT/Drivers/{FiberHome,ZTE,Huawei}  (mapping per vendor/model/firmware)
   │    ├─ Provisioning/ProvisioningService    (orkestrasi)
   │    └─ Audit/AuditLogger                    (redaksi secret otomatis)
   ├─ Repositories  → akses DB (Eloquent) per agregat
   ├─ Jobs (Queue: database driver → bisa diganti Redis)
   │    ├─ RunProvisioningJob   (backup → apply → monitor → verify)
   │    └─ RefreshDeviceJob
   └─ MySQL / MariaDB
   ▼
 GenieACS NBI  (http://192.168.20.254:7557)
   ▼
 GenieACS CWMP (port 7547)  ── TR-069 ──▶  ONT FiberHome / ZTE / Huawei
```

Prinsip:
1. **Satu pintu ke GenieACS.** Hanya `GenieACSService` yang memanggil NBI. Driver tidak pernah memanggil HTTP langsung.
2. **Driver menghasilkan rencana, bukan mengeksekusi.** Driver mengubah konfigurasi yang diminta menjadi `ProvisioningPlan` (daftar langkah: addObject, setParameterValues, refresh). Rencana bisa di-preview, disimpan, disimulasikan, lalu dieksekusi oleh ProvisioningService.
3. **Parameter dari data, bukan dari kode.** Path parameter disimpan di tabel `ont_parameter_mappings` (vendor + model + pola firmware). Driver hanya mengambil path dari mapping. Path yang belum diverifikasi (`verified = false`) tidak boleh dikirim di mode produksi.
4. **Tidak ada "sukses" tanpa verifikasi.** Status sukses baru diberikan kalau task sudah hilang dari antrean `/tasks`, tidak ada fault, dan parameter yang dibaca ulang dari ONT cocok dengan yang diminta.
5. **Mode simulasi terpisah.** `PROVISIONING_MODE=simulation|production` di `.env`. Mode simulasi memakai `FakeGenieACSClient`, dan setiap job serta history diberi tanda `is_simulation`. Badge "SIMULASI" selalu tampil di UI.

---

## 2. Struktur folder

```
ont-autoconfig/
├─ app/
│  ├─ Enums/                  Role, ServiceType, JobStatus, WanSlot, SecurityMode
│  ├─ Http/
│  │  ├─ Controllers/         Web: Dashboard, AutoConfig, Devices, Templates, History, Users, Settings
│  │  │  └─ Api/              OntController, ConfigurationController, GenieAcsController
│  │  ├─ Middleware/          EnsureRole, ForceJsonApi
│  │  └─ Requests/            SearchOntRequest, ConfigureOntRequest, …
│  ├─ Jobs/                   RunProvisioningJob, RefreshDeviceJob
│  ├─ Models/                 14 model Eloquent
│  ├─ Policies/               OntDevicePolicy, TemplatePolicy, UserPolicy, SettingsPolicy
│  ├─ Repositories/           OntDeviceRepository, ConfigurationJobRepository, …
│  └─ Services/
│     ├─ Audit/AuditLogger.php
│     ├─ GenieACS/
│     │  ├─ GenieACSService.php
│     │  ├─ GenieACSClientInterface.php   (HTTP asli / Fake untuk simulasi & test)
│     │  ├─ HttpGenieACSClient.php
│     │  ├─ FakeGenieACSClient.php
│     │  └─ Exceptions/
│     ├─ ONT/
│     │  ├─ ONTManager.php  ONTDetector.php  ONTInterface.php
│     │  ├─ Drivers/{FiberHome,ZTE,Huawei}/{XDriver,XParameters}.php
│     │  ├─ Mappers/{WAN,VLAN,SSID,Binding}Mapper.php
│     │  └─ Validators/{ONT,WAN}Validator.php
│     └─ Provisioning/
│        ├─ ProvisioningService.php
│        ├─ ProvisioningPlan.php
│        └─ VerificationService.php
├─ database/migrations, seeders (roles, admin awal, model ONT, mapping terverifikasi)
├─ resources/views/            layouts/app, partials/sidebar, dashboard, auto-config/wizard, …
├─ public/assets/              app.css, app.js (tema, fetch helper)
├─ routes/web.php, api.php
├─ tests/Unit, tests/Feature   (memakai FakeGenieACSClient, tidak pernah ke ONT asli)
└─ docs/                       ARCHITECTURE.md, INSTALL.md, PARAMETER-MAPPING.md
```

---

## 3. Database schema

| Tabel | Kolom penting | Catatan |
|---|---|---|
| `users` | name, email (unik), password (bcrypt), role enum(admin,teknisi,viewer), is_active, last_login_at | |
| `ont_models` | vendor enum, model_name, product_class, firmware_pattern (regex), status enum(supported,testing,unsupported), notes | unik (vendor, product_class, firmware_pattern) |
| `ont_capabilities` | ont_model_id FK, max_wan_ip, max_wan_ppp, lan_ports, ssid_count, has_5g, supports_bridge, supports_pppoe, binding_method enum(string_list, flags, wan_plus_lan), vlan_min, vlan_max | 1:1 dengan model |
| `ont_parameter_mappings` | ont_model_id FK, key (mis. `wan.vlan_id`), path_template (mis. `…WANIPConnection.{i}.VLANID`), value_type, value_map JSON, verified bool, verified_at, source | unik (ont_model_id, key) |
| `ont_devices` | genieacs_id (unik), serial_number (unik), manufacturer, oui, product_class, ont_model_id FK null, hardware_version, software_version, ip_address, last_inform_at, last_seen_online, parameters_snapshot JSON, synced_at | cache dari GenieACS, bukan sumber kebenaran |
| `configuration_templates` | name (unik), service_type, payload JSON (tanpa secret), is_default, created_by FK | password PPPoE tidak pernah disimpan |
| `configuration_jobs` | uuid, ont_device_id FK, user_id FK, service_type, request_payload JSON (secret terenkripsi), plan JSON, status enum(pending,processing,verifying,success,partial,failed,cancelled), is_simulation, idempotency_key (unik), genieacs_task_ids JSON, error_code, error_message, started_at, finished_at | inti provisioning |
| `wan_configurations` | configuration_job_id FK, ont_device_id FK, slot enum(ip,ppp), instance_path, connection_type, vlan_id, vlan_priority, service_list, nat_enabled, pppoe_username, pppoe_password (encrypted) | |
| `ssid_configurations` | configuration_job_id FK, ssid_index, ssid_name, password (encrypted), security_mode, enabled, hidden | |
| `interface_bindings` | wan_configuration_id FK, interface_type enum(lan,wlan,wan), interface_index, interface_path | |
| `configuration_backups` | configuration_job_id FK, parameter_path, old_value, old_type, new_value, is_secret, restored_at | satu baris per parameter, sehingga rollback parsial bisa dilacak |
| `configuration_histories` | configuration_job_id FK, event, status, message, meta JSON, created_at | timeline per job |
| `genieacs_settings` | nbi_url, cwmp_url, username, password (encrypted cast), timeouts, verify_tls, last_test_status, last_test_at | satu baris aktif |
| `audit_logs` | user_id FK null, action, subject_type, subject_id, ip, user_agent, changes JSON (sudah diredaksi), created_at | index (action, created_at) |

Ditambah tabel bawaan Laravel: `sessions`, `jobs`, `failed_jobs`, `cache`, dan `personal_access_tokens`.

---

## 4. Alur integrasi GenieACS (NBI)

| Langkah | Panggilan NBI |
|---|---|
| Cari SN | `GET /devices/?query={"_deviceId._SerialNumber":"<SN>"}&projection=_id,_deviceId,_lastInform,…` (URL-encoded). Hasil 0 berarti tidak ditemukan; hasil lebih dari 1 berarti teknisi wajib memilih. SN dicocokkan ulang secara exact match. |
| Cek online | Kirim task ringan `getParameterValues [DeviceInfo.UpTime]` dengan `?connection_request&timeout=…`. HTTP 200 berarti ONT merespons sekarang. HTTP 202 berarti ONT tidak merespons Connection Request, jadi task di-cancel dan status ditandai **offline**. `_lastInform` hanya dipakai sebagai informasi, bukan bukti online. |
| Discovery | `POST /devices/<id>/tasks?connection_request` `{"name":"refreshObject","objectName":"InternetGatewayDevice.WANDevice"}` (juga untuk LANDevice), lalu `GET /devices/?query={_id}` |
| Tambah WAN | `{"name":"addObject","objectName":"…WANConnectionDevice.<n>.WANIPConnection"}`. Instance baru dibaca dari refresh berikutnya. |
| Set parameter | `{"name":"setParameterValues","parameterValues":[[path,value,type],…]}` |
| Monitor | Jika `GET /tasks/?query={"_id":"<taskId>"}` kosong berarti task selesai. Jika task masih ada, cek `GET /faults/?query={"device":"<id>"}` (fault akan menahan task). |
| Verifikasi | `refreshObject` pada objek yang diubah, lalu bandingkan nilainya dengan rencana. |
| Rollback | `setParameterValues` dengan nilai dari `configuration_backups`. Status rollback bisa `restored`, `partial`, atau `failed` per parameter. |

**Fakta dari server Anda (17-11-2026):** NBI listen di `*:7557` tanpa autentikasi. Disarankan membatasi port ini dengan firewall (hanya IP server aplikasi), atau menaruhnya di belakang reverse-proxy dengan Basic Auth. Aplikasi mendukung keduanya.

---

## 5. Fakta parameter yang sudah terverifikasi dari ONT asli

FiberHome HG6145D2, firmware RP4313 (SN FHTT9D34CF80):

| Fungsi | Path |
|---|---|
| Jumlah WAN | `WANDevice.1.WANConnectionNumberOfEntries`, `…WANConnectionDevice.{n}.WANIPConnectionNumberOfEntries` / `WANPPPConnectionNumberOfEntries` |
| Tipe koneksi | `…WANIPConnection.{i}.ConnectionType` (contoh `IP_Routed`) |
| VLAN | `…WANIPConnection.{i}.VLANEnable`, `…VLANID`, juga `…WANConnectionDevice.{n}.X_FH_WANGponLinkConfig.{VLANID,802-1pMark,Mode}` |
| Service list | `…WANIPConnection.{i}.X_FH_ServiceList` (contoh `TR069,VOIP`) |
| Binding LAN/WLAN | `…WANIPConnection.{i}.X_FH_LanInterface` (string). Format nilainya **belum diverifikasi**, perlu satu contoh WAN yang sudah di-bind lewat web ONT. |
| NAT | `…WANIPConnection.{i}.NATEnabled` |
| WiFi | `LANDevice.1.WLANConfiguration.{1..8}.{SSID,Enable,BeaconType,SSIDAdvertisementEnabled,IEEE11iAuthenticationMode,IEEE11iEncryptionModes,PreSharedKey.1.KeyPassphrase}`. Index 1–4 untuk 2.4 GHz, 5–8 untuk 5 GHz. |
| Port | `LANEthernetInterfaceNumberOfEntries = 4`, `LANWLANConfigurationNumberOfEntries = 8` |

Dipakai di provision `default` server Anda, tetapi belum dicek di ONT asli lewat aplikasi ini:
- **Huawei:** `X_HW_SERVICELIST`, `X_HW_VLAN`, dan binding berbentuk **flag** `X_HW_LANBIND.{Lan1..4,SSID1..4}Enable`. Formatnya berbeda dari string list.
- **ZTE:** `X_ZTE-COM_ServiceList`, `X_ZTE-COM_VLANID`. Binding F670L memakai WAN path + LAN/WLAN (sesuai spesifikasi Anda). Path final perlu dicek pada F670L asli.

Mapping yang belum terverifikasi masuk dengan `verified = false`. Driver akan menolak mengirimnya di mode produksi dan menampilkan "Parameter belum terverifikasi untuk model ini".

**Referensi video Google Drive tidak bisa saya buka.** Untuk Phase 4, saya butuh dump parameter dari satu ONT ZTE F670L dan satu Huawei, masing-masing dengan WAN PPPoE dan WAN bridge yang sudah dibuat manual lewat web ONT. Dump bisa saya ambil langsung dari GenieACS kalau ONT-nya online.

---

## 6. Rencana phase

| Phase | Isi | Cara uji |
|---|---|---|
| **1 (sekarang)** | Laravel, 14 migration, model, seeder, login, role & policy, layout + sidebar + dark/light, dashboard (data DB), halaman GenieACS Settings (simpan terenkripsi) | `php artisan test`, login 3 role, cek akses menu |
| 2 | GenieACSService + test connection, cari SN, cek online, ONTDetector, discovery parameter | Cari SN FHTT9D34CF80 di server asli (hanya baca) |
| 3 | ONTInterface, driver FiberHome/ZTE/Huawei, editor mapping parameter | Unit test mapping dan capability dari dump asli |
| 4 | WAN bridge/PPPoE, VLAN, SSID, binding, aturan khusus F670L, wizard step 2–6 | Preview plan di mode simulasi |
| 5 | Queue provisioning, monitor task, verifikasi, backup, rollback | Simulasi dulu, lalu satu ONT uji (bukan ONT pelanggan) |
| 6 | Template, history, user management, audit log, rate limit | Feature test RBAC |
| 7 | Test end-to-end, deploy (Nginx + PHP-FPM + Supervisor queue), dokumentasi | Checklist go-live |
