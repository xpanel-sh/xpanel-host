#!/usr/bin/env bash
set -Eeuo pipefail
if command -v codex >/dev/null 2>&1; then
  echo "Codex CLI disponible: $(command -v codex)"
  exit 0
fi
command -v npm >/dev/null 2>&1 || { echo 'Node.js/npm es necesario para instalar Codex.' >&2; exit 1; }
npm install --global --no-audit --no-fund @openai/codex
command -v codex >/dev/null 2>&1 || { echo 'Codex no quedó instalado.' >&2; exit 1; }
