# Instalasi ONT AUTO CONFIGURATION (Phase 1)

## Kebutuhan
- PHP 8.3+ dengan extension: pdo_mysql, mbstring, openssl, curl, intl, bcmath, xml, zip
- Composer 2
- MySQL 8+ / MariaDB 10.6+ (SQLite hanya untuk pengujian otomatis)
- Akses jaringan ke GenieACS NBI (default port 7557)

## 1. Pasang dependency
```bash
composer install
cp .env.example .env
php artisan key:generate
```

## 2. Database MySQL
Buat database dan user (jalankan sekali, masukkan password root MySQL Anda):
```bash
mysql -u root -p -e "CREATE DATABASE ont_autoconfig CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'ont_app'@'localhost' IDENTIFIED BY 'GantiPasswordIni'; GRANT ALL ON ont_autoconfig.* TO 'ont_app'@'localhost'; FLUSH PRIVILEGES;"
```

Ubah bagian database di `.env`:
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ont_autoconfig
DB_USERNAME=ont_app
DB_PASSWORD=GantiPasswordIni
```

## 3. Pengaturan aplikasi (`.env`)
| Variabel | Fungsi |
|---|---|
| `PROVISIONING_MODE` | `simulation` (default, tidak mengirim apa pun ke ONT) atau `production` |
| `GENIEACS_NBI_URL` | URL NBI awal, mis. `http://192.168.20.254:7557` (bisa diubah di menu GenieACS Settings) |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | Admin awal. Jika `ADMIN_PASSWORD` kosong, password acak dibuat dan ditampilkan sekali saat seeding. |
| `APP_TIMEZONE` | `Asia/Jakarta` |

> `APP_KEY` dipakai untuk mengenkripsi credential GenieACS, password PPPoE dan WiFi. **Jangan diganti** setelah ada data, karena data yang sudah terenkripsi tidak bisa dibuka lagi. Simpan backup-nya di tempat aman.

## 4. Migrasi & data awal
```bash
php artisan migrate --seed
```
Seeder membuat: admin awal, pengaturan GenieACS, 7 model ONT (dari log GenieACS), dan mapping parameter. Hanya mapping FiberHome HG6145D2 yang dibaca dari ONT asli yang diberi tanda *verified*.

## 5. Menjalankan
Pengembangan:
```bash
php artisan serve
```

Produksi (ringkas, rincian di Phase 7):
- Nginx + PHP-FPM, document root ke `public/`
- `APP_ENV=production`, `APP_DEBUG=false`
- `php artisan config:cache && php artisan route:cache && php artisan view:cache`
- Worker queue lewat Supervisor: `php artisan queue:work --queue=provisioning,default` (dipakai mulai Phase 5)

## 6. Pengujian
```bash
php artisan test
```
Test memakai SQLite in-memory dan `Http::fake()`, sehingga **tidak pernah** menghubungi GenieACS atau ONT asli.

## Keamanan GenieACS
NBI GenieACS (port 7557) secara default tidak memakai autentikasi. Batasi aksesnya, misalnya hanya dari IP server aplikasi:
```bash
sudo ufw allow from <IP_SERVER_APLIKASI> to any port 7557 proto tcp
sudo ufw deny 7557/tcp
```
Atau taruh NBI di belakang reverse-proxy dengan Basic Auth, lalu isi username/password di menu **GenieACS Settings**. Credential disimpan terenkripsi dan tidak pernah ditampilkan.

## Akses per role
| Fitur | Admin | Teknisi | Viewer |
|---|:-:|:-:|:-:|
| Dashboard | ✅ | ✅ | ✅ |
| Cari ONT / lihat status (Phase 2) | ✅ | ✅ | ✅ |
| Konfigurasi ONT (Phase 4–5) | ✅ | ✅ | ❌ |
| GenieACS Settings, User, Model ONT, Template | ✅ | ❌ | ❌ |

## Phase 2: Pencarian & sinkronisasi ONT
- **Auto Configuration**: masukkan SN (huruf besar/kecil bebas; untuk Huawei bisa SN stiker `HWTC…` maupun bentuk hex `48575443…`).
- **Cek online** memakai Connection Request nyata (`getParameterValues DeviceInfo.UpTime`). Jika ONT tidak merespons, task langsung dihapus dari antrean GenieACS dan statusnya **OFFLINE**.
- **Refresh Parameter** (Admin/Teknisi) menjalankan `refreshObject` untuk DeviceInfo, WANDevice, dan LANDevice. Tombol ini diperlukan setelah ONT BOOTSTRAP, karena provision `bootstrap` di GenieACS mengosongkan cache parameter.
- Sinkronisasi daftar ONT:
  ```bash
  php artisan ont:sync
  ```
  Otomatis setiap 15 menit bila cron Laravel aktif:
  ```
  * * * * * cd /path/ke/ont-autoconfig && php artisan schedule:run >> /dev/null 2>&1
  ```

### API Phase 2
| Method | Endpoint | Role |
|---|---|---|
| POST | `/api/ont/search` `{serial_number}` | semua |
| GET | `/api/ont/{id}` | semua |
| GET | `/api/ont/{id}/parameters?prefix=` | semua (secret disamarkan) |
| GET | `/api/ont/{id}/capabilities` | semua |
| POST | `/api/ont/{id}/check-online` | semua |
| POST | `/api/ont/{id}/refresh` | admin, teknisi |
| POST | `/api/ont/sync` | admin, teknisi |

## Phase 3–7: Driver, provisioning, template, user, dan deploy
- Driver vendor ada di `app/Services/ONT/Drivers/{FiberHome,ZTE,Huawei}`. Path parameter diambil dari tabel `ont_parameter_mappings` (menu **Device Models**).
- Untuk menjalankan worker provisioning saat pengembangan:
  ```bash
  php artisan queue:work --queue=provisioning,default --tries=1
  ```
- Deploy produksi dan checklist sebelum mode produksi: lihat [DEPLOY.md](DEPLOY.md).

### API konfigurasi
| Method | Endpoint | Role |
|---|---|---|
| GET | `/api/ont/{id}/config-context` | admin, teknisi |
| POST | `/api/ont/{id}/preview` | admin, teknisi |
| POST | `/api/ont/configure` | admin, teknisi |
| GET | `/api/configuration/{uuid}/status` | admin, pemilik job |
| GET | `/api/configuration/{uuid}/history` | admin, pemilik job |
| POST | `/api/configuration/{uuid}/rollback` | admin, pemilik job |
| GET | `/api/templates` | admin, teknisi |

## Verifikasi parameter otomatis
- **Saat ONT dicari / di-refresh:** setiap mapping yang path-nya ditemukan di ONT asli otomatis menjadi *verified* (level **path**), lengkap dengan SN dan firmware ONT sumbernya.
- **Setelah job produksi:** parameter yang ditulis lalu dibaca ulang dengan nilai cocok naik ke level **write**.
- **Model baru** dari vendor yang didukung (FiberHome/ZTE/Huawei) otomatis didaftarkan di Device Models dengan status *uji coba*, lalu diverifikasi otomatis. Status **Didukung** tetap diputuskan admin.
- Tingkat verifikasi tidak pernah diturunkan otomatis. Parameter yang objeknya belum ada di ONT (mis. `wan.ppp.*` bila ONT belum punya WAN PPP) tetap belum terverifikasi.
- Setiap job produksi tetap membaca ulang nilai dari ONT, sehingga format nilai yang tidak sesuai akan terdeteksi sebagai PARTIAL dan bisa di-rollback.
