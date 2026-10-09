@echo off
REM Arrête l'ERP Dolibarr (les données sont conservées dans le dossier donnees).
cd /d "%~dp0"
docker compose stop
pause
