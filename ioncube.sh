#!/bin/bash

uname=$(uname -i)
if [[ $uname == x86_64 ]]; then
  wget -4 https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz
  sudo tar xzf ioncube_loaders_lin_x86-64.tar.gz -C /usr/local
  sudo rm -rf ioncube_loaders_lin_x86-64.tar.gz
fi
if [[ $uname == aarch64 ]]; then
  wget -4 https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_aarch64.tar.gz
  sudo tar xzf ioncube_loaders_lin_aarch64.tar.gz -C /usr/local
  sudo rm -rf ioncube_loaders_lin_aarch64.tar.gz
fi

# Resolve the actual PHP major/minor version (for example 8.1).
PHPVERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
PHP_FPM_DIR="/etc/php/${PHPVERSION}/fpm"
PHP_CLI_DIR="/etc/php/${PHPVERSION}/cli"
ZEND_EXTENSION_PATH="/usr/local/ioncube/ioncube_loader_lin_${PHPVERSION}.so"

mkdir -p "${PHP_FPM_DIR}/conf.d" "${PHP_CLI_DIR}/conf.d"

echo "zend_extension = ${ZEND_EXTENSION_PATH}" > "${PHP_FPM_DIR}/conf.d/00-ioncube.ini"
echo "zend_extension = ${ZEND_EXTENSION_PATH}" > "${PHP_CLI_DIR}/conf.d/00-ioncube.ini"

# Remove old ionCube entries from main php.ini files to prevent duplicate loading.
if [ -f "${PHP_FPM_DIR}/php.ini" ]; then
  sed -i '/^[[:space:]]*zend_extension[[:space:]]*=.*ioncube_loader_lin_/d' "${PHP_FPM_DIR}/php.ini"
fi
if [ -f "${PHP_CLI_DIR}/php.ini" ]; then
  sed -i '/^[[:space:]]*zend_extension[[:space:]]*=.*ioncube_loader_lin_/d' "${PHP_CLI_DIR}/php.ini"
fi

systemctl restart "php${PHPVERSION}-fpm"
systemctl restart nginx
