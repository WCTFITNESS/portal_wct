#!/bin/sh
# Nao usar set -e: falha do Node nao deve impedir o Apache.

cd /var/www/html/portal_wct/wct-code || exit 0

export WCT_CODE_PORT=3001
export WCT_CODE_BASE_PATH=/wct-code-app
export WCT_CODE_BYPASS_AUTH=1
export WCT_CODE_INTERNAL_SECRET="${WCT_CODE_INTERNAL_SECRET:-wct-internal}"
export PORTAL_HTTP_PORT="${PORT:-80}"
export PORTAL_BASE_URL="${PORTAL_BASE_URL:-/}"

if [ ! -d node_modules ]; then
  echo "[start-wct-code] node_modules ausente; tentando npm install..." >&2
  npm install --omit=dev --no-audit --no-fund >> /var/log/wct-code.log 2>&1 || true
fi

node app.js >> /var/log/wct-code.log 2>&1 &

echo "[start-wct-code] WCT Code Node em background na porta ${WCT_CODE_PORT}" >&2
