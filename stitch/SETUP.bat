@echo off
echo ============================================
echo   Google Stitch SDK - Setup para Sistema NG
echo ============================================
echo.

:: Verificar Node.js
where node >nul 2>nul
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] Node.js no esta instalado.
    echo.
    echo Descargalo de: https://nodejs.org/
    echo Instala la version LTS ^(recomendada^)
    echo Luego ejecuta este script de nuevo.
    echo.
    pause
    exit /b 1
)

echo [OK] Node.js encontrado:
node --version
echo.

:: Instalar dependencias
echo Instalando Stitch SDK...
npm install
echo.

if %ERRORLEVEL% EQU 0 (
    echo ============================================
    echo   Setup completado exitosamente!
    echo ============================================
    echo.
    echo Comandos disponibles:
    echo   npm run generate  -- Generar pantalla UI
    echo   npm run variants  -- Generar variantes
    echo   npm run list      -- Listar proyectos
    echo.
    echo Ejemplo:
    echo   node generate-screen.js "Dashboard de inventario NAGSA"
    echo.
) else (
    echo [ERROR] Fallo la instalacion. Verifica tu conexion a internet.
)

pause
