#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${XPANEL_HOST_PORT:-8081}"

php "$ROOT/artisan" serve --host=0.0.0.0 --port="$PORT"

