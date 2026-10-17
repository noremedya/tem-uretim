# Üretim imajı (docker-compose.prod.yml: app, worker, scheduler). Derleme bağlamı: proje kökü.
#   docker compose -f docker-compose.prod.yml build
# Aşamalar: base (PHP + eklentiler) → vendor (Composer, --no-dev) → assets (Vite) → son imaj.

# --- base: FrankenPHP + PHP eklentileri -------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4 AS base

# soap: Aşama 6 (Uyumsoft e-dönüşüm) için; pcntl: kuyruk işçisinin düzgün kapanması için.
RUN install-php-extensions pdo_pgsql intl zip gd bcmath opcache pcntl soap

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY deploy/php/production.ini "$PHP_INI_DIR/conf.d/zz-production.ini"

WORKDIR /app

# --- vendor: Composer bağımlılıkları (geliştirme paketleri hariç) ----------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Önce yalnızca kilit dosyası: bağımlılıklar değişmedikçe bu katman önbellekten gelir.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist

COPY . .
# post-autoload-dump: package:discover ve filament:upgrade (Filament JS/CSS/fontlarını public/ altına yayınlar).
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && rm -f bootstrap/cache/config.php bootstrap/cache/routes-*.php bootstrap/cache/events.php

# --- assets: Vite derlemesi (tema CSS'i vendor/filament'ten içe aktarır) ----------------------
FROM node:24-trixie-slim AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY --from=vendor /app /app
RUN npm run build

# --- son imaj ---------------------------------------------------------------------------------
FROM base

ARG UID=1000
ARG GID=1000

# Root olmayan kullanıcı. 80 portunu dinleyebilmesi için FrankenPHP'ye yalnızca bu yetki verilir.
RUN groupadd -g ${GID} app \
    && useradd -u ${UID} -g ${GID} -M -d /app -s /usr/sbin/nologin app \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && chown -R app:app /data /config

COPY --chown=app:app --from=vendor /app /app
COPY --chown=app:app --from=assets /app/public/build /app/public/build
COPY --chmod=755 deploy/docker-entrypoint.sh /usr/local/bin/app-entrypoint

USER app

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

EXPOSE 80

# Taban imajın healthcheck'i (localhost:2019/metrics) kullanılmaz; compose dosyası /up'ı yoklar.
HEALTHCHECK NONE

ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "php-server", "--root", "public/", "--listen", ":80"]
