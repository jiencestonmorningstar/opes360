<#
.SYNOPSIS
    Stops the local OPES360 site.
#>
$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent

Get-Process caddy -ErrorAction SilentlyContinue | Stop-Process -Force
Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" |
    Where-Object { $_.CommandLine -like '*-S 127.0.0.1:9001*' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force }

Write-Host "Stopped." -ForegroundColor Green
