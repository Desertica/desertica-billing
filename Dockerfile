FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction \
        --ignore-platform-req=ext-soap --ignore-platform-req=ext-zip
COPY src ./src
RUN composer dump-autoload --no-dev --classmap-authoritative \
        --ignore-platform-req=ext-soap --ignore-platform-req=ext-zip


# php-fpm + nginx (ver docs/DECISIONS.md, «Servidor de producción»).
FROM php:8.3-fpm-bookworm
# hadolint ignore=DL3008
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx libxml2-dev libzip-dev wkhtmltopdf fontconfig fonts-dejavu-core \
    && docker-php-ext-install soap zip opcache \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled /etc/nginx/conf.d /usr/local/etc/php-fpm.d \
    && groupadd --system --gid 10001 billing && useradd --system --uid 10001 --gid billing --home-dir /app billing \
    && mkdir -p /var/lib/billing /run/billing /tmp/nginx \
    && chown billing:billing /var/lib/billing /run/billing /tmp/nginx
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-billing.ini
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/billing-entrypoint
WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY src ./src
COPY public ./public
COPY bin ./bin
COPY composer.json ./
ENV BILLING_STORAGE_DIR=/var/lib/billing
USER 10001:10001
EXPOSE 8080
VOLUME ["/var/lib/billing"]
# Pasa por nginx y php-fpm: comprueba la cadena completa, no solo un proceso.
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD ["php", "-r", "exit(@file_get_contents('http://127.0.0.1:8080/health', false, stream_context_create(['http' => ['timeout' => 3]])) === false ? 1 : 0);"]
STOPSIGNAL SIGTERM
ENTRYPOINT ["/usr/local/bin/billing-entrypoint"]
