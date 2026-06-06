@echo off
setlocal enabledelayedexpansion

echo =============================================
echo  Disciple.Tools - CRM Sync Release Builder
echo =============================================
echo.

:: Resolve paths relative to this script (located in the plugin root)
set "SCRIPT_DIR=%~dp0"
set "PLUGIN_DIR=%SCRIPT_DIR:~0,-1%"
set "STAGE_DIR=%SCRIPT_DIR%..\disciple-tools-crm-sync-stage"

:: Read version from plugin header to build versioned zip name
set "PS_PLUGIN_PHP=%PLUGIN_DIR%\disciple-tools-crm-sync.php"
for /f "usebackq delims=" %%V in (`powershell -NoProfile -Command "$line = (Get-Content $Env:PS_PLUGIN_PHP | Where-Object { $_ -match 'Version:' } | Select-Object -First 1); if ($line -match 'Version:\s*(.+)') { $Matches[1].Trim() }"`) do set "PLUGIN_VERSION=%%V"
set "ZIP_OUTPUT=%SCRIPT_DIR%..\disciple-tools-crm-sync-%PLUGIN_VERSION%.zip"

:: ----------------------------------------------------------
:: Step 0: Read version from crm2dt.php and sync version-control.json
:: ----------------------------------------------------------
echo [0/4] Syncing version-control.json from plugin header...
set "PS_PLUGIN=%PLUGIN_DIR%"
powershell -NoProfile -Command "$plugin = [IO.Path]::GetFullPath($Env:PS_PLUGIN); $php = Join-Path $plugin 'disciple-tools-crm-sync.php'; $json = Join-Path $plugin 'version-control.json'; $line = (Get-Content $php | Where-Object { $_ -match 'Version:' } | Select-Object -First 1); if ($line -match 'Version:\s*(.+)') { $v = $Matches[1].Trim(); $j = Get-Content $json -Raw | ConvertFrom-Json; $j.version = $v; $j.download_url = ('https://github.com/' + $j.git_owner + '/' + $j.git_repo + '/releases/download/v' + $v + '/' + $j.git_repo + '-' + $v + '.zip'); $utf8NoBom = New-Object System.Text.UTF8Encoding $false; [IO.File]::WriteAllText($json, ($j | ConvertTo-Json -Depth 5), $utf8NoBom); Write-Host ('       Version: ' + $v) } else { Write-Error 'Could not parse Version from disciple-tools-crm-sync.php'; exit 1 }"
if %ERRORLEVEL% NEQ 0 (
    echo.
    echo ERROR: Failed to sync version-control.json. Aborting.
    pause
    exit /b 1
)
echo.

:: ----------------------------------------------------------
:: Step 1: Build
:: ----------------------------------------------------------
echo [1/4] Building JavaScript assets...
cd /d "%PLUGIN_DIR%"
call npm run build
if %ERRORLEVEL% NEQ 0 (
    echo.
    echo ERROR: npm run build failed. Aborting.
    pause
    exit /b 1
)
echo        Build succeeded.
echo.

:: ----------------------------------------------------------
:: Step 2: Stage release files
:: ----------------------------------------------------------
echo [2/4] Staging release files...

:: Clean any previous staging directory
if exist "%STAGE_DIR%" rmdir /s /q "%STAGE_DIR%"
mkdir "%STAGE_DIR%\disciple-tools-crm-sync"

:: Copy release folders (excludes src/, node_modules/, cypress/, test/, etc.)
for %%D in (admin connectors dist documentation import languages rest-api translation webhook) do (
    if exist "%PLUGIN_DIR%\%%D" (
        robocopy "%PLUGIN_DIR%\%%D" "%STAGE_DIR%\disciple-tools-crm-sync\%%D" /E /NFL /NDL /NJH /NJS /NP >nul
    )
)

