#!/usr/bin/env bash
# =============================================================================
#  S.NET MANAGER — Pemasang otomatis kebutuhan server (Ubuntu 20.04+ / Debian 11+)
#
#  Jalankan SEKALI sebagai root di VPS:
#      sudo bash /path/snet-manager/scripts/setup-server.sh
#  Setelah itu installer web (install.php) bisa memasang/memperbaiki komponen sendiri
#  lewat /usr/local/sbin/snet-setup (dipasang skrip ini, milik root).
#
#  Pemakaian:  setup-server.sh [all|web|php|composer|mysql|freeradius|wireguard|whatsapp|queue|cron|sudoers|check]
# =============================================================================
set -uo pipefail
export DEBIAN_FRONTEND=noninteractive

SELF="$(readlink -f "${BASH_SOURCE[0]}")"
# Saat dijalankan dari /usr/local/sbin/snet-setup, lokasi aplikasi dibaca dari file ini.
if [ -f /etc/snet-manager.path ] && [ "$SELF" = "/usr/local/sbin/snet-setup" ]; then
    APP_DIR="$(cat /etc/snet-manager.path)"
else
    APP_DIR="$(cd "$(dirname "$SELF")/.." && pwd)"
fi
STEP="${1:-all}"
LOG_PREFIX="[snet-setup]"
ok()   { echo "$LOG_PREFIX ✔ $*"; }
info() { echo "$LOG_PREFIX • $*"; }
warn() { echo "$LOG_PREFIX ! $*"; }
die()  { echo "$LOG_PREFIX ✘ $*"; exit 1; }

[ "$STEP" = "check" ] && { echo "ok"; exit 0; }   # dipakai install.php: cek izin sudo
[ "$(id -u)" -eq 0 ] || die "Jalankan sebagai root: sudo bash $SELF"
[ -f "$APP_DIR/artisan" ] || die "Folder aplikasi tidak valid: $APP_DIR"
command -v apt-get >/dev/null || die "Skrip ini hanya untuk Ubuntu/Debian (apt)."

AAPANEL=0; [ -d /www/server/panel ] && AAPANEL=1
# User web server = pemilik folder aplikasi (www di aaPanel, www-data di Ubuntu).
WEB_USER="$(stat -c %U "$APP_DIR")"
if [ "$WEB_USER" = "root" ]; then
    for u in www www-data nginx; do id "$u" >/dev/null 2>&1 && WEB_USER="$u" && break; done
    chown -R "$WEB_USER":"$WEB_USER" "$APP_DIR"
