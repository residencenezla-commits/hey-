@echo off
REM Sauvegarde la base de l'ERP dans le dossier sauvegardes (copiez-la ensuite sur une clé ou un disque externe).
cd /d "%~dp0"
if not exist sauvegardes mkdir sauvegardes
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd_HHmm"') do set D=%%i
docker compose exec -T mariadb sh -c "mariadb-dump -u dolidbuser -p\"$MARIADB_PASSWORD\" dolidb" > "sauvegardes\dolibarr-%D%.sql"
for %%F in ("sauvegardes\dolibarr-%D%.sql") do if %%~zF LSS 1000 (
  del "sauvegardes\dolibarr-%D%.sql"
  echo ECHEC de la sauvegarde : l'ERP est-il demarre ? Lancez demarrer.bat puis recommencez.
  pause
  exit /b 1
)
echo Sauvegarde enregistree : sauvegardes\dolibarr-%D%.sql
pause
