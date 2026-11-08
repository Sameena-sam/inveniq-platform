@echo off
REM ============================================================
REM  InvenIQ - one-click ML setup
REM  Double-click this file (or run it from a terminal).
REM  Prerequisites:
REM    1. Python 3.9+ installed
REM    2. XAMPP running (Apache + MySQL) with the 'inveniq' DB imported
REM ============================================================
cd /d "%~dp0"
echo.
echo === InvenIQ ML setup ===
echo Working folder: %cd%
echo.

REM -- pick python or py launcher --
where python >nul 2>nul && (set PY=python) || (set PY=py)
echo Using Python command: %PY%
%PY% --version || (echo Python not found. Install Python 3.9+ and re-run. & pause & exit /b 1)
echo.

echo [1/4] Installing Python packages...
%PY% -m pip install -r requirements.txt
if errorlevel 1 (echo pip install failed. & pause & exit /b 1)
echo.

echo [2/4] Training the model on your real dataset (creates model.pkl)...
%PY% train_model.py
if errorlevel 1 (echo Training failed. & pause & exit /b 1)
echo.

echo [3/4] Loading your dataset into MySQL (replaces demo data)...
%PY% load_data.py
if errorlevel 1 (echo Data load failed. Is XAMPP MySQL running and the 'inveniq' DB imported? & pause & exit /b 1)
echo.

echo [4/4] Starting the model service in a new window...
start "InvenIQ ML Service" %PY% predict_service.py
timeout /t 4 >nul

echo.
echo === Done! Opening the app... ===
start "" http://localhost/inveniq/pages/dashboard.php
echo.
echo Keep the "InvenIQ ML Service" window open while you use the app.
echo Go to: AI ^& Analytics -^> Demand Forecast (ML)  to confirm the model is active.
pause
