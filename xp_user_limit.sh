#!/usr/bin/env bash
set -Eeuo pipefail

[[ ${EUID} -eq 0 ]] || { echo "This script must be run as root." >&2; exit 1; }
[[ $# -eq 3 ]] || { echo "Usage: $0 <add|del> <username> <max_logins>" >&2; exit 1; }

action="$1"; username="$2"; max_logins="$3"
[[ "$username" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || { echo "Invalid Linux username." >&2; exit 1; }
[[ "$max_logins" =~ ^[0-9]+$ ]] || { echo "Invalid max_logins value." >&2; exit 1; }

limits_file="/etc/security/limits.conf"
[[ -f "$limits_file" ]] || { echo "Missing $limits_file." >&2; exit 1; }

tmp_file="$(mktemp)"
trap 'rm -f "$tmp_file" "$tmp_file.new"' EXIT
cp -- "$limits_file" "$tmp_file"

case "$action" in
  add)
    awk -v user="$username" -v max="$max_logins" '
      $1 == user && $2 == "hard" && $3 == "maxlogins" { $4 = max; found = 1 }
      { print }
      END { if (!found) print user, "hard", "maxlogins", max }
    ' "$tmp_file" > "$tmp_file.new"
    ;;
  del)
    awk -v user="$username" '
      !($1 == user && $2 == "hard" && $3 == "maxlogins") { print }
    ' "$tmp_file" > "$tmp_file.new"
    ;;
  *)
    echo "Unknown action: $action" >&2; exit 1
    ;;
esac

install -o root -g root -m 0644 "$tmp_file.new" "$limits_file"
