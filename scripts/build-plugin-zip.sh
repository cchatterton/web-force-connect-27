#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/wordpress"
rm -f "$ROOT/alphasys-web-force-connect-27.zip"
zip -qr "$ROOT/alphasys-web-force-connect-27.zip" alphasys-web-force-connect-27 -x '*.DS_Store' '*.zip'
