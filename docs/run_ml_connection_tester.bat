@echo off
cd /d "%~dp0"
echo Iniciando testador de conexao Mercado Livre...
python ml_connection_tester.py
if errorlevel 1 (
    echo.
    echo Erro ao iniciar. Verifique se Python 3 esta instalado: python --version
    pause
)
