@echo off
setlocal

REM Script para criar banco e tabelas automaticamente no XAMPP

set "PROJECT_DIR=%~dp0"
set "MYSQL_BIN=C:\xampp\mysql\bin\mysql.exe"
set "DB_NAME=portal_wct"
set "DB_USER=root"
set "DB_PASS="
set "SQL_FILE=%PROJECT_DIR%database.sql"

echo ===============================================
echo   Setup de Banco de Dados - Portal WCT
echo ===============================================
echo.

if not exist "%MYSQL_BIN%" (
    echo [ERRO] Nao foi encontrado: "%MYSQL_BIN%"
    echo Ajuste o caminho no arquivo setup_database.bat se necessario.
    pause
    exit /b 1
)

if not exist "%SQL_FILE%" (
    echo [ERRO] Arquivo SQL nao encontrado: "%SQL_FILE%"
    pause
    exit /b 1
)

set /p "DB_PASS=Senha do MySQL root (pressione Enter se vazia): "

echo.
echo [1/2] Importando "%SQL_FILE%" ...

if "%DB_PASS%"=="" (
    "%MYSQL_BIN%" -u "%DB_USER%" < "%SQL_FILE%"
) else (
    "%MYSQL_BIN%" -u "%DB_USER%" -p%DB_PASS% < "%SQL_FILE%"
)

if errorlevel 1 (
    echo [ERRO] Falha ao importar o banco de dados.
    echo Verifique se o MySQL do XAMPP esta iniciado e se a senha esta correta.
    pause
    exit /b 1
)

echo [2/2] Banco "%DB_NAME%" criado/importado com sucesso.
echo.
echo Pronto! Agora acesse:
echo http://localhost/portal_wct/index.php
echo.
pause
exit /b 0
