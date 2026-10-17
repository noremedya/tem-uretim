#!/usr/bin/env bash
# Güncelleme (KURULUM_KILAVUZU bölüm 13). Proje klasöründe, uygulama kullanıcısıyla çalıştırılır:
#   ./deploy/scripts/deploy.sh
#
# Sıra: yedek (backup.sh, sudo ile) → git pull --ff-only → imaj derleme → veritabanı başlatma →
# migration (tablo sahibiyle) ve uygulama kullanıcısı yetkileri → up -d → optimize → /up kontrolü.
# Migration, yeni kod çalışmaya başlamadan önce yapılır; böylece yeni kod eski şemayla çalışmaz.
set -euo pipefail

# shellcheck source=deploy/scripts/lib.sh
source "$(dirname "$0")/lib.sh"

require_env_file
[[ -n "$(env_value APP_KEY)" ]] || die ".env içinde APP_KEY boş (KURULUM_KILAVUZU bölüm 10)."

cd "$PROJECT_DIR"

log "1/7 Yedek alınıyor..."
if [[ "${SKIP_BACKUP:-0}" == "1" ]]; then
    warn "SKIP_BACKUP=1: yedek atlandı."
elif [[ $EUID -eq 0 ]]; then
    "$PROJECT_DIR/deploy/scripts/backup.sh"
else
    sudo "$PROJECT_DIR/deploy/scripts/backup.sh"
fi

log "2/7 Kod güncelleniyor (git pull --ff-only)..."
git pull --ff-only

log "3/7 İmajlar derleniyor..."
dc build

log "4/7 Veritabanı güncelleniyor..."
"$PROJECT_DIR/deploy/scripts/db-migrate.sh"

log "5/7 Servisler yenileniyor..."
dc up -d --remove-orphans

log "6/7 Önbellekler yenileniyor (optimize)..."
dc exec -T app php artisan optimize

log "7/7 Sağlık kontrolü..."
wait_for_app

log "Güncelleme tamamlandı ($(git rev-parse --short HEAD))."
