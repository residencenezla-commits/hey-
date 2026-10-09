#!/bin/sh
# Exporte vers PC Compta les écritures saisies dans l'ERP (options : --du=AAAA-MM-JJ --au=… --journal=073 --essai)
cd "$(dirname "$0")" || exit 1
docker compose exec -T web php /var/www/scripts/outils/export_pccompta.php "$@"
