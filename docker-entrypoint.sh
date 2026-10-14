#!/bin/sh
set -e
# Volume gehört nach dem Mounten oft root – Apache (www-data) muss die SQLite-Datei schreiben dürfen
mkdir -p "$(dirname "${DB_PATH:-/data/reservierungen.sqlite}")"
chown -R www-data:www-data "$(dirname "${DB_PATH:-/data/reservierungen.sqlite}")"
exec "$@"
