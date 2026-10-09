#!/bin/sh
# Calcule le tableau de bord de gestion dans exports/TABLEAU_DE_BORD_AAAAMMJJ.html
cd "$(dirname "$0")" || exit 1
docker compose exec -T web php /var/www/scripts/outils/tableau_de_bord.php
