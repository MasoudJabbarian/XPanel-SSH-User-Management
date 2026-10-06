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
PHPVERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
PHP_FPM_DIR="/etc/php/${PHPVERSION}/fpm"
PHP_CLI_DIR="/etc/php/${PHPVERSION}/cli"
ZEND_EXTENSION_PATH="/usr/local/ioncube/ioncube_loader_lin_${PHPVERSION}.so"
if [ ! -f "$ZEND_EXTENSION_PATH" ]; then echo "ionCube loader not found: $ZEND_EXTENSION_PATH"; exit 1; fi

mkdir -p "${PHP_FPM_DIR}/conf.d" "${PHP_CLI_DIR}/conf.d"
for ini in "${PHP_FPM_DIR}/php.ini" "${PHP_CLI_DIR}/php.ini" "${PHP_FPM_DIR}/conf.d/"*.ini "${PHP_CLI_DIR}/conf.d/"*.ini; do
  [ -f "$ini" ] && sed -i "/^[[:space:]]*zend_extension[[:space:]]*=.*ioncube_loader_lin_/d" "$ini"
done
printf "%s\\n" "zend_extension = ${ZEND_EXTENSION_PATH}" > "${PHP_FPM_DIR}/conf.d/00-ioncube.ini"
printf "%s\\n" "zend_extension = ${ZEND_EXTENSION_PATH}" > "${PHP_CLI_DIR}/conf.d/00-ioncube.ini"

mkdir -p "/etc/systemd/system/php${PHPVERSION}-fpm.service.d"
cat > "/etc/systemd/system/php${PHPVERSION}-fpm.service.d/override.conf" <<EOF
[Service]
ProtectSystem=false
EOF
systemctl daemon-reload
systemctl restart "php${PHPVERSION}-fpm"
systemctl restart nginx
php -v | grep -qi "ioncube" || { echo "ionCube failed to load"; exit 1; }
