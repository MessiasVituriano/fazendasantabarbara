#!/bin/sh
#
# Backup diario do banco (pg_dump) e dos arquivos (fotos e anexos), guardados no volume "backups".
# Roda no container "backup" (docker-compose.prod.yml). Mantem os ultimos BACKUP_DIAS dias (padrao 14).
#
#   Backup agora:   docker compose -f docker-compose.prod.yml exec backup sh /backup.sh agora
#   Listar:         docker compose -f docker-compose.prod.yml exec backup ls -lh /backups
#   Restaurar:      ver README (secao Backups)
#
# Atencao: o volume fica no mesmo servidor. Para sobreviver a perda do droplet, copie /backups para fora
# (ex.: Spaces/S3) ou ative os backups do proprio droplet.
set -eu

DIR=/backups
DIAS="${BACKUP_DIAS:-14}"
HORA="${BACKUP_HORA:-03}"

fazer_backup() {
    ts="$(date +%Y%m%d-%H%M)"
    echo "==> Backup $ts"
    PGPASSWORD="$DB_PASSWORD" pg_dump -h db -U "$DB_USERNAME" -d "$DB_DATABASE" -Fc -f "$DIR/banco-$ts.dump.parcial"
    mv "$DIR/banco-$ts.dump.parcial" "$DIR/banco-$ts.dump"
    tar -czf "$DIR/arquivos-$ts.tar.gz.parcial" -C /storage app
    mv "$DIR/arquivos-$ts.tar.gz.parcial" "$DIR/arquivos-$ts.tar.gz"
    find "$DIR" -name '*.parcial' -delete
    find "$DIR" \( -name 'banco-*.dump' -o -name 'arquivos-*.tar.gz' \) -mtime +"$DIAS" -delete
    echo "==> Backup $ts concluido: $(ls -1 "$DIR"/banco-*.dump | wc -l) dia(s) guardado(s)"
}

if [ "${1:-}" = "agora" ]; then
    fazer_backup
    exit 0
fi

mkdir -p "$DIR"
echo "==> Backup diario as ${HORA}h ($TZ), guardando $DIAS dias"
while true; do
    hoje="$(date +%Y%m%d)"
    if [ "$(date +%H)" = "$HORA" ] && [ ! -f "$DIR/.feito-$hoje" ]; then
        fazer_backup && touch "$DIR/.feito-$hoje"
        find "$DIR" -name '.feito-*' -mtime +3 -delete
    fi
    sleep 600
done
