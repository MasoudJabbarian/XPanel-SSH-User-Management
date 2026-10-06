#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="/var/www/html/app"
WEB_DIR="/var/www/html/cp"
HELPER="/usr/local/sbin/xpanel-userctl"
LIMITER="/usr/local/bin/xp_user_limit"

die(){ echo "ERROR: $*" >&2; exit 1; }
[[ "${EUID}" -eq 0 ]] || die "Run as root."
[[ -f /etc/os-release ]] || die "Cannot detect operating system."
. /etc/os-release
[[ "${ID}" == "ubuntu" && "${VERSION_ID}" == "24.04" ]] || die "This installer supports Ubuntu 24.04 only."

validate_user(){ [[ "$1" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]]; }
validate_port(){ [[ "$1" =~ ^[0-9]+$ ]] && (($1>=1 && $1<=65535)); }

read -r -p "Panel admin username [admin]: " PANEL_ADMIN_USERNAME
PANEL_ADMIN_USERNAME="${PANEL_ADMIN_USERNAME:-admin}"
validate_user "$PANEL_ADMIN_USERNAME" || die "Invalid panel admin username."
read -r -s -p "Panel admin password: " PANEL_ADMIN_PASSWORD; echo
[[ -n "$PANEL_ADMIN_PASSWORD" ]] || die "Admin password cannot be empty."
read -r -p "SSH port [22]: " PORT_SSH; PORT_SSH="${PORT_SSH:-22}"
validate_port "$PORT_SSH" || die "Invalid SSH port."

DB_NAME="XPanel"
DB_USER="xpanel"
read -r -s -p "Database password [leave empty to generate]: " DB_PASSWORD; echo
if [[ -z "$DB_PASSWORD" ]]; then
  DB_PASSWORD="$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | head -c 32)"
fi

echo "[1/8] Installing Ubuntu packages..."
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y nginx mariadb-server openssh-server composer git unzip curl   php8.3-cli php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl   php8.3-zip php8.3-intl php8.3-opcache

echo "[2/8] Installing application..."
install -d -o root -g root -m 0755 /var/www/html
if [[ -d "$APP_DIR" && -f "$APP_DIR/.env" ]]; then
  cp -- "$APP_DIR/.env" /tmp/xpanel.env
else
  rm -f /tmp/xpanel.env
fi
rm -rf -- "$APP_DIR" "$WEB_DIR"
cp -a "$SCRIPT_DIR/Web Panel/app" "$APP_DIR"
cp -a "$SCRIPT_DIR/Web Panel/cp" "$WEB_DIR"
if [[ -f /tmp/xpanel.env ]]; then
  mv -- /tmp/xpanel.env "$APP_DIR/.env"
else
  cp -- "$APP_DIR/.env.example" "$APP_DIR/.env"
fi

echo "[3/8] Configuring database..."
systemctl enable --now mariadb
DB_PASSWORD_SQL="${DB_PASSWORD//\'/\'\'}"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD_SQL';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD_SQL';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "[4/8] Configuring Laravel..."
php -r '
$p = $argv[1];
$v = file_get_contents($p);
$values = [
 "APP_ENV" => "production",
 "APP_DEBUG" => "false",
 "APP_URL" => "http://localhost",
 "PANEL_DIRECT" => "cp",
 "DB_DATABASE" => "XPanel",
 "DB_USERNAME" => "xpanel",
 "DB_PASSWORD" => $argv[2],
 "PANEL_ADMIN_USERNAME" => $argv[3],
 "PANEL_ADMIN_PASSWORD" => $argv[4],
 "PORT_SSH" => $argv[5],
 "CRON_TRAFFIC" => "active",
 "ANTI_USER" => "active",
 "STATUS_LOG" => "deactive"
];
foreach ($values as $k => $val) {
 $line = $k . "=" . str_replace(["\r","\n"], "", $val);
 $re = "/^" . preg_quote($k, "/") . "=.*$/m";
 $v = preg_match($re, $v) ? preg_replace($re, $line, $v) : rtrim($v)."\n".$line."\n";
}
file_put_contents($p, $v, LOCK_EX);
' "$APP_DIR/.env" "$DB_PASSWORD" "$PANEL_ADMIN_USERNAME" "$PANEL_ADMIN_PASSWORD" "$PORT_SSH"
cd "$APP_DIR"
php artisan key:generate --force
php artisan migrate --force
php artisan storage:link || true

echo "[5/8] Installing privileged SSH helpers..."
install -o root -g root -m 0755 "$SCRIPT_DIR/support/xpanel-userctl" "$HELPER"
install -o root -g root -m 0755 "$SCRIPT_DIR/xp_user_limit.sh" "$LIMITER"
cat > /etc/sudoers.d/xpanel-www-data <<EOF
www-data ALL=(root) NOPASSWD: $HELPER
EOF
chmod 0440 /etc/sudoers.d/xpanel-www-data
visudo -cf /etc/sudoers.d/xpanel-www-data

echo "[6/8] Configuring SSH and scheduler..."
if ! grep -qE '^[[:space:]]*Port[[:space:]]+' /etc/ssh/sshd_config; then
  printf '\nPort %s\n' "$PORT_SSH" >> /etc/ssh/sshd_config
else
  sed -i -E "0,/^[[:space:]]*Port[[:space:]]+.*/s//Port $PORT_SSH/" /etc/ssh/sshd_config
fi
sshd -t
systemctl enable --now ssh
systemctl reload ssh

install -d -o root -g root -m 0755 /var/www/html/app/storage/banner
cat > /etc/cron.d/xpanel <<EOF
* * * * * www-data cd $APP_DIR && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
EOF
chmod 0644 /etc/cron.d/xpanel

echo "[7/8] Configuring Nginx..."
cat > /etc/nginx/sites-available/xpanel <<EOF
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    root $WEB_DIR;
    index index.php;

    client_max_body_size 20M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /index.php {
        include snippets/fastcgi-php.conf;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ \.php$ {
        return 404;
    }

    location ~ /\. {
        deny all;
    }
}
EOF
rm -f /etc/nginx/sites-enabled/default
ln -sfn /etc/nginx/sites-available/xpanel /etc/nginx/sites-enabled/xpanel
nginx -t
systemctl enable --now php8.3-fpm nginx
systemctl reload nginx

echo "[8/8] Applying permissions..."
chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
chmod -R ug+rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
chown -R root:root "$WEB_DIR"
find "$WEB_DIR" -type d -exec chmod 0755 {} +
find "$WEB_DIR" -type f -exec chmod 0644 {} +

echo
echo "XPanel SSH-only installation completed."
echo "Panel: http://<server-ip>/"
echo "SSH port: $PORT_SSH"
echo "Admin user: $PANEL_ADMIN_USERNAME"
echo "Database: $DB_NAME / $DB_USER"
