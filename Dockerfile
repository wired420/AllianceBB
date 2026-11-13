# AllianceBB on FrankenPHP (Caddy + PHP 8.3 in worker mode, with the Mercure hub built in).
FROM dunglas/frankenphp:1-php8.3

RUN install-php-extensions pdo_pgsql intl zip opcache pcntl \
 && apt-get update \
 && apt-get install -y --no-install-recommends postgresql-client unzip \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/alliancebb.ini
COPY docker/Caddyfile /etc/caddy/Caddyfile

# The release copy lives in /usr/src/alliancebb. On first start it is copied into
# /app (a volume), so updates made from the admin panel survive container restarts.
WORKDIR /usr/src/alliancebb
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && rm -rf tests phpunit.xml deploy .git

COPY docker/entrypoint.sh /usr/local/bin/alliancebb-entrypoint
RUN chmod +x /usr/local/bin/alliancebb-entrypoint

WORKDIR /app
VOLUME /app
ENTRYPOINT ["alliancebb-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