fi
PHP_BIN="$(command -v php8.3 || command -v php || echo /usr/bin/php)"
# aaPanel: pakai PHP >= 8.3 tertinggi yang terpasang di /www/server/php/<versi>/bin/php.
for v in 85 84 83; do [ -x "/www/server/php/$v/bin/php" ] && PHP_BIN="/www/server/php/$v/bin/php" && break; done
# aaPanel menonaktifkan proc_open/putenv dll. di php.ini (ikut ke CLI); untuk perintah CLI kita aktifkan kembali.
PHP_CLI=("$PHP_BIN" -d disable_functions= -d open_basedir= -d memory_limit=1024M)
php_ok() { "${PHP_CLI[@]}" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' 2>/dev/null; }
DB_FILE="$APP_DIR/storage/app/setup-db.json"

apt_install() { apt-get install -y -q "$@" >/dev/null 2>&1 || { apt-get update -q >/dev/null 2>&1; apt-get install -y -q "$@" >/dev/null 2>&1; }; }

# Kredensial DB: dari .env (setelah install web) atau dari setup-db.json (dibuat langkah mysql).
read_db() {
    DB_HOST=127.0.0.1; DB_PORT=3306; DB_NAME=""; DB_USER=""; DB_PASS=""
    if [ -f "$APP_DIR/.env" ] && grep -q '^DB_CONNECTION=mysql' "$APP_DIR/.env"; then
        # .env di-parse oleh user web (vendor/ bisa ditulis user web → jangan dimuat sebagai root);
        # root hanya membaca hasil JSON-nya sebagai data, lalu meng-escape tiap nilai.
        local json
        json="$(cd / && sudo -u "$WEB_USER" "$PHP_BIN" -r 'require $argv[1]."/vendor/autoload.php"; echo json_encode(Dotenv\Dotenv::parse(file_get_contents($argv[1]."/.env")));' "$APP_DIR" 2>/dev/null)"
        eval "$(cd / && printf '%s' "$json" | "$PHP_BIN" -r '$e = json_decode(stream_get_contents(STDIN), true) ?: []; foreach (["DB_HOST","DB_PORT","DB_DATABASE","DB_USERNAME","DB_PASSWORD"] as $k) echo $k, "=", escapeshellarg((string) ($e[$k] ?? "")), "\n";')"
        DB_HOST="${DB_HOST:-127.0.0.1}"; DB_NAME="${DB_DATABASE:-}"; DB_USER="${DB_USERNAME:-}"; DB_PASS="${DB_PASSWORD:-}"
    elif [ -f "$DB_FILE" ]; then
        eval "$(cd / && "$PHP_BIN" -r '$d = json_decode(file_get_contents("'"$DB_FILE"'"), true); foreach (["host"=>"DB_HOST","port"=>"DB_PORT","database"=>"DB_NAME","username"=>"DB_USER","password"=>"DB_PASS"] as $k=>$v) echo $v, "=", escapeshellarg((string)($d[$k] ?? "")), "\n";')"
    fi
    [ "$DB_HOST" = "localhost" ] && DB_HOST=127.0.0.1
}

step_base() {
    info "Paket dasar…"
    apt-get update -q >/dev/null 2>&1
    apt_install curl ca-certificates gnupg lsb-release unzip git cron iptables software-properties-common
    systemctl enable --now cron >/dev/null 2>&1 || true
    ok "Paket dasar terpasang."
}

step_php() {
    if [ "$AAPANEL" -eq 1 ]; then
        php_ok && { ok "PHP $("${PHP_CLI[@]}" -r 'echo PHP_VERSION;') aaPanel dipakai: $PHP_BIN"; return; }
        die "PHP 8.3 belum terpasang di aaPanel (yang terdeteksi: $(ls /www/server/php 2>/dev/null | tr '\n' ' ')). Pasang lewat aaPanel → App Store → PHP 8.3, lalu jalankan ulang skrip ini."
    fi
    if "$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' 2>/dev/null; then
        info "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;') sudah ada, melengkapi ekstensi…"
    else
        info "Memasang PHP 8.3…"
        if grep -qi ubuntu /etc/os-release; then
            add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1
        else
            curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury-php.gpg
            echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/sury-php.list
        fi
        apt-get update -q >/dev/null 2>&1
    fi
    apt_install php8.3-cli php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl php8.3-gd
    PHP_BIN="$(command -v php8.3 || command -v php)"
    PHP_CLI=("$PHP_BIN" -d disable_functions= -d open_basedir= -d memory_limit=1024M)
    "$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' && ok "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;') siap." || die "PHP 8.3 gagal dipasang."
}

step_web() {
    if [ "$AAPANEL" -eq 1 ]; then
        ok "aaPanel terdeteksi: pastikan Website → Site directory → Running directory = /public."
        return
    fi
    if ss -ltn 2>/dev/null | grep -qE ':(80|443)\s' && ! systemctl is-active --quiet nginx; then
        ok "Web server lain sudah aktif di port 80 — dilewati (arahkan document root ke $APP_DIR/public)."
        return
    fi
    step_php
    info "Memasang Nginx…"
    apt_install nginx
    FPM_SOCK="$(ls /run/php/php8.3-fpm.sock 2>/dev/null || echo /run/php/php8.3-fpm.sock)"
    cat > /etc/nginx/sites-available/snet-manager <<EOF
server {
    listen 80 default_server;
    server_name _;
    root $APP_DIR/public;
    index index.php;
    client_max_body_size 64M;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$FPM_SOCK;
        fastcgi_read_timeout 600;
    }
    location ~ /\.(?!well-known) { deny all; }
}
EOF
    ln -sf /etc/nginx/sites-available/snet-manager /etc/nginx/sites-enabled/snet-manager
    rm -f /etc/nginx/sites-enabled/default
    # php-fpm berjalan sebagai www-data → samakan pemilik folder aplikasi.
    WEB_USER=www-data; chown -R www-data:www-data "$APP_DIR"
    systemctl enable --now php8.3-fpm >/dev/null 2>&1; systemctl restart php8.3-fpm
    nginx -t >/dev/null 2>&1 && systemctl enable --now nginx >/dev/null 2>&1 && systemctl reload nginx && ok "Nginx + PHP-FPM aktif (root: $APP_DIR/public)." || die "Konfigurasi Nginx gagal (nginx -t)."
}

