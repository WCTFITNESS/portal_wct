# Portal WCT — PHP + Apache (Render.com e Docker).
# No Render: Runtime = Docker, Dockerfile Path = ./Dockerfile
# Defina variaveis de ambiente (MySQL externo + URLs). Ver deploy/RENDER.txt

FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev nodejs npm \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_mysql mysqli pdo_pgsql pgsql \
    && a2enmod rewrite headers env proxy proxy_http \
    && mkdir -p /var/www/html/portal_wct/wct-code/public/relatorios

COPY . /var/www/html/portal_wct/
COPY deploy/portal/config.docker.php /var/www/html/portal_wct/config/config.php
COPY deploy/portal/apache-passenv.conf /etc/apache2/conf-enabled/z-wct-passenv.conf
COPY deploy/render/zzz-portal-docroot.conf /etc/apache2/conf-enabled/zzz-portal-docroot.conf
COPY deploy/render/wct-code-proxy.conf /etc/apache2/conf-enabled/wct-code-proxy.conf

RUN cd /var/www/html/portal_wct/wct-code \
    && npm install --omit=dev --no-audit --no-fund \
    && touch /var/log/wct-code.log \
    && chown -R www-data:www-data /var/www/html/portal_wct

RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/portal_wct|' /etc/apache2/sites-available/000-default.conf

COPY deploy/render/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
COPY deploy/render/start-wct-code.sh /usr/local/bin/start-wct-code.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh /usr/local/bin/start-wct-code.sh

# Raiz do site na URL publica (Render: URL sem subpasta)
ENV PORTAL_BASE_URL=/

WORKDIR /var/www/html/portal_wct

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
