#!/bin/sh
set -e
# Render injeta PORT dinamico; Apache padrao escuta em 80.
if [ -n "${PORT}" ]; then
  sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
fi
exec apache2-foreground