step_composer() {
    if [ -f "$APP_DIR/vendor/autoload.php" ]; then ok "Dependensi PHP (vendor) sudah ada."; return; fi
    php_ok || die "PHP 8.3 tidak ditemukan ($PHP_BIN). Pasang PHP 8.3 dulu."
    if ! command -v composer >/dev/null; then
        info "Memasang Composer…"
        curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php && "${PHP_CLI[@]}" /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer >/dev/null && rm -f /tmp/composer-setup.php
    fi
    info "composer install (1–5 menit)…"
    mkdir -p "$APP_DIR/storage/app/composer-home" && chown -R "$WEB_USER":"$WEB_USER" "$APP_DIR/storage"
    local out
    if out="$(sudo -u "$WEB_USER" env HOME="$APP_DIR/storage/app/composer-home" COMPOSER_HOME="$APP_DIR/storage/app/composer-home" "${PHP_CLI[@]}" "$(command -v composer)" install --no-dev --optimize-autoloader --no-interaction --no-progress --working-dir="$APP_DIR" 2>&1)"; then
        ok "Dependensi PHP terpasang."
    else
        echo "$out" | tail -25; die "composer install gagal (lihat pesan di atas)."
    fi
}

step_mysql() {
    if (echo > /dev/tcp/127.0.0.1/3306) >/dev/null 2>&1; then
        ok "Server MySQL/MariaDB sudah berjalan di 127.0.0.1:3306."
    elif [ "$AAPANEL" -eq 1 ]; then
        warn "aaPanel: pasang MySQL lewat App Store aaPanel, lalu jalankan ulang skrip ini."; return
    else
        info "Memasang MariaDB…"
        apt_install mariadb-server
        systemctl enable --now mariadb >/dev/null 2>&1
        ok "MariaDB aktif."
    fi
    # Buat database + user khusus aplikasi (hanya bila .env belum ada & root lokal bisa login via socket).
    if [ ! -f "$APP_DIR/.env" ] && [ ! -f "$DB_FILE" ] && mysql -uroot -e 'select 1' >/dev/null 2>&1; then
        local pass; pass="$(openssl rand -base64 18 | tr -dc 'A-Za-z0-9' | head -c 20)"
        mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS snet_manager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'snet'@'127.0.0.1' IDENTIFIED BY '$pass';
CREATE USER IF NOT EXISTS 'snet'@'localhost' IDENTIFIED BY '$pass';
ALTER USER 'snet'@'127.0.0.1' IDENTIFIED BY '$pass';
ALTER USER 'snet'@'localhost' IDENTIFIED BY '$pass';
GRANT ALL PRIVILEGES ON snet_manager.* TO 'snet'@'127.0.0.1';
GRANT ALL PRIVILEGES ON snet_manager.* TO 'snet'@'localhost';
FLUSH PRIVILEGES;
SQL
        mkdir -p "$(dirname "$DB_FILE")"
        printf '{"host":"127.0.0.1","port":"3306","database":"snet_manager","username":"snet","password":"%s"}\n' "$pass" > "$DB_FILE"
        chown "$WEB_USER":"$WEB_USER" "$DB_FILE"; chmod 600 "$DB_FILE"
        ok "Database snet_manager + user snet dibuat (diisi otomatis di install.php)."
    fi
}

