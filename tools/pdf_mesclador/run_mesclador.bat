@echo off
cd /d "%~dp0"
python pdf_mesclador_gui.py
if errorlevel 1 pause
