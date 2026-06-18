#!/bin/bash
# =============================================
# Chama Frete - Backup Automático do Banco
# =============================================
# Uso: ./scripts/backup.sh
# Agendar (cron): 0 3 * * * /var/www/chama-frete/api/scripts/backup.sh
# =============================================

set -euo pipefail

# Configurações
BACKUP_DIR="/var/backups/chama-frete"
DB_NAME="${DB_NAME:-chama_frete}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_HOST="${DB_HOST:-localhost}"
RETENTION_DAYS=30
DATE=$(date +%Y-%m-%d_%H-%M-%S)
BACKUP_FILE="${BACKUP_DIR}/${DB_NAME}_${DATE}.sql.gz"
LATEST_LINK="${BACKUP_DIR}/latest.sql.gz"
LOG_FILE="${BACKUP_DIR}/backup.log"

# Carregar .env se existir
if [ -f "$(dirname "$0")/../.env" ]; then
    set -a
    source "$(dirname "$0")/../.env"
    set +a
fi

# Criar diretório
mkdir -p "$BACKUP_DIR"

# Início
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Iniciando backup de $DB_NAME..." >> "$LOG_FILE"

# Executar mysqldump com segurança
MYSQL_OPTS="--host=$DB_HOST --user=$DB_USER --routines --events --triggers --single-transaction --quick --lock-tables=false"
if [ -n "$DB_PASS" ]; then
    MYSQL_OPTS="$MYSQL_OPTS --password=$DB_PASS"
fi

if mysqldump $MYSQL_OPTS "$DB_NAME" | gzip > "$BACKUP_FILE"; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Backup criado: $BACKUP_FILE ($(du -h "$BACKUP_FILE" | cut -f1))" >> "$LOG_FILE"

    # Atualizar link "latest"
    ln -sf "$BACKUP_FILE" "$LATEST_LINK"

    # Remover backups antigos
    find "$BACKUP_DIR" -name "${DB_NAME}_*.sql.gz" -mtime +$RETENTION_DAYS -delete
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Backup antigo (>${RETENTION_DAYS}d) removido" >> "$LOG_FILE"
else
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERRO: Falha no backup!" >> "$LOG_FILE"
    exit 1
fi

# Verificar integridade
if zcat "$BACKUP_FILE" | head -n 5 > /dev/null 2>&1; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Backup verificado com sucesso" >> "$LOG_FILE"
else
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERRO: Backup corrompido!" >> "$LOG_FILE"
    exit 1
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Backup concluído com sucesso" >> "$LOG_FILE"
