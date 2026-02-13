@echo off
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0wbgl_server.ps1" -Action restart -Port 8181 -OpenBrowser
