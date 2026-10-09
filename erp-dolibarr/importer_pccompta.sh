#!/bin/sh
# Recharge un export de PC Compta : ./importer_pccompta.sh /chemin/DLG_COMPTA....xlsx
cd "$(dirname "$0")" || exit 1
[ -f "$1" ] || { echo "Usage : $0 fichier_pccompta.xls|.xlsx"; exit 1; }
cp "$1" exports/ && docker compose exec -T web php /var/www/scripts/outils/importer_pccompta.php "$(basename "$1")"
