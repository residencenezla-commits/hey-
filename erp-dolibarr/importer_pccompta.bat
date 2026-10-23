@echo off
REM Recharge dans l'ERP un nouvel export de PC Compta : faites glisser le fichier
REM DLG_COMPTA... (.xls ou .xlsx) sur ce fichier .bat.
cd /d "%~dp0"
if "%~1"=="" (
  echo Faites glisser le fichier exporte de PC Compta sur importer_pccompta.bat.
  pause
  exit /b
)
copy /y "%~1" "exports\%~nx1" >nul
docker compose exec -T web php /var/www/scripts/outils/importer_pccompta.php "%~nx1"
pause
