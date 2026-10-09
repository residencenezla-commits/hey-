#!/bin/sh
# Construit le dossier comptable : bilan, TCR (SCF), balances, grand livre, G50
# dans exports/DOSSIER_COMPTABLE_*.xlsx et exports/ETATS_FINANCIERS_*.html
# Arrêté à une date : ./dossier_comptable.sh --au=2026-09-30
cd "$(dirname "$0")" || exit 1
docker compose exec -T web php /var/www/scripts/outils/dossier_comptable.php "$@"
