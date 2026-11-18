# Deploy Produksi: ONT AUTO CONFIGURATION

Contoh untuk Ubuntu 22.04/24.04, Nginx + PHP-FPM 8.3 + MySQL/MariaDB + Supervisor.
Bisa dipasang di server yang sama dengan GenieACS (192.168.20.254) atau di server terpisah.

## 1. Paket
```bash
sudo apt install -y nginx mariadb-server supervisor unzip git \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-intl php8.3-bcmath php8.3-zip
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
```

## 2. Aplikasi
```bash
sudo mkdir -p /var/www/ont-autoconfig && sudo chown $USER: /var/www/ont-autoconfig
# salin kode ke /var/www/ont-autoconfig, lalu:
cd /var/www/ont-autoconfig
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
```

Isi `.env` (minimal):
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ont.isp-anda.id
APP_TIMEZONE=Asia/Jakarta
DB_CONNECTION=mysql
DB_DATABASE=ont_autoconfig
DB_USERNAME=ont_app
DB_PASSWORD=...
QUEUE_CONNECTION=database
SESSION_SECURE_COOKIE=true
PROVISIONING_MODE=simulation     # ubah ke production hanya setelah checklist di bawah lulus
GENIEACS_NBI_URL=http://127.0.0.1:7557
ADMIN_EMAIL=admin@isp-anda.id
ADMIN_PASSWORD=                  # kosong = dibuat acak & ditampilkan sekali
```

```bash
php artisan migrate --seed --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data: storage bootstrap/cache
```

> **Backup `APP_KEY`.** Kunci ini mengenkripsi credential GenieACS, password PPPoE, dan password WiFi di backup. Kalau kunci hilang, data terenkripsi tidak bisa dibuka lagi.

## 3. Nginx
```nginx
server {
    listen 80;
    server_name ont.isp-anda.id;
    root /var/www/ont-autoconfig/public;
    index index.php;
    client_max_body_size 2m;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 120;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```
Pasang HTTPS (mis. certbot). Aplikasi ini sebaiknya hanya bisa diakses dari jaringan internal/VPN NOC.

## 4. Worker provisioning (Supervisor)
`/etc/supervisor/conf.d/ont-worker.conf`:
```ini
[program:ont-worker]
command=php /var/www/ont-autoconfig/artisan queue:work --queue=provisioning,default --tries=1 --timeout=900 --sleep=1
user=www-data
numprocs=2
autostart=true
autorestart=true
stopwaitsecs=960
redirect_stderr=true
stdout_logfile=/var/www/ont-autoconfig/storage/logs/worker.log
```
```bash
sudo supervisorctl reread && sudo supervisorctl update
```
`--tries=1` disengaja: provisioning tidak boleh diulang otomatis karena bisa membuat WAN dobel.

## 5. Cron (sinkronisasi ONT tiap 15 menit)
```
* * * * * www-data cd /var/www/ont-autoconfig && php artisan schedule:run >> /dev/null 2>&1
```

## 6. Amankan GenieACS NBI
NBI (7557) tidak memakai autentikasi. Batasi aksesnya:
```bash
sudo ufw allow from <IP_SERVER_APLIKASI> to any port 7557 proto tcp
sudo ufw deny 7557/tcp
```

## 7. Checklist sebelum `PROVISIONING_MODE=production`
Lakukan **per model + firmware**, memakai ONT uji (bukan ONT pelanggan):

1. Buat WAN PPPoE dan WAN Bridge secara manual lewat web ONT, lengkap dengan binding LAN/SSID.
2. Di aplikasi: **ONT Devices → Detail → Refresh Parameter → Tampilkan parameter**. Catat path dan format nilai untuk VLAN, service list, binding, dan PPPoE.
3. Di **Device Models**: sesuaikan path tiap mapping, lalu centang *verified* hanya untuk yang sudah dicek.
   - FiberHome HG6145D2: cek format `X_FH_LanInterface` (binding) dan semua `wan.ppp.*`.
   - ZTE F670L: isi path `wan.ip.binding` / `wan.ppp.binding` (WAN + LAN/WLAN).
   - Huawei: cek `X_HW_LANBIND.*`, `X_HW_VLAN`, `X_HW_SERVICELIST`.
4. Jalankan wizard dalam mode simulasi. Pastikan semua langkah di Preview sesuai dengan yang Anda buat manual.
5. Ubah status model menjadi **Didukung**, set `PROVISIONING_MODE=production`, lalu `php artisan config:clear`.
6. Uji satu kali di ONT uji. Pastikan hasilnya SUCCESS dengan verifikasi *match*, dan uji juga **Rollback**.
7. Baru gunakan untuk ONT pelanggan.

## 8. Update aplikasi
```bash
php artisan down
git pull   # atau salin kode baru
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo supervisorctl restart ont-worker:*
php artisan up
```
