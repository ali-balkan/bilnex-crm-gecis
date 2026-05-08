$ErrorActionPreference = "Stop"

$php = Get-Command php -ErrorAction SilentlyContinue
if ($php) {
    $phpExe = $php.Source
} else {
    $phpExe = Join-Path $env:LOCALAPPDATA "Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
}

if (!(Test-Path $phpExe)) {
    throw "PHP bulunamadi. Once winget install --id PHP.PHP.8.4 --exact komutunu calistirin."
}

$phpDir = Split-Path $phpExe -Parent
$env:CRM_BASE_URL = ""

& $phpExe `
    -d "extension_dir=$phpDir\ext" `
    -d "extension=pdo_sqlite" `
    -d "extension=sqlite3" `
    -d "extension=gd" `
    -d "extension=zip" `
    -S 127.0.0.1:8001 `
    -t $PSScriptRoot
