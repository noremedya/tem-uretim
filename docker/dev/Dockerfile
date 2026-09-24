# Geliştirme imajı: FrankenPHP (PHP 8.4) + Composer + Node.
# Üretim imajı ayrıdır (deploy/Dockerfile, Aşama 8).
FROM dunglas/frankenphp:1-php8.4

ARG UID=1000
ARG GID=1000

RUN install-php-extensions pdo_pgsql intl zip gd bcmath opcache pcntl \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip postgresql-client \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=node:24-trixie-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-trixie-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && echo "memory_limit=512M" > "$PHP_INI_DIR/conf.d/zz-dev.ini"

# Proje dosyaları host kullanıcısına ait kalsın diye aynı UID/GID ile çalış.
RUN groupadd -g ${GID} app \
    && useradd -u ${UID} -g ${GID} -m app \
    && chown -R app:app /data/caddy /config/caddy /app

USER app
WORKDIR /app

EXPOSE 8000 5173

CMD ["frankenphp", "php-server", "--root", "public/", "--listen", ":8000"]
