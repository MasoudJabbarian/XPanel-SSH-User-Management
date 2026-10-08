#!/usr/bin/env bash
set -Eeuo pipefail

# Stable installer entrypoint: always installs the current master tree.
# Supports: curl -fsSL https://raw.githubusercontent.com/MasoudJabbarian/XPanel-SSH-User-Management/master/install.sh | sudo bash

REPO="https://github.com/MasoudJabbarian/XPanel-SSH-User-Management"
BRANCH="master"
TMP_DIR="$(mktemp -d /tmp/xpanel-install.XXXXXX)"
ARCHIVE="$TMP_DIR/xpanel.tar.gz"
EXTRACT_DIR="$TMP_DIR/src"

cleanup() {
  rm -rf -- "$TMP_DIR"
}
trap cleanup EXIT

die() {
  echo "ERROR: $*" >&2
  exit 1
}

[[ "${EUID}" -eq 0 ]] || die "Run as root."
command -v curl >/dev/null 2>&1 || die "curl is required."
command -v tar >/dev/null 2>&1 || die "tar is required."

echo "Downloading XPanel SSH installer from branch: ${BRANCH}"
curl -fsSL --retry 3 --retry-delay 2 \
  -o "$ARCHIVE" \
  "$REPO/archive/refs/heads/${BRANCH}.tar.gz"

mkdir -p "$EXTRACT_DIR"
tar -xzf "$ARCHIVE" -C "$EXTRACT_DIR" --strip-components=1
[[ -f "$EXTRACT_DIR/install-master.sh" ]] || die "Installer implementation was not found in master archive."

chmod 0755 "$EXTRACT_DIR/install-master.sh"
"$EXTRACT_DIR/install-master.sh"
