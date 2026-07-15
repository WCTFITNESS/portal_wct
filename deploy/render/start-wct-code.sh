#!/bin/sh
set -e

cd /var/www/html/portal_wct/wct-code

export WCT_CODE_PORT=3001
export WCT_CODE_BASE_PATH=/wct-code-app
export WCT_CODE_BYPASS_AUTH=1
export WCT_CODE_INTERNAL_SECRET="${WCT_CODE_INTERNAL_SECRET:-wct-internal}"
export PORTAL_HTTP_PORT="${PORT:-80}"
export PORTAL_BASE_URL="${PORTAL_BASE_URL:-/}"

if [ ! -d node_modules ]; then
  npm install --omit=dev
fi

node app.js >> /var/log/wct-code.log 2>&1 &

echo "[docker-entrypoint] WCT Code Node iniciado na porta ${WCT_CODE_PORT}"
