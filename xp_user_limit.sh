#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "\${EUID}" -ne 0 ]]; then
  echo "This script must run as root." >&2
  exit 1
fi

if [[ "\$#" -ne 3 ]]; then
  echo "Usage: \$0 <add|del> <username> <max_logins>" >&2
  exit 1
fi

action="\$1"
username="\$2"
max_logins="\$3"

if [[ ! "\$username" =~ ^[a-z_][a-z0-9_-]{0,31}\$ ]]; then
  echo "Invalid Linux username." >&2
  exit 1
fi

if [[ ! "\$max_logins" =~ ^[0-9]+$ ]]; then
  echo "Invalid max_logins value." >&2
  exit 1
fi

limits_file="/etc/security/limits.conf"
[[ -f "\$limits_file" ]] || { echo "Error: \$limits_file not found." >&2; exit 1; }

tmp_file="\$(mktemp)"
trap 'rm -f "\$tmp_file" "\$tmp_file.new"' EXIT
cp -- "\$limits_file" "\$tmp_file"

case "\$action" in
  add)
    awk -v user="\$username" -v max="\$max_logins" '
      \$1 == user && \$2 == "hard" && \$3 == "maxlogins" { \$4 = max; found = 1 }
      { print }
      END { if (!found) print user, "hard", "maxlogins", max }
    ' "\$tmp_file" > "\$tmp_file.new"
    mv -- "\$tmp_file.new" "\$tmp_file"
    ;;
  del)
    awk -v user="\$username" '
      !(\$1 == user && \$2 == "hard" && \$3 == "maxlogins") { print }
    ' "\$tmp_file" > "\$tmp_file.new"
    mv -- "\$tmp_file.new" "\$tmp_file"
    ;;
  *)
    echo "Unknown action: \$action. Use add or del." >&2
    exit 1
    ;;
esac

install -o root -g root -m 0644 "\$tmp_file" "\$limits_file"
echo "User \$username limit updated."
