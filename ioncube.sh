#!/usr/bin/env bash
set -Eeuo pipefail

if [[ ${EUID} -ne 0 ]]; then
  echo "Please run as root." >&2
  exit 1
fi

arch="$(dpkg --print-architecture)"
case "$arch" in
  amd64) loader_archive="ioncube_loaders_lin_x86-64.tar.gz" ;;
  arm64) loader_archive="ioncube_loaders_lin_aarch64.tar.gz" ;;
  *) echo "Unsupported architecture: $arch" >&2; exit 1 ;;
esac

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT
cd "$tmp_dir"

curl --fail --location --proto '=https' --tlsv1.2 -o "$loader_archive" "https://downloads.ioncube.com/loader_downloads/$loader_archive"
tar -xzf "$loader_archive" -C /usr/local

php_version="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
loader="/usr/local/ioncube/ioncube_loader_lin_${php_version}.so"
if [[ ! -f "$loader" ]]; then
  echo "ionCube loader for PHP $php_version was not found." >&2
  exit 1
fi

for sapi in cli fpm; do
  ini_dir="/etc/php/${php_version}/${sapi}/conf.d"
  install -d -m 0755 "$ini_dir"
  printf '%s\n' "zend_extension=$loader" > "$ini_dir/00-ioncube.ini"
done

systemctl restart "php${php_version}-fpm"
systemctl reload nginx || systemctl restart nginx

php -v
