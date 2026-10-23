@echo off
REM Construit le dossier comptable de l'exercice : bilan actif et passif, TCR (SCF),
REM balance générale, balance des tiers, grand livre et préparation G50.
REM Résultat : exports\DOSSIER_COMPTABLE_*.xlsx et exports\ETATS_FINANCIERS_*.html (ouvert ensuite).
REM Arrêté à une date : dossier_comptable.bat --au=2026-09-30
cd /d "%~dp0"
docker compose exec -T web php /var/www/scripts/outils/dossier_comptable.php %*
for /f "delims=" %%F in ('dir /b /o-d "exports\ETATS_FINANCIERS_*.html"') do (start "" "exports\%%F" & goto :fin)
:fin
pause
