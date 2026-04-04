@echo off
chcp 65001 >nul
title ngrok - authtoken (bir kez)
echo.
echo  === ngrok ucretsiz hesap + token (bir kez yapilir) ===
echo.
echo  1) Tarayicide ac: https://dashboard.ngrok.com/signup
echo     (veya giris yap: https://dashboard.ngrok.com/login)
echo  2) Sonra: https://dashboard.ngrok.com/get-started/your-authtoken
echo     "Authtoken" metnini KOPYALA (uzun harf/rakam).
echo.
start "" "https://dashboard.ngrok.com/get-started/your-authtoken"
echo.
set /p TOKEN="Token'i asagiya yapistirip Enter'a basin: "
if "%TOKEN%"=="" (
  echo Bos biraktiniz. Tekrar calistirin.
  pause
  exit /b 1
)
where ngrok >nul 2>&1
if errorlevel 1 (
  echo ngrok yok. Yukle: winget install Ngrok.Ngrok
  pause
  exit /b 1
)
ngrok config add-authtoken "%TOKEN%"
if errorlevel 1 (
  echo.
  echo Hata. Token dogru mu kopyalandi kontrol edin.
  pause
  exit /b 1
)
echo.
echo Tamam. Simdi ollama-tunnel-ngrok.bat dosyasini calistirabilirsiniz.
pause
