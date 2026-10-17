#!/usr/bin/env bash
# deploy/scripts/*.sh için ortak yardımcılar. Doğrudan çalıştırılmaz, "source" ile yüklenir.

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
COMPOSE_FILE="${COMPOSE_FILE:-$PROJECT_DIR/docker-compose.prod.yml}"
ENV_FILE="${ENV_FILE:-$PROJECT_DIR/.env}"

log() { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }
warn() { printf '[%s] UYARI: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2; }
die() {
    printf '[%s] HATA: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2
    exit 1
}

dc() { docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"; }

require_env_file() {
    [[ -f "$ENV_FILE" ]] || die "$ENV_FILE bulunamadı. .env.production.example dosyasından oluşturun (KURULUM_KILAVUZU bölüm 8)."
}

# .env içinden tek bir değeri okur (dosya "source" edilmez; değerdeki $, boşluk vb. güvenli kalır).
# Çevreleyen tek/çift tırnaklar kaldırılır. Değer yoksa ikinci parametre (varsayılan) döner.
env_value() {
    local key="$1" default="${2:-}" line value
    line="$(grep -E "^[[:space:]]*${key}=" "$ENV_FILE" | tail -n 1 || true)"
    if [[ -z "$line" ]]; then
        printf '%s' "$default"
        return
    fi
    value="${line#*=}"
    value="${value%$'\r'}"
    if [[ "$value" =~ ^\"(.*)\"$ ]] || [[ "$value" =~ ^\'(.*)\'$ ]]; then
        value="${BASH_REMATCH[1]}"
    fi
    if [[ -z "$value" ]]; then
        value="$default"
    fi
    printf '%s' "$value"
}

db_name() { env_value DB_DATABASE uretim; }
db_owner() { env_value DB_OWNER_USERNAME uretim; }
db_app_user() { env_value DB_USERNAME uretim_app; }

# Veritabanı konteynerini başlatır ve sağlıklı olana kadar bekler.
start_db() {
    log "Veritabanı başlatılıyor..."
    dc up -d --wait db >/dev/null || die "Veritabanı konteyneri sağlıklı hâle gelmedi (dc logs db ile bakın)."
}

# Uygulama kullanıcısını oluşturur/günceller ve yetkilerini verir (deploy/db/app-role.sql).
apply_app_role() {
    local app_password
    app_password="$(env_value DB_PASSWORD)"
    [[ -n "$app_password" ]] || die ".env içinde DB_PASSWORD boş."
    [[ "$(db_app_user)" != "$(db_owner)" ]] || die "DB_USERNAME tablo sahibiyle (DB_OWNER_USERNAME) aynı olamaz."

    log "Uygulama veritabanı kullanıcısı ($(db_app_user)) ve yetkileri ayarlanıyor..."
    APP_DB_PASSWORD="$app_password" dc exec -T -e APP_DB_PASSWORD db \
        psql -X -q -U "$(db_owner)" -d "$(db_name)" \
        -v app_user="$(db_app_user)" -v owner_user="$(db_owner)" -v db_name="$(db_name)" \
        -f - <"$PROJECT_DIR/deploy/db/app-role.sql" \
        || die "Uygulama veritabanı kullanıcısı ayarlanamadı."
}

# Migration'ları tablo sahibiyle, tek seferlik bir konteynerde çalıştırır. Bu konteynerde ayar
# önbelleği kurulmaz (docker-entrypoint.sh), sahip bilgileri çalışan uygulamaya geçmez.
run_migrations() {
    local owner_password
    owner_password="$(env_value DB_OWNER_PASSWORD)"
    [[ -n "$owner_password" ]] || die ".env içinde DB_OWNER_PASSWORD boş."

    log "Migration'lar tablo sahibiyle ($(db_owner)) çalıştırılıyor..."
    DB_USERNAME="$(db_owner)" DB_PASSWORD="$owner_password" \
        dc run --rm --no-deps -T -e DB_USERNAME -e DB_PASSWORD app php artisan migrate --force \
        || die "Migration başarısız."
}

# /up sağlık kontrolü; 90 saniye boyunca tekrar dener.
wait_for_app() {
    log "Uygulama sağlık kontrolü (/up)..."
    for _ in $(seq 1 30); do
        if dc exec -T app curl -fsS -o /dev/null http://localhost:80/up 2>/dev/null; then
            log "Uygulama çalışıyor."
            return 0
        fi
        sleep 3
    done
    die "Uygulama 90 saniyede sağlık kontrolünü geçemedi. Loglara bakın: dc logs --tail=100 app"
}
