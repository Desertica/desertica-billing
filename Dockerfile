FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction \
        --ignore-platform-req=ext-soap --ignore-platform-req=ext-zip
COPY src ./src
RUN composer dump-autoload --no-dev --classmap-authoritative \
        --ignore-platform-req=ext-soap --ignore-platform-req=ext-zip


FROM php:8.3-cli-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends libxml2-dev libzip-dev wkhtmltopdf fontconfig fonts-dejavu-core \
    && docker-php-ext-install soap zip pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*
RUN groupadd --system --gid 10001 billing && useradd --system --uid 10001 --gid billing --home-dir /app billing
WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY src ./src
COPY public ./public
COPY composer.json ./
RUN mkdir -p /var/lib/billing && chown billing:billing /var/lib/billing
ENV BILLING_STORAGE_DIR=/var/lib/billing \
    PHP_CLI_SERVER_WORKERS=4
USER billing
EXPOSE 8080
VOLUME ["/var/lib/billing"]
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/health") === false ? 1 : 0);'
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