step_freeradius() {
    info "Memasang FreeRADIUS + modul MySQL…"
    apt_install freeradius freeradius-mysql freeradius-utils
    read_db
    if [ -z "$DB_NAME" ]; then
        warn "Kredensial database belum ada — FreeRADIUS dipasang, konfigurasi SQL dilakukan otomatis setelah install.php selesai."
        systemctl enable freeradius >/dev/null 2>&1; return
    fi
    # Nilai berasal dari .env (bisa diubah user web) → tolak yang bisa menyisipkan baris konfigurasi.
    [[ "$DB_PORT" =~ ^[0-9]{1,5}$ ]] || die "DB_PORT tidak valid."
    for v in "$DB_HOST" "$DB_NAME" "$DB_USER"; do [[ "$v" =~ ^[A-Za-z0-9._:-]+$ ]] || die "Nilai DB tidak valid untuk konfigurasi FreeRADIUS: $v"; done
    [[ "$DB_PASS" == *$'\n'* ]] && die "Password database tidak boleh berisi baris baru."
    local conf=/etc/freeradius/3.0/mods-available/sql
    [ -f "$conf" ] && [ ! -f "$conf.orig" ] && cp "$conf" "$conf.orig"
    local pass_esc
    pass_esc="$(printf '%s' "$DB_PASS" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g')"
    cat > "$conf" <<EOF
sql {
    driver = "rlm_sql_mysql"
    dialect = "mysql"
    server = "${DB_HOST}"
    port = ${DB_PORT}
    login = "${DB_USER}"
    password = "${pass_esc}"
    radius_db = "${DB_NAME}"
    acct_table1 = "radacct"
    acct_table2 = "radacct"
    postauth_table = "radpostauth"
    authcheck_table = "radcheck"
    authreply_table = "radreply"
    groupcheck_table = "radgroupcheck"
    groupreply_table = "radgroupreply"
    usergroup_table = "radusergroup"
    nas_table = "nas"
    sql_user_name = "%{%{Stripped-User-Name}:-%{%{User-Name}:-DEFAULT}}"
    simul_count_query = "SELECT COUNT(*) FROM \${acct_table1} WHERE username = '%{SQL-User-Name}' AND acctstoptime IS NULL"
    simul_verify_query = "SELECT radacctid, acctsessionid, username, nasipaddress, nasportid, framedipaddress, callingstationid, framedprotocol FROM \${acct_table1} WHERE username = '%{SQL-User-Name}' AND acctstoptime IS NULL"
    read_clients = yes
    client_table = "nas"
    group_attribute = "SQL-Group"
    \$INCLUDE \${modconfdir}/\${.:name}/main/\${dialect}/queries.conf
}
EOF
    chgrp -h freerad "$conf" 2>/dev/null; chmod 640 "$conf"
    ln -sf "$conf" /etc/freeradius/3.0/mods-enabled/sql
    for site in default inner-tunnel; do
        f=/etc/freeradius/3.0/sites-available/$site
        [ -f "$f" ] || continue
        sed -i 's/^[[:space:]]*sqlippool/#    sqlippool/g; s/^[[:space:]]*-sql\([[:space:]]*\)$/    sql\1/g; s/^[[:space:]]*#[[:space:]]*sql\([[:space:]]*\)$/    sql\1/g' "$f"
    done
    iptables -C INPUT -p udp --dport 1812:1813 -j ACCEPT 2>/dev/null || iptables -I INPUT -p udp --dport 1812:1813 -j ACCEPT
    command -v ufw >/dev/null && ufw allow 1812:1813/udp >/dev/null 2>&1
    systemctl enable freeradius >/dev/null 2>&1
    systemctl restart freeradius
    sleep 2
    systemctl is-active --quiet freeradius && ok "FreeRADIUS aktif & terhubung ke database $DB_NAME." \
        || warn "FreeRADIUS belum aktif (tabel radius dibuat saat install.php). Akan dicoba lagi otomatis setelah instalasi."
}

