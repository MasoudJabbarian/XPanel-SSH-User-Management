#!/usr/bin/env bash
set -Eeuo pipefail

if [[ ${EUID} -ne 0 ]]; then
  echo "Please run as root." >&2
  exit 1
fi

ENV_FILE="/var/www/html/app/.env"
if [[ ! -r "$ENV_FILE" ]]; then
  echo "Missing $ENV_FILE" >&2
  exit 1
fi

panel_port="$(awk -F= '/^PORT_PANEL=/{print $2}' "$ENV_FILE" | tail -n1 | tr -d '[:space:]')"
[[ "$panel_port" =~ ^[0-9]{1,5}$ ]] && (( panel_port >= 1 && panel_port <= 65535 )) || { echo "Invalid PORT_PANEL." >&2; exit 1; }

read -r -p "Please enter the pointed domain / sub-domain name: " domain
domain="${domain,,}"
if [[ ! "$domain" =~ ^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$ ]]; then
  echo "Invalid domain name." >&2
  exit 1
fi

apt-get update
apt-get install -y certbot python3-certbot-nginx

certbot --nginx --non-interactive --agree-tos --register-unsafely-without-email -d "$domain"

php_sock=""
for candidate in /run/php/php*-fpm.sock /var/run/php/php*-fpm.sock; do
  if [[ -S "$candidate" ]]; then
    php_sock="$candidate"
    break
  fi
done
[[ -n "$php_sock" ]] || { echo "Could not find a PHP-FPM socket." >&2; exit 1; }

cat > /etc/nginx/sites-available/xpanel <<EOF
server {
    listen 80;
    server_name $domain;
    root /var/www/html/example;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$php_sock;
        fastcgi_param PHP_VALUE "memory_limit=4096M";
    }

    location ~ /\.ht {
        deny all;
    }

    location /ws {
        if ($http_upgrade != "websocket") { return 404; }
        proxy_pass http://127.0.0.1:8880;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_read_timeout 52w;
    }
}

server {
    listen $panel_port ssl;
    server_name $domain;
    root /var/www/html/cp;
    index index.php index.html;

    ssl_certificate /etc/letsencrypt/live/$domain/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$domain/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$php_sock;
        fastcgi_param PHP_VALUE "memory_limit=4096M";
    }

    location ~ /\.ht {
        deny all;
    }
}
EOF

ln -sfn /etc/nginx/sites-available/xpanel /etc/nginx/sites-enabled/xpanel

cat > /var/www/html/kill.sh <<EOF
#!/usr/bin/env bash
set -Eeuo pipefail
for i in $(seq 1 10); do
  curl -fsS --max-time 30 "https://$domain:$panel_port/fixer/multiuser" >/dev/null || true
  sleep 6
done
EOF

cat > /var/www/html/other.sh <<EOF
#!/usr/bin/env bash
set -Eeuo pipefail
for i in $(seq 1 3); do
  curl -fsS --max-time 30 "https://$domain:$panel_port/fixer/other" >/dev/null || true
  sleep 17
done
EOF

chmod 0750 /var/www/html/kill.sh /var/www/html/other.sh
chown root:root /var/www/html/kill.sh /var/www/html/other.sh

cat > /etc/cron.d/xpanel <<EOF
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * root /var/www/html/kill.sh
* * * * * root /var/www/html/other.sh
0 * * * * root curl -fsS --max-time 30 "https://$domain:$panel_port/fixer/checkhurly" >/dev/null 2>&1
*/10 * * * * root curl -fsS --max-time 30 "https://$domain:$panel_port/fixer/checktraffic" >/dev/null 2>&1
*/15 * * * * root curl -fsS --max-time 30 "https://$domain:$panel_port/fixer/checkfilter" >/dev/null 2>&1
0 0 * * * root curl -fsS --max-time 30 "https://$domain:$panel_port/fixer/exp" >/dev/null 2>&1
EOF
chmod 0644 /etc/cron.d/xpanel

nginx -t
systemctl enable --now nginx
systemctl reload nginx
echo "HTTPS configured for https://$domain:$panel_port/"
