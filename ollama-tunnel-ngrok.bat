@echo off
chcp 65001 >nul
title Ollama - ngrok (trycloudflare 403 ise bunu kullan)
echo.
echo  === ngrok ile Ollama (port 11434) ===
echo.
echo  "authentication failed / authtoken" gorurseniz ONCE:
echo    ngrok-authtoken-kur.bat   (cift tikla, token yapistir)
echo.
echo  Ollama bu PCde acik olmali. Bu pencereyi KAPATMA.
echo  Asagidaki "Forwarding" satirindaki https://.... adresini
echo  WordPress - PrintOnNow Ollama - Ollama base URL yap.
echo.
where ngrok >nul 2>&1
if errorlevel 1 (
  echo [HATA] ngrok bulunamadi. Yukle:
  echo   winget install Ngrok.Ngrok
  echo Sonra bu dosyayi tekrar calistir.
  pause
  exit /b 1
)
ngrok http 11434
if errorlevel 1 (
  echo.
  echo Hata aldiysaniz: ngrok-authtoken-kur.bat ile token kurun.
  pause
)
pause
