@echo off
setlocal
cd /d "%~dp0"

where git >nul 2>nul
if errorlevel 1 (
    echo [ERRO] Comando "git" nao encontrado no PATH.
    echo Instale o Git: https://git-scm.com/download/win
    echo Reinicie o terminal e execute este arquivo de novo.
    pause
    exit /b 1
)

if not exist ".git" (
    echo Inicializando repositorio Git...
    git init
)

git remote get-url origin >nul 2>nul
if errorlevel 1 (
    git remote add origin https://github.com/WCTFITNESS/portal_wct.git
    echo Remote "origin" adicionado: https://github.com/WCTFITNESS/portal_wct.git
) else (
    git remote set-url origin https://github.com/WCTFITNESS/portal_wct.git
    echo Remote "origin" apontando para: https://github.com/WCTFITNESS/portal_wct.git
)

echo.
echo --- Proximos comandos no mesmo diretorio ---
echo git add .
echo git commit -m "Initial commit"
echo git branch -M main
echo git push -u origin main
echo.
echo Na primeira vez, configure seu nome/e-mail se o Git pedir:
echo   git config --global user.name "Seu Nome"
echo   git config --global user.email "seu@email.com"
echo.
echo No push, use um Personal Access Token como senha se usar HTTPS.
echo Repositorio remoto: https://github.com/WCTFITNESS/portal_wct
echo.
pause
