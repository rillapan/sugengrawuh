FROM --platform=linux/amd64 composer:2 AS composer_bin

FROM --platform=linux/amd64 php:8.2-fpm-bookworm

LABEL maintainer="Sugeng Rawuh Billing & POS"
LABEL description="Production PHP 8.2-FPM runtime for Sugeng Rawuh Billing & POS (linux/amd64)"

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=Asia/Jakarta \
    COMPOSER_ALLOW_SUPERUSER=1 \
    CONTAINER_ROLE=app

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
        default-mysql-client \
        unzip \
        curl \
        procps \
        iproute2 \
        netcat-openbsd \
        gosu \
        ca-certificates \
        tzdata \
    && ln -snf /usr/share/zoneinfo/$TZ /etc/localtime \
    && echo $TZ > /etc/timezone \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        sockets \
        intl \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

COPY docker/php/php-production.ini /usr/local/etc/php/conf.d/99-sugeng-rawuh.ini
COPY --from=composer_bin /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . /var/www/html

RUN rm -f /var/www/html/.env \
        /var/www/html/.env.production \
        /var/www/html/bootstrap/cache/*.php \
    && if [ -f /var/www/html/vendor/autoload.php ]; then \
        composer dump-autoload --optimize --no-scripts; \
    else \
        composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts; \
    fi \
    && mkdir -p \
        /var/www/html/storage/app/public \
        /var/www/html/storage/framework/cache/data \
        /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/testing \
        /var/www/html/storage/framework/views \
        /var/www/html/storage/logs \
        /var/www/html/bootstrap/cache \
        /usr/src/storage-skel \
    && cp -a /var/www/html/storage/. /usr/src/storage-skel/ \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /usr/src/storage-skel \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint-sr.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-sr.sh

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/docker-entrypoint-sr.sh"]
CMD ["php-fpm"]

