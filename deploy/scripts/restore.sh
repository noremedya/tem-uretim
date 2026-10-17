#!/usr/bin/env bash
# Geri yükleme (KURULUM_KILAVUZU bölüm 14). root olarak çalışır:
#   sudo ./deploy/scripts/restore.sh /var/backups/uretim/db-20260924-0230.dump [/var/backups/uretim/files-20260924-0230.tar.gz]
#
# Onay ister → mevcut veritabanının güvenlik dökümünü alır → app/worker/scheduler durdurulur →
# veritabanı şeması boşaltılır ve döküm pg_restore --clean --if-exists ile tek transaction'da yüklenir →
# (verildiyse) dosya arşivi storage/app'e açılır → migration ve uygulama kullanıcısı yetkileri →
# servisler başlatılır → optimize → /up kontrolü.
#
# Şema önce boşaltılır: dökümde olmayan (daha yeni migration'larla eklenmiş) tablolar kalırsa
# sonraki migration çakışır.
set -euo pipefail

# shellcheck source=deploy/scripts/lib.sh
source "$(dirname "$0")/lib.sh"

BACKUP_DIR="${BACKUP_DIR:-/var/backups/uretim}"

usage() { die "Kullanım: $0 <db-....dump> [files-....tar.gz]"; }

[[ $# -ge 1 && $# -le 2 ]] || usage
dump_file="$1"
files_file="${2:-}"

[[ $EUID -eq 0 ]] || die "Bu betik root olarak çalışmalı (sudo $0 ...)."
require_env_file
[[ -f "$dump_file" ]] || die "Döküm dosyası bulunamadı: $dump_file"
[[ -z "$files_file" || -f "$files_file" ]] || die "Dosya arşivi bulunamadı: $files_file"

start_db
dc exec -T db pg_restore -l >/dev/null <"$dump_file" || die "Döküm dosyası okunamadı (bozuk veya pg_dump -Fc değil): $dump_file"
if [[ -n "$files_file" ]]; then
    tar tzf "$files_file" >/dev/null || die "Dosya arşivi okunamadı: $files_file"
fi

echo
echo "DİKKAT: '$(db_name)' veritabanındaki TÜM veriler silinip şu yedekle değiştirilecek:"
echo "  Veritabanı: $dump_file"
[[ -n "$files_file" ]] && echo "  Dosyalar:   $files_file (storage/app içeriği değiştirilir)"
echo
read -r -p "Devam etmek için büyük harflerle EVET yazın: " answer
[[ "$answer" == "EVET" ]] || die "Onay verilmedi, hiçbir şey değişmedi."

umask 077
mkdir -p "$BACKUP_DIR"
safety_file="$BACKUP_DIR/pre-restore-$(date +%Y%m%d-%H%M%S).dump"
log "Mevcut veritabanının güvenlik dökümü alınıyor: $safety_file"
dc exec -T db pg_dump -U "$(db_owner)" -d "$(db_name)" -Fc >"$safety_file"

log "app, worker ve scheduler durduruluyor..."
dc stop app worker scheduler

log "Veritabanı şeması boşaltılıyor..."
dc exec -T db psql -X -q -v ON_ERROR_STOP=1 -U "$(db_owner)" -d "$(db_name)" \
    -c "DROP SCHEMA public CASCADE; CREATE SCHEMA public AUTHORIZATION pg_database_owner; GRANT USAGE ON SCHEMA public TO PUBLIC;"

# Döküm, uygulama kullanıcısına verilmiş yetkileri içerir; kullanıcı yüklemeden önce var olmalı.
apply_app_role

log "Döküm yükleniyor (pg_restore)..."
if ! dc exec -T db pg_restore -U "$(db_owner)" -d "$(db_name)" --clean --if-exists \
    --single-transaction --exit-on-error <"$dump_file"; then
    die "pg_restore başarısız. Veritabanı boş kalmış olabilir. Önceki hâle dönmek için: $0 $safety_file"
fi

if [[ -n "$files_file" ]]; then
    log "Dosyalar geri yükleniyor (storage/app)..."
    dc run --rm --no-deps -T --entrypoint sh app \
        -c 'rm -rf /app/storage/app && tar xzf - -C /app/storage' <"$files_file"
fi

"$PROJECT_DIR/deploy/scripts/db-migrate.sh"

log "Servisler başlatılıyor..."
dc up -d

dc exec -T app php artisan optimize
wait_for_app

log "Geri yükleme tamamlandı. Güvenlik dökümü: $safety_file"
