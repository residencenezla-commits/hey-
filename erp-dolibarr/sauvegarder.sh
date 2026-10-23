#!/bin/sh
# Sauvegarde la base de l'ERP dans le dossier sauvegardes/ (à copier ensuite hors du serveur).
cd "$(dirname "$0")" || exit 1
mkdir -p sauvegardes
fichier="sauvegardes/dolibarr-$(date +%Y-%m-%d_%H%M).sql"
if docker compose exec -T mariadb sh -c 'mariadb-dump -u dolidbuser -p"$MARIADB_PASSWORD" dolidb' > "$fichier" && [ -s "$fichier" ]; then
  echo "Sauvegarde enregistrée : $fichier"
else
  rm -f "$fichier"
  echo "ÉCHEC de la sauvegarde : l'ERP est-il démarré ?" >&2
  exit 1
fi
