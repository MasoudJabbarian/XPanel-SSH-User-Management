#!/usr/bin/env bash
set -Eeuo pipefail

# XPanel SSH - stable installer entrypoint.
# This file is intentionally standalone so it can be executed directly with:
# curl -fsSL https://raw.githubusercontent.com/MasoudJabbarian/XPanel-SSH-User-Management/master/install.sh | sudo bash
#
# The actual installer and all application/support files are taken from the
# Ubuntu 24.04 hardening branch, so the one-line installer cannot accidentally
# install an older release from another repository.

REPO="https://github.com/MasoudJabbarian/XPanel-SSH-User-Management"
BRANCH="fix/ubuntu-24-04-hardening"
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

echo "Downloading XPanel SSH installer from branch: $BRANCH"
curl -fsSL --retry 3 --retry-delay 2 \
  -o "$ARCHIVE" \
  "$REPO/archive/refs/heads/$BRANCH.tar.gz"

mkdir -p "$EXTRACT_DIR"
tar -xzf "$ARCHIVE" -C "$EXTRACT_DIR" --strip-components=1

[[ -f "$EXTRACT_DIR/install.sh" ]] || die "Installer was not found in branch archive."

chmod 0755 "$EXTRACT_DIR/install.sh"
exec "$EXTRACT_DIR/install.sh"