:: Copy release files
for %%F in (config.php disciple-tools-crm-sync.php index.php LICENSE README.md uninstall.php version-control.json) do (
    if exist "%PLUGIN_DIR%\%%F" (
        copy /y "%PLUGIN_DIR%\%%F" "%STAGE_DIR%\disciple-tools-crm-sync\%%F" >nul
    )
)

echo        Files staged.
echo.

:: ----------------------------------------------------------
:: Validate staged files before zipping
::
:: Every file listed here maps directly to a require_once call in the main
:: plugin file. If any are missing the ZIP would produce a fatal on load,
:: so we catch that here rather than shipping a broken release.
:: ----------------------------------------------------------
echo [2b/4] Validating staged files...
set "STAGE_ROOT=%STAGE_DIR%\disciple-tools-crm-sync"
set "VALIDATION_FAILED=0"

for %%F in (
    disciple-tools-crm-sync.php
    config.php
    index.php
    uninstall.php
    import\class-logger.php
    connectors\abstract-connector.php
    connectors\connector-registry.php
    connectors\respond-io\respond-io-api-client.php
    connectors\respond-io\respond-io-connector.php
    connectors\metricool\metricool-api-client.php
    connectors\metricool\metricool-connector.php
    translation\abstract-translation-provider.php
    translation\gemini\gemini-translation-provider.php
    translation\class-translation-logger.php
    translation\class-translation-rate-limiter.php
    translation\class-translation-service.php
    rest-api\rest-api.php
    webhook\webhook-listener.php
    admin\admin-menu-and-tabs.php
) do (
    if not exist "%STAGE_ROOT%\%%F" (
        echo        MISSING: %%F
        set "VALIDATION_FAILED=1"
    )
)

if "!VALIDATION_FAILED!"=="1" (
    echo.
    echo ERROR: One or more required files are missing from the staged directory.
    echo        Fix the staging step above and try again.
    rmdir /s /q "%STAGE_DIR%"
    pause
    exit /b 1
)
echo        All required files present.
echo.

:: ----------------------------------------------------------
:: Step 3: Zip and clean up
:: ----------------------------------------------------------
echo [3/4] Creating zip...

if exist "%ZIP_OUTPUT%" del "%ZIP_OUTPUT%"

:: Pass paths via environment variables to avoid quoting issues with spaces.
:: Use .NET ZipFile directly instead of Compress-Archive so every entry is
:: written with POSIX forward-slash separators (crm2dt/import/class-logger.php).
:: PowerShell's Compress-Archive stores Windows backslash separators in entry
:: names; on Linux, PHP's ZipArchive treats \ as a literal character rather
:: than a directory separator, so subdirectory files never land in the right
:: place (e.g. class-logger.php ends up as a file named "import\class-logger.php").
set "PS_SRC=%STAGE_DIR%\disciple-tools-crm-sync"
set "PS_DST=%ZIP_OUTPUT%"
powershell -NoProfile -Command "if (Test-Path $Env:PS_DST) { Remove-Item $Env:PS_DST -Force }; Add-Type -AssemblyName System.IO.Compression.FileSystem; $zip = [System.IO.Compression.ZipFile]::Open($Env:PS_DST, 'Create'); $src = (Resolve-Path $Env:PS_SRC).Path; $folderName = Split-Path $src -Leaf; Get-ChildItem -Path $src -Recurse -File | ForEach-Object { $rel = $_.FullName.Substring($src.Length + 1).Replace('\', '/'); $entry = $folderName + '/' + $rel; [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $entry) | Out-Null }; $zip.Dispose()"

if %ERRORLEVEL% NEQ 0 (
    echo.
    echo ERROR: Zip creation failed.
    rmdir /s /q "%STAGE_DIR%"
    pause
    exit /b 1
)

rmdir /s /q "%STAGE_DIR%"

echo        Done.
echo.
echo =============================================
echo  Release zip: %ZIP_OUTPUT%
echo =============================================
echo.
pause