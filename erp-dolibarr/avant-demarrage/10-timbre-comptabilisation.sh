#!/bin/bash
# Exécuté à chaque démarrage du conteneur (dossier before-starting.d).
# Dolibarr cherche le compte du timbre fiscal dans le dictionnaire par son montant exact,
# ce qui échoue pour un timbre calculé (1 %, 1,5 %, 2 %). On retombe alors sur le compte
# par défaut ACCOUNTING_REVENUESTAMP_SOLD_ACCOUNT (44720), comme prévu dans le code d'origine.
f=/var/www/html/accountancy/journal/sellsjournal.php
if grep -q "\$compta_revenuestamp = 'NotDefined';" "$f"; then
  sed -i "s/\$compta_revenuestamp = 'NotDefined';/\$compta_revenuestamp = getDolGlobalString('ACCOUNTING_REVENUESTAMP_SOLD_ACCOUNT', 'NotDefined');/" "$f"
  echo "Timbre DZ : compte du timbre (44720) pris en compte à la comptabilisation des ventes."
fi
