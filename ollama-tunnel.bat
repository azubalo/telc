@echo off
chcp 65001 >nul
title Ollama - Cloudflare quick tunnel
echo.
echo  Ollama must be running on this PC (default port 11434).
echo  Leave this window OPEN while WordPress generates posts.
echo  Copy the https://....trycloudflare.com URL into WordPress:
echo  Settings - PrintOnNow Ollama - Ollama base URL
echo  (No trailing slash. URL changes each time you restart this tunnel.)
echo.
echo  If browser shows HTTP 403 Access denied: trycloudflare blocks some networks.
echo  Use instead: ollama-tunnel-ngrok.bat  (free ngrok account)
echo       or:     ollama-tunnel-localtunnel.bat  (needs Node.js)
echo.
cloudflared tunnel --url http://127.0.0.1:11434
if errorlevel 1 (
  echo.
  echo If "cloudflared" was not found, close and reopen this folder in Explorer,
  echo or install: winget install Cloudflare.cloudflared
  pause
)
