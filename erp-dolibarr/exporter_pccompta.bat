@echo off
REM Exporte vers PC Compta les écritures saisies dans l'ERP et pas encore exportées.
REM Fichiers produits dans le dossier exports : .xls (à importer dans PC Compta), .xlsx et .csv.
cd /d "%~dp0"
docker compose exec -T web php /var/www/scripts/outils/export_pccompta.php %*
start "" "%~dp0exports"
pause
