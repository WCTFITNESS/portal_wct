@echo off
setlocal EnableDelayedExpansion

REM Atualiza o banco MySQL do Portal WCT (portal_wct):
REM   1) database.sql — cria o banco (se nao existir) e tabelas (IF NOT EXISTS)
REM   2) database_mercadopago_settings.sql — tabela mercadopago_settings, se o arquivo existir
REM Requer MySQL do XAMPP em execucao.

set "PROJECT_DIR=%~dp0"
set "MYSQL_BIN=C:\xampp\mysql\bin\mysql.exe"
set "DB_USER=root"
set "DB_PASS="
set "SQL_PRINCIPAL=%PROJECT_DIR%database.sql"
set "SQL_MP=%PROJECT_DIR%database_mercadopago_settings.sql"

echo ===============================================
echo   Atualizar banco - Portal WCT (portal_wct)
echo ===============================================
echo.

if not exist "%MYSQL_BIN%" (
    echo [ERRO] Nao foi encontrado: "%MYSQL_BIN%"
    echo Ajuste MYSQL_BIN neste arquivo se o XAMPP estiver em outro caminho.
    pause
    exit /b 1
)

if not exist "%SQL_PRINCIPAL%" (
    echo [ERRO] Arquivo nao encontrado: "%SQL_PRINCIPAL%"
    pause
    exit /b 1
)

set /p "DB_PASS=Senha do MySQL root (Enter se vazia): "

echo.
echo [1/2] Importando database.sql ...
if "!DB_PASS!"=="" (
    "%MYSQL_BIN%" -u "%DB_USER%" < "%SQL_PRINCIPAL%"
) else (
    "%MYSQL_BIN%" -u "%DB_USER%" -p!DB_PASS! < "%SQL_PRINCIPAL%"
)
if errorlevel 1 (
    echo [ERRO] Falha ao importar database.sql
    echo Confirme se o MySQL esta rodando e a senha esta correta.
    pause
    exit /b 1
)
echo       OK.

if exist "%SQL_MP%" (
    echo [2/2] Importando database_mercadopago_settings.sql ...
    if "!DB_PASS!"=="" (
        "%MYSQL_BIN%" -u "%DB_USER%" < "%SQL_MP%"
    ) else (
        "%MYSQL_BIN%" -u "%DB_USER%" -p!DB_PASS! < "%SQL_MP%"
    )
    if errorlevel 1 (
        echo [ERRO] Falha ao importar database_mercadopago_settings.sql
        pause
        exit /b 1
    )
    echo       OK.
) else (
    echo [2/2] database_mercadopago_settings.sql nao encontrado — ignorado.
)

echo.
echo Banco portal_wct atualizado.
echo Acesse: http://localhost/portal_wct/index.php
echo.
pause
exit /b 0
