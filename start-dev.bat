@echo off
setlocal
cd /d "%~dp0"

echo ==============================================
echo  DeepSeek Usage Dashboard - Dev Mode (HMR)
echo    Backend : http://127.0.0.1:8000
echo    Frontend: http://localhost:3210
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

rem Kill stale processes on our ports so a second launch won't hit "Address already in use"
call :free_port 8000
call :free_port 3210

rem Backend: php -S. Dorm network needs Git CA bundle for SSL.
if exist "D:\soft\Git\mingw64\ssl\certs\ca-bundle.crt" (
    start "DS-Backend" cmd /k "php -d curl.cainfo=D:/soft/Git/mingw64/ssl/certs/ca-bundle.crt -S 127.0.0.1:8000 -t site"
) else (
    start "DS-Backend" cmd /k "php -S 127.0.0.1:8000 -t site"
)

rem Frontend: Vite dev (HMR)
start "DS-Frontend" cmd /k "cd frontend && npm run dev"

echo Both servers launched. Check the two new windows.
exit /b 0

:free_port
for /f "tokens=5" %%p in ('netstat -ano ^| findstr ":%~1" ^| findstr "LISTENING"') do (
    echo [WARN] Port %~1 held by stale PID %%p - killing it...
    taskkill /f /pid %%p >nul 2>&1
)
exit /b 0