step_wireguard() {
    info "Memasang WireGuard…"
    apt_install wireguard wireguard-tools iptables
    mkdir -p /etc/wireguard && chmod 700 /etc/wireguard
    [ -f /etc/wireguard/server_private.key ] || (umask 077; wg genkey > /etc/wireguard/server_private.key)
    wg pubkey < /etc/wireguard/server_private.key > /etc/wireguard/server_public.key
    # Public key boleh dibaca aplikasi (diisi otomatis ke Pengaturan WireGuard).
    cp /etc/wireguard/server_public.key "$APP_DIR/storage/app/wg_server_public.key" && chown "$WEB_USER":"$WEB_USER" "$APP_DIR/storage/app/wg_server_public.key"
    local port=51820 prefix=10.66.66 wan
    wan="$(ip route get 8.8.8.8 2>/dev/null | awk '{for(i=1;i<=NF;i++) if ($i=="dev") print $(i+1)}' | head -1)"; wan="${wan:-eth0}"
    if [ ! -f /etc/wireguard/wg0.conf ]; then
        cat > /etc/wireguard/wg0.conf <<EOF
[Interface]
Address = ${prefix}.1/24
ListenPort = ${port}
PrivateKey = $(cat /etc/wireguard/server_private.key)
PostUp = iptables -I INPUT 1 -p udp --dport ${port} -j ACCEPT; iptables -I INPUT 1 -i wg0 -j ACCEPT; iptables -I FORWARD 1 -i wg0 -j ACCEPT; iptables -I FORWARD 1 -o wg0 -j ACCEPT; iptables -t nat -A POSTROUTING -o ${wan} -j MASQUERADE; iptables -t nat -A POSTROUTING -o wg0 -j MASQUERADE
PostDown = iptables -D INPUT -p udp --dport ${port} -j ACCEPT; iptables -D INPUT -i wg0 -j ACCEPT; iptables -D FORWARD -i wg0 -j ACCEPT; iptables -D FORWARD -o wg0 -j ACCEPT; iptables -t nat -D POSTROUTING -o ${wan} -j MASQUERADE; iptables -t nat -D POSTROUTING -o wg0 -j MASQUERADE
EOF
        chmod 600 /etc/wireguard/wg0.conf
    fi
    grep -q '^net.ipv4.ip_forward=1' /etc/sysctl.conf || echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf
    sysctl -w net.ipv4.ip_forward=1 >/dev/null
    command -v ufw >/dev/null && ufw allow ${port}/udp >/dev/null 2>&1
    systemctl enable wg-quick@wg0 >/dev/null 2>&1
    systemctl restart wg-quick@wg0
    systemctl is-active --quiet wg-quick@wg0 && ok "WireGuard wg0 aktif (UDP ${port})." || warn "wg-quick@wg0 gagal start — cek: journalctl -u wg-quick@wg0"
}

