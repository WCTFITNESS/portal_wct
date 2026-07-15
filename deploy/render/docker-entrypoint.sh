#!/bin/sh
# Sem set -e: falhas pontuais no init nao devem impedir apache2-foreground (evita portal inteiro em 502).
# Render envia PORT (ex.: 10000); VirtualHost deve usar a mesma porta que Listen.

LISTEN_PORT="${PORT:-80}"

LISTEN_CONF="/etc/apache2/ports.conf"
if [ -f "$LISTEN_CONF" ]; then
  sed -i "s/^Listen .*/Listen ${LISTEN_PORT}/" "$LISTEN_CONF"
fi

SITE="/etc/apache2/sites-available/000-default.conf"
if [ -f "$SITE" ]; then
  # Imagem oficial php-apache usa <VirtualHost *:80>
  sed -i "s|<VirtualHost \*:80>|<VirtualHost *:${LISTEN_PORT}>|g" "$SITE"
  sed -i "s|<VirtualHost \*:8080>|<VirtualHost *:${LISTEN_PORT}>|g" "$SITE"
fi

# Grava DB a partir das env vars do Docker (CLI vê sempre; evita PDO com host=mysql por mod_php).
php /var/www/html/portal_wct/deploy/render/bake-db-runtime.php || echo "[docker-entrypoint] bake-db-runtime avisou falha (ver logs)." >&2
# Nunca impedir Apache: falha aqui antes gerava 502 em todo o site (set -e + exit!=0 / excecao PHP).
php /var/www/html/portal_wct/deploy/render/init-schema.php || echo "[docker-entrypoint] init-schema encerrou com erro (Apache sobe igual)." >&2

/usr/local/bin/start-wct-code.sh || echo "[docker-entrypoint] WCT Code Node nao iniciou (ver /var/log/wct-code.log)." >&2

exec apache2-foreground
