@echo off
REM Calcule le tableau de bord de gestion (chiffre d'affaires, trésorerie, TVA, clients, charges)
REM à partir du grand livre de l'ERP et l'ouvre dans le navigateur.
cd /d "%~dp0"
docker compose exec -T web php /var/www/scripts/outils/tableau_de_bord.php
for /f "delims=" %%F in ('dir /b /o-d "exports\TABLEAU_DE_BORD_*.html"') do (start "" "exports\%%F" & goto :fin)
:fin
pause
