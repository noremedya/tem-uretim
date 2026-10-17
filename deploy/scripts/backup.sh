#!/usr/bin/env bash
# Gece yedeği (KURULUM_KILAVUZU bölüm 12). cron ile root olarak çalışır:
#   30 2 * * * /opt/uretim/deploy/scripts/backup.sh >> /var/log/uretim-yedek.log 2>&1
#
# 1. /etc/uretim-yedek.env yüklenir (restic ve healthchecks bilgileri)
# 2. Veritabanı: pg_dump -Fc → /var/backups/uretim/db-YYYYMMDD-HHMM.dump
# 3. Yüklenen/üretilen dosyalar (storage/app) → files-YYYYMMDD-HHMM.tar.gz
# 4. /mnt/yedek bağlıysa USB diske kopya
# 5. restic ile buluta (B2) gönderim; 6. restic forget --keep-daily 30 --keep-monthly 12 --prune
# 7. Sunucuda 14 günden, USB diskte 60 günden eski yedekler silinir
# 8. Sonuç healthchecks.io'ya bildirilir ($HC_YEDEK_URL; hatada /fail)
#
# Yerel yedek (adım 2-3) başarısızsa betik hata verir. USB disk takılı değilse veya bulut
# gönderimi başarısızsa (ör. internet yok) uyarı yazılır, yerel yedekler korunur ve devam edilir;
# bulut gönderimi başarısızsa healthchecks'e "fail" bildirilir.
set -euo pipefail

# shellcheck source=deploy/scripts/lib.sh
source "$(dirname "$0")/lib.sh"

SECRETS_FILE="${SECRETS_FILE:-/etc/uretim-yedek.env}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/uretim}"
USB_MOUNT="${USB_MOUNT:-/mnt/yedek}"
KEEP_LOCAL_DAYS=14
KEEP_USB_DAYS=60

HC_YEDEK_URL=""
WARNINGS=()

ping_healthcheck() {
    local suffix="$1" body="$2"
    [[ -n "$HC_YEDEK_URL" ]] || return 0
    curl -fsS -m 10 --retry 3 -o /dev/null --data-raw "$body" "${HC_YEDEK_URL}${suffix}" 2>/dev/null \
        || warn "healthchecks bildirimi gönderilemedi."
}

FAILED_LINE=""
trap 'FAILED_LINE=$LINENO' ERR
on_exit() {
    local code=$?
    ((code == 0)) && return 0
    printf '[%s] HATA: Yedekleme başarısız (çıkış kodu %s%s).\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$code" "${FAILED_LINE:+, satır $FAILED_LINE}" >&2
    ping_healthcheck /fail "Yedekleme başarısız (çıkış kodu $code${FAILED_LINE:+, satır $FAILED_LINE})"
}
trap on_exit EXIT

[[ $EUID -eq 0 ]] || die "Bu betik root olarak çalışmalı (sudo $0)."

# 1. Gizli bilgiler
if [[ -f "$SECRETS_FILE" ]]; then
    set -a
    # shellcheck disable=SC1090
    source "$SECRETS_FILE"
    set +a
    HC_YEDEK_URL="${HC_YEDEK_URL:-}"
else
    warn "$SECRETS_FILE bulunamadı; bulut yedeği ve healthchecks bildirimi atlanacak."
fi

require_env_file
umask 077
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

stamp="$(date +%Y%m%d-%H%M)"
dump_file="$BACKUP_DIR/db-$stamp.dump"
files_file="$BACKUP_DIR/files-$stamp.tar.gz"

log "Yedekleme başladı ($stamp)."

# 2. Veritabanı dökümü (önce geçici dosyaya; tamamlanmamış döküm yedek gibi görünmesin)
log "Veritabanı dökümü alınıyor..."
dc exec -T db pg_dump -U "$(db_owner)" -d "$(db_name)" -Fc >"$dump_file.part"
dc exec -T db pg_restore -l >/dev/null <"$dump_file.part" || die "Döküm dosyası okunamadı (bozuk olabilir)."
mv "$dump_file.part" "$dump_file"
log "Veritabanı dökümü: $dump_file ($(du -h "$dump_file" | cut -f1))"

# 3. Dosyalar (storage volume'undaki storage/app)
log "Dosyalar arşivleniyor..."
dc run --rm --no-deps -T --entrypoint tar app czf - -C /app/storage app >"$files_file.part"
mv "$files_file.part" "$files_file"
log "Dosya arşivi: $files_file ($(du -h "$files_file" | cut -f1))"

# 4. USB disk
if mountpoint -q "$USB_MOUNT"; then
    log "USB diske kopyalanıyor ($USB_MOUNT/uretim)..."
    if mkdir -p "$USB_MOUNT/uretim" && cp "$dump_file" "$files_file" "$USB_MOUNT/uretim/"; then
        log "USB kopyası tamam."
    else
        WARNINGS+=("USB diske kopyalanamadı")
        warn "USB diske kopyalanamadı."
    fi
else
    WARNINGS+=("USB disk bağlı değil")
    warn "$USB_MOUNT bağlı değil; USB kopyası atlandı."
fi

# 5-6. Bulut (restic)
cloud_ok=false
if [[ -z "${RESTIC_REPOSITORY:-}" ]]; then
    WARNINGS+=("restic ayarlı değil")
    warn "RESTIC_REPOSITORY tanımlı değil; bulut yedeği atlandı."
elif ! command -v restic >/dev/null 2>&1; then
    WARNINGS+=("restic kurulu değil")
    warn "restic kurulu değil; bulut yedeği atlandı."
else
    log "Buluta gönderiliyor (restic)..."
    if restic backup --quiet --tag uretim "$BACKUP_DIR"; then
        log "Bulut yedeği tamam."
        if restic forget --quiet --keep-daily 30 --keep-monthly 12 --prune; then
            cloud_ok=true
        else
            WARNINGS+=("restic forget/prune başarısız")
            warn "Buluttaki eski yedekler temizlenemedi."
        fi
    else
        WARNINGS+=("bulut yedeği gönderilemedi")
        warn "Bulut yedeği gönderilemedi (internet bağlantısı olmayabilir); yerel yedekler korundu."
    fi
fi

# 7. Eski yedekleri sil
log "Eski yedekler temizleniyor (sunucu: $KEEP_LOCAL_DAYS gün, USB: $KEEP_USB_DAYS gün)..."
find "$BACKUP_DIR" -maxdepth 1 -type f \( -name 'db-*.dump' -o -name 'files-*.tar.gz' -o -name '*.part' \) \
    -mtime +"$KEEP_LOCAL_DAYS" -delete
if mountpoint -q "$USB_MOUNT" && [[ -d "$USB_MOUNT/uretim" ]]; then
    find "$USB_MOUNT/uretim" -maxdepth 1 -type f \( -name 'db-*.dump' -o -name 'files-*.tar.gz' \) \
        -mtime +"$KEEP_USB_DAYS" -delete
fi

# 8. Sonuç
summary="Yedek $stamp: $(basename "$dump_file"), $(basename "$files_file")"
if ((${#WARNINGS[@]} > 0)); then
    summary+=" | Uyarılar: $(printf '%s; ' "${WARNINGS[@]}")"
fi

if [[ -n "${RESTIC_REPOSITORY:-}" && "$cloud_ok" != true ]]; then
    ping_healthcheck /fail "$summary"
    log "Yerel yedek tamam, bulut yedeği eksik. $summary"
else
    ping_healthcheck "" "$summary"
    log "Yedekleme tamamlandı. $summary"
fi
