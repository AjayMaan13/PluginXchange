# Two targets in one file.
#   dev         php-fpm with dev tools (pcov, git, composer). docker-compose builds this one and mounts the source.
#   production  the release image: one container (Apache + mod_php) that serves HTTP itself.
#               Built last, so a plain `docker build` gives the production image.

# ---- dev ----
FROM php:8.3-fpm AS dev

RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_mysql \
    && pecl install pcov \
    && docker-php-ext-enable pcov

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ---- production: dependencies without dev packages ----
FROM composer:2 AS deps

WORKDIR /app
COPY composer.json composer.lock ./
COPY src ./src
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# ---- production: the release image ----
FROM php:8.3-apache AS production

RUN docker-php-ext-install pdo_mysql \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo 'expose_php = Off' > "$PHP_INI_DIR/conf.d/zz-hardening.ini" \
    && sed -i 's/Listen 80$/Listen 8080/' /etc/apache2/ports.conf

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
# Only what runs: no tests, docs, tools or dev packages. Root-owned and read-only for the web user.
COPY --from=deps /app/vendor ./vendor
COPY src ./src
COPY public ./public

# Run as the web user on an unprivileged port. Apache needs to write its pid and lock files.
RUN chown -R www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2
USER www-data
EXPOSE 8080

# "/" answers without touching the database, so a database outage does not restart the container
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/") === false ? 1 : 0);'
