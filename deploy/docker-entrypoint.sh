#!/usr/bin/env bash
# Üretim konteyneri giriş noktası (deploy/Dockerfile).
# storage volume'unda gereken klasörleri açar; web sunucusu, kuyruk işçisi ve zamanlayıcı başlarken
# ayar/rota/görünüm önbelleklerini kurar. Tek seferlik komutlar (migrate, seed vb.) önbellek kurmaz;
# böylece migrate için verilen tablo sahibi bilgileri önbelleğe yazılmaz.
set -euo pipefail

mkdir -p storage/app/private storage/app/public storage/logs \
    storage/framework/cache/data storage/framework/sessions storage/framework/views

case "${1:-} ${2:-} ${3:-}" in
    "frankenphp "* | "php artisan queue:work" | "php artisan schedule:work")
        php artisan optimize --no-interaction >/dev/null
        ;;
esac

exec "$@"
