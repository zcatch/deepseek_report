@echo off
setlocal
cd /d "%~dp0"

echo ==============================================
echo  DeepSeek Usage Dashboard - Dev Mode (HMR)
echo    Backend : http://127.0.0.1:8000
echo    Frontend: http://localhost:5173
echo  Close each window to stop its server.
echo ==============================================
echo.

if not exist "frontend\node_modules" (
    echo [ERROR] Frontend deps not installed. Run first:
    echo   cd frontend
    echo   npm install --registry=https://registry.npmmirror.com
    pause
    exit /b 1
)

rem Backend: php -S. Dorm network needs Git CA bundle for SSL.
if exist "D:\soft\Git\mingw64\ssl\certs\ca-bundle.crt" (
    start "DS-Backend" cmd /k "php -d curl.cainfo=D:/soft/Git/mingw64/ssl/certs/ca-bundle.crt -S 127.0.0.1:8000 -t site"
) else (
    start "DS-Backend" cmd /k "php -S 127.0.0.1:8000 -t site"
)

rem Frontend: Vite dev (HMR)
start "DS-Frontend" cmd /k "cd frontend && npm run dev"

echo Both servers launched. Check the two new windows.
