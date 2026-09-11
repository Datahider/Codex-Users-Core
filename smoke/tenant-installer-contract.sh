#!/usr/bin/env bash

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALLER="$PROJECT_ROOT/bin/install-tenant.php"

test -x "$INSTALLER"

help="$($INSTALLER --help)"
grep -Fq 'install-tenant.php /path/to/tenant.ini' <<<"$help"

tmp_dir="$(mktemp -d /home/web/tmp/tenant-installer-test.XXXXXX)"
trap 'rm -rf "$tmp_dir"' EXIT

cat > "$tmp_dir/invalid.ini" <<'EOF'
username=bad/name
telegram_chat_id=-100123
transcription_api_key=sk-test
EOF
chmod 600 "$tmp_dir/invalid.ini"

if "$INSTALLER" --validate "$tmp_dir/invalid.ini" >/dev/null 2>&1; then
    echo 'Installer accepted invalid INI data' >&2
    exit 1
fi

cat > "$tmp_dir/valid.ini" <<'EOF'
username=valid-name
telegram_chat_id=-100123
transcription_api_key=sk-test
EOF
chmod 644 "$tmp_dir/valid.ini"

if "$INSTALLER" --validate "$tmp_dir/valid.ini" >/dev/null 2>&1; then
    echo 'Installer accepted insecure INI permissions' >&2
    exit 1
fi

chmod 600 "$tmp_dir/valid.ini"
"$INSTALLER" --validate "$tmp_dir/valid.ini" >/dev/null

echo 'Tenant installer contract: OK'
