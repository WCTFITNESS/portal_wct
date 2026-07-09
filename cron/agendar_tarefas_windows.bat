@echo off
REM Agenda tarefas do Portal WCT no Agendador de Tarefas do Windows.
REM Execute como administrador (clique direito ^> Executar como administrador).

set PHP=C:\xampp\php\php.exe
set PORTAL=C:\xampp\htdocs\ml-portal

if not exist "%PHP%" (
    echo ERRO: PHP nao encontrado em %PHP%
    exit /b 1
)

echo Criando/atualizando tarefas do Portal WCT...

schtasks /Create /TN "MLPortal-RefreshToken-5h" /SC HOURLY /MO 5 /TR "\"%PHP%\" \"%PORTAL%\cron\refresh_token.php\"" /F
if errorlevel 1 (
    echo Falha ao criar MLPortal-RefreshToken-5h
    exit /b 1
)
echo OK: MLPortal-RefreshToken-5h ^(a cada 5 horas^)

schtasks /Create /TN "MLPortal-RefreshLexosHub-1h" /SC HOURLY /MO 1 /TR "\"%PHP%\" \"%PORTAL%\cron\refresh_lexos_hub.php\"" /F
if errorlevel 1 (
    echo Falha ao criar MLPortal-RefreshLexosHub-1h
    exit /b 1
)
echo OK: MLPortal-RefreshLexosHub-1h ^(a cada 1 hora^)

echo.
echo Tarefas agendadas. Verifique em taskschd.msc
schtasks /Query /TN "MLPortal-RefreshToken-5h" /FO LIST | findstr /I "Nome Proxima Status"
schtasks /Query /TN "MLPortal-RefreshLexosHub-1h" /FO LIST | findstr /I "Nome Proxima Status"

pause
