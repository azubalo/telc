@echo off
chcp 65001 >nul
title Ollama - localtunnel (Node.js gerekir)
echo.
echo  === localtunnel (ucretsiz, Node.js kurulu olmali) ===
echo  https://nodejs.org adresinden LTS kur.
echo  Ilk sefer: npx biraz indirir, biraz bekleyin.
echo  Cikan https://.... adresini WordPress Ollama base URL yap.
echo  Ollama acik kalsin. Bu pencereyi KAPATMA.
echo.
where node >nul 2>&1
if errorlevel 1 (
  echo [HATA] node bulunamadi. Node.js LTS kurun.
  pause
  exit /b 1
)
npx --yes localtunnel --port 11434
pause
