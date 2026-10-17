#!/usr/bin/env bash
# Veritabanını günceller: migration'lar tablo sahibiyle çalışır, ardından uygulama kullanıcısının
# (DB_USERNAME) yetkileri yenilenir. İlk kurulumda, deploy.sh ve restore.sh içinde kullanılır.
#   ./deploy/scripts/db-migrate.sh
set -euo pipefail

# shellcheck source=deploy/scripts/lib.sh
source "$(dirname "$0")/lib.sh"

require_env_file
start_db
# Kullanıcı önce oluşturulur ki migration'ların oluşturduğu tablolara varsayılan yetkiler uygulansın.
apply_app_role
run_migrations
apply_app_role
log "Veritabanı güncel."
