@echo off
chcp 65001 >nul
setlocal EnableDelayedExpansion
cd /d "%~dp0"

echo PHP kontrol / ilk kurulum...
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0kur-php.ps1"
if errorlevel 1 (
  echo [HATA] Otomatik PHP kurulumu basarisiz. Internet ve PowerShell gerekli.
  pause
  exit /b 1
)

set "PHP_CMD="
if exist "%~dp0php-yolu.txt" (
  for /f "usebackq tokens=* eol=# delims=" %%a in ("%~dp0php-yolu.txt") do (
    if not defined PHP_CMD (
      set "PHP_CMD=%%a"
    )
  )
)

if defined PHP_CMD (
  if exist "!PHP_CMD!" goto :php_ok
)

set "PHP_CMD=php"
where php >nul 2>&1 && goto :php_ok

set "PHP_CMD=%~dp0php\php.exe"
if exist "%PHP_CMD%" goto :php_ok

if exist "%LocalAppData%\Programs\PHP\php.exe" (
  set "PHP_CMD=%LocalAppData%\Programs\PHP\php.exe"
  goto :php_ok
)
if exist "C:\php\php.exe" (
  set "PHP_CMD=C:\php\php.exe"
  goto :php_ok
)
if exist "%ProgramFiles%\PHP\php.exe" (
  set "PHP_CMD=%ProgramFiles%\PHP\php.exe"
  goto :php_ok
)
if exist "%ProgramFiles%\php\php.exe" (
  set "PHP_CMD=%ProgramFiles%\php\php.exe"
  goto :php_ok
)

echo [HATA] PHP hala bulunamadi. "kur-php.ps1" calisti ama php\php.exe yok; php-yolu.txt veya PATH ile yol verin.
pause
exit /b 1

:php_ok

set "PORT=8790"
set "URL=http://127.0.0.1:%PORT%/"

echo Sunucu baslatiliyor: %URL%
echo Kapatmak icin acilan "PrintOnNow Blog" penceresini kapatin veya Ctrl+C.
echo.

rem /D ile calisma klasoru (bosluklu yol sorunsuz); php yolunda bosluk varsa tirnak
start "PrintOnNow Blog" /D "%~dp0" cmd /k ""%PHP_CMD%" -S 127.0.0.1:%PORT% index.php"

timeout /t 2 /nobreak >nul
start "" "%URL%"

echo Tarayici acildi. Giris: config.php icindeki admin kullanici / sifre.
echo Kaynak ve hedef kategorileri config.php -^> defaults bolumunden ayarlayabilirsiniz.
pause
