<#
.SYNOPSIS
    Starts the local OPES360 site again after a reboot.
.DESCRIPTION
    Nothing here installs as a Windows service, so both processes stop when the
    machine does. This brings them back without redoing the setup.
#>
param([string] $Domain = 'opes360.test', [int] $PhpPort = 9001)

$ErrorActionPreference = 'Stop'
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root

if (-not (Test-Path (Join-Path $Root '.env'))) {
    Write-Host "No .env - run install.ps1 first." -ForegroundColor Red
    exit 1
}

if (-not (Test-Path (Join-Path $Root 'Caddyfile'))) {
    Write-Host "No Caddyfile - this install used Laragon, which starts with Windows." -ForegroundColor Yellow
    Write-Host "Check the Laragon tray icon; nothing here to start." -ForegroundColor Yellow
    Start-Process "https://$Domain"
    exit
}

$env:PHP_CLI_SERVER_WORKERS = '8'

if (-not (Get-NetTCPConnection -LocalPort $PhpPort -State Listen -ErrorAction SilentlyContinue)) {
    Start-Process php -ArgumentList @('-S', "127.0.0.1:$PhpPort", '-t', 'public', 'public/index.php') `
        -WorkingDirectory $Root -WindowStyle Hidden
    Write-Host "PHP started" -ForegroundColor Green
}

if (-not (Get-Process caddy -ErrorAction SilentlyContinue)) {
    Start-Process (Join-Path $PSScriptRoot 'bin\caddy.exe') `
        -ArgumentList @('run', '--config', (Join-Path $Root 'Caddyfile')) `
        -WorkingDirectory $Root -WindowStyle Hidden
    Write-Host "Caddy started" -ForegroundColor Green
}

foreach ($i in 1..30) {
    Start-Sleep -Seconds 2
    try {
        if ((Invoke-WebRequest "https://$Domain/up" -UseBasicParsing -TimeoutSec 5).StatusCode -eq 200) {
            Write-Host "`n  https://$Domain is up." -ForegroundColor Green
            Start-Process "https://$Domain"
            exit
        }
    } catch { }
}

Write-Host "Site did not answer. Check storage\logs\laravel.log" -ForegroundColor Yellow