step_whatsapp() {
    if ! command -v node >/dev/null || [ "$(node -v | tr -dc 0-9 | cut -c1-2)" -lt 18 ]; then
        info "Memasang Node.js 20…"
        curl -fsSL https://deb.nodesource.com/setup_20.x | bash - >/dev/null 2>&1
        apt_install nodejs
    fi
    local dir="$APP_DIR/wa-service" url
    [ -f "$dir/server.js" ] || die "Folder wa-service tidak ditemukan."
    if [ ! -d "$dir/node_modules/@whiskeysockets" ]; then
        info "npm install layanan WhatsApp…"
        chown -R "$WEB_USER":"$WEB_USER" "$dir"
        mkdir -p "$APP_DIR/storage/app/npm-home" && chown "$WEB_USER":"$WEB_USER" "$APP_DIR/storage/app/npm-home"
        (cd "$dir" && sudo -u "$WEB_USER" env HOME="$APP_DIR/storage/app/npm-home" npm install --omit=dev --no-audit --no-fund >/dev/null 2>&1) || die "npm install gagal di $dir"
    fi
    url="$(grep -E '^APP_URL=' "$APP_DIR/.env" 2>/dev/null | cut -d= -f2- | tr -d '"'"'")"
    cat > /etc/systemd/system/snet-wa.service <<EOF
[Unit]
Description=S.NET WhatsApp Gateway (Baileys)
After=network.target
[Service]
Type=simple
User=$WEB_USER
Group=$WEB_USER
WorkingDirectory=$dir
ExecStart=$(command -v node) $dir/server.js
Restart=always
RestartSec=5
Environment=PORT=3000
Environment=NODE_ENV=production
Environment=WEBHOOK_URL=${url:-http://127.0.0.1}/webhooks/wa-inbox
[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload; systemctl enable snet-wa >/dev/null 2>&1; systemctl restart snet-wa
    sleep 2
    systemctl is-active --quiet snet-wa && ok "Layanan WhatsApp aktif (127.0.0.1:3000)." || warn "snet-wa gagal start — cek: journalctl -u snet-wa"
}

step_queue() {
    cat > /etc/systemd/system/snet-queue.service <<EOF
[Unit]
Description=S.NET MANAGER queue worker (config ONT)
After=network.target mariadb.service mysql.service
[Service]
User=$WEB_USER
Group=$WEB_USER
WorkingDirectory=$APP_DIR
ExecStart=${PHP_CLI[*]} $APP_DIR/artisan queue:work --sleep=3 --tries=1 --max-time=3600
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload; systemctl enable snet-queue >/dev/null 2>&1
    if [ -f "$APP_DIR/.env" ]; then systemctl restart snet-queue; ok "Queue worker aktif."; else ok "Queue worker terpasang (aktif otomatis setelah install.php)."; fi
}

step_cron() {
    echo "* * * * * $WEB_USER cd $APP_DIR && ${PHP_CLI[*]} artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/snet-manager
    chmod 644 /etc/cron.d/snet-manager
    ok "Cron scheduler terpasang (/etc/cron.d/snet-manager)."
}

step_sudoers() {
    # Salinan milik root: user web hanya boleh menjalankan skrip ini (bukan file di folder aplikasi yang bisa ia ubah).
    install -o root -g root -m 755 "$SELF" /usr/local/sbin/snet-setup
    echo "$APP_DIR" > /etc/snet-manager.path; chmod 644 /etc/snet-manager.path
    local wg ipt ip sc
    wg="$(command -v wg || echo /usr/bin/wg)"; ipt="$(command -v iptables || echo /usr/sbin/iptables)"; ip="$(command -v ip || echo /usr/sbin/ip)"; sc="$(command -v systemctl || echo /usr/bin/systemctl)"
    cat > /etc/sudoers.d/snet-manager <<EOF
# S.NET MANAGER — perintah yang boleh dijalankan aplikasi tanpa password
$WEB_USER ALL=(root) NOPASSWD: /usr/local/sbin/snet-setup, $wg, $(dirname "$wg")/wg-quick, $ipt, $ip, $sc restart freeradius, $sc restart wg-quick@*, $sc restart snet-wa, $sc restart snet-queue
EOF
    chmod 440 /etc/sudoers.d/snet-manager
    visudo -cf /etc/sudoers.d/snet-manager >/dev/null && ok "Izin sudo aplikasi dipasang (user $WEB_USER)." || { rm -f /etc/sudoers.d/snet-manager; die "sudoers tidak valid, dibatalkan."; }
}

step_permissions() {
    mkdir -p "$APP_DIR"/storage/{app,logs} "$APP_DIR"/storage/framework/{cache,sessions,views} "$APP_DIR/bootstrap/cache"
    chown -R "$WEB_USER":"$WEB_USER" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" "$APP_DIR/wa-service"
    chown "$WEB_USER":"$WEB_USER" "$APP_DIR"
    # Upload lewat file manager sering membuat semua file 777 — rapikan ke 755/644 (storage & cache tetap bisa ditulis).
    find "$APP_DIR" -path "$APP_DIR/vendor" -prune -o -path "$APP_DIR/wa-service/node_modules" -prune -o -type d -exec chmod 755 {} + 2>/dev/null
    find "$APP_DIR" -path "$APP_DIR/vendor" -prune -o -path "$APP_DIR/wa-service/node_modules" -prune -o -type f -exec chmod 644 {} + 2>/dev/null
    chmod 755 "$APP_DIR/artisan" "$APP_DIR/scripts/setup-server.sh"
    chmod -R ug+rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
    [ -f "$APP_DIR/.env" ] && chmod 640 "$APP_DIR/.env"
    ok "Hak akses folder dirapikan (755/644; storage & bootstrap/cache bisa ditulis $WEB_USER)."
}

case "$STEP" in
    all)
        info "Folder aplikasi: $APP_DIR (user web: $WEB_USER, aaPanel: $([ "$AAPANEL" -eq 1 ] && echo ya || echo tidak))"
        step_base; step_web; step_php; step_composer; step_mysql; step_freeradius; step_wireguard; step_whatsapp
        step_queue; step_cron; step_permissions; step_sudoers
        echo; ok "Selesai. Buka http://$(hostname -I 2>/dev/null | awk '{print $1}')/install.php (atau domain Anda) untuk melanjutkan."
        ;;
    web|php|composer|mysql|freeradius|wireguard|whatsapp|queue|cron|sudoers|permissions) "step_$STEP" ;;
    post-install)   # dipanggil install.php setelah database siap
        step_freeradius; step_queue; [ -f /etc/systemd/system/snet-wa.service ] && step_whatsapp; step_permissions ;;
    *) die "Langkah tidak dikenal: $STEP" ;;
esac
