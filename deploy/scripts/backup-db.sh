#!/usr/bin/env bash
# ==============================================================================
# SPMS Production Automated Database Backup & Rotation
# Usage: sudo bash /var/www/spms/deploy/scripts/backup-db.sh
# Cron: 0 2 * * * /bin/bash /var/www/spms/deploy/scripts/backup-db.sh >> /var/log/spms/backup.log 2>&1
# ==============================================================================

set -euo pipefail

BACKUP_DIR="/var/backups/spms"
ENV_FILE="/var/www/spms/.env"
TIMESTAMP=$(date '+%Y%m%d_%H%M%S')
BACKUP_FILE="${BACKUP_DIR}/spms_real_${TIMESTAMP}.sql.gz"
RETENTION_DAYS=14

mkdir -p "${BACKUP_DIR}"
chmod 700 "${BACKUP_DIR}"

if [ ! -f "${ENV_FILE}" ]; then
    echo "[$(date)] ERROR: .env file not found at ${ENV_FILE}" >&2
    exit 1
fi

# Extract DB credentials safely from .env
DB_NAME=$(grep -E '^DB_DATABASE=' "${ENV_FILE}" | cut -d '=' -f2- | tr -d '"' | tr -d "'")
DB_USER=$(grep -E '^DB_USERNAME=' "${ENV_FILE}" | cut -d '=' -f2- | tr -d '"' | tr -d "'")
DB_PASS=$(grep -E '^DB_PASSWORD=' "${ENV_FILE}" | cut -d '=' -f2- | tr -d '"' | tr -d "'")
DB_HOST=$(grep -E '^DB_HOST=' "${ENV_FILE}" | cut -d '=' -f2- | tr -d '"' | tr -d "'")

if [ -z "${DB_NAME}" ] || [ -z "${DB_USER}" ]; then
    echo "[$(date)] ERROR: Unable to read DB_DATABASE or DB_USERNAME from .env" >&2
    exit 1
fi

echo "[$(date)] Starting backup of ${DB_NAME} to ${BACKUP_FILE}..."

export MYSQL_PWD="${DB_PASS}"
mysqldump \
    --host="${DB_HOST:-127.0.0.1}" \
    --user="${DB_USER}" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    "${DB_NAME}" | gzip -9 > "${BACKUP_FILE}"
unset MYSQL_PWD

chmod 600 "${BACKUP_FILE}"
BACKUP_SIZE=$(du -h "${BACKUP_FILE}" | cut -f1)

echo "[$(date)] Backup completed successfully. Size: ${BACKUP_SIZE}"

# Rotate backups older than retention window
echo "[$(date)] Purging backups older than ${RETENTION_DAYS} days..."
find "${BACKUP_DIR}" -name "spms_real_*.sql.gz" -mtime +"${RETENTION_DAYS}" -delete
echo "[$(date)] Rotation complete. Remaining backups:"
ls -lh "${BACKUP_DIR}"
