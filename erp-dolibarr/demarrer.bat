@echo off
REM Démarre l'ERP Dolibarr de DPR AXXAM (nécessite Docker Desktop, démarré).
cd /d "%~dp0"
if not exist .env (
  copy .env.exemple .env >nul
  echo Premier demarrage : choisissez vos mots de passe dans le fichier .env qui va s'ouvrir,
  echo enregistrez-le, fermez le Bloc-notes, puis relancez demarrer.bat.
  notepad .env
  exit /b
)
docker compose up -d
if errorlevel 1 (
  echo.
  echo Docker ne repond pas. Lancez Docker Desktop, attendez qu'il soit pret, puis recommencez.
  pause
  exit /b
)
echo.
echo L'ERP demarre. Au tout premier lancement, comptez 2 a 3 minutes avant que la page reponde.
timeout /t 10 >nul
start http://localhost:8080
