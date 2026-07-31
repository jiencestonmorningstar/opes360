<#
.SYNOPSIS
    Makes the local OPES360 install reachable from other devices on the same
    WiFi - phones included - over trusted HTTPS.

.DESCRIPTION
    The first version of this script tried to get the LAN IP served on port
    443 - either by adding it to the repo's own Caddyfile, or (for a Laragon
    install) just pointing the phone at https://<lan-ip> and hoping. That
    second part was wrong: Laragon's Apache/Nginx picks which project to
    serve by matching the Host header against each vhost's ServerName - a
    request addressed to a bare IP matches none of them, so it falls through
    to whatever vhost happens to be first, which can easily be a *different*
    project on the same machine. That is the "shows a different platform"
    symptom.

    This version sidesteps vhost matching, and any fight over who owns port
    443, entirely: it runs its own tiny, independent pair of processes - a
    PHP built-in server plus a Caddy instance in front of it for TLS - bound
    to a dedicated port that nothing else on the machine is using. Works
    identically whether the desktop install uses Laragon or this repo's own
    Caddy setup, because it does not touch either of them.

    Caddy's `tls internal` mode is what makes the padlock work without a
    manually-bought certificate: it runs its own certificate authority and
    trusts it on this PC automatically. The phone has never seen that CA
    though, so the last step is copying its root certificate over once.

.EXAMPLE
    .\lan-access.ps1
#>

[CmdletBinding()]
param(
    [int] $LanPort = 8443,
    [int] $PhpPort = 9091
)

$ErrorActionPreference = 'Stop'

$identity  = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "Re-launching as Administrator - a new window will open and STAY OPEN." -ForegroundColor Yellow
    Write-Host "If Windows asks for permission, click Yes." -ForegroundColor Yellow
    # -NoExit: without it the elevated window runs the script and closes itself
    # immediately - including on error - before there is anything to read.
    Start-Process powershell -Verb RunAs -ArgumentList @('-NoExit', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"", '-LanPort', $LanPort, '-PhpPort', $PhpPort)
    exit
}

$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root

function Step($text) { Write-Host "`n==> $text" -ForegroundColor Cyan }
function Ok($text)   { Write-Host "    $text" -ForegroundColor Green }
function Warn($text) { Write-Host "    $text" -ForegroundColor Yellow }
function Die($text)  { Write-Host "`nFAILED: $text" -ForegroundColor Red; exit 1 }

Write-Host "OPES360 - LAN / phone access" -ForegroundColor White

if (-not (Test-Path (Join-Path $Root 'artisan'))) {
    Die "artisan not found in $Root - run this from inside the project (or run install.ps1 first)."
}

# ---- find the LAN IP ------------------------------------------------------

Step "Finding this machine's LAN IP"

$lanIp = Get-NetIPAddress -AddressFamily IPv4 |
    Where-Object {
        $_.IPAddress -notlike '169.254.*' -and $_.IPAddress -ne '127.0.0.1' -and
        ($_.InterfaceAlias -notmatch 'Loopback|vEthernet|WSL')
    } |
    Sort-Object -Property @{ Expression = { $_.InterfaceAlias -match 'Wi-?Fi' }; Descending = $true } |
    Select-Object -First 1 -ExpandProperty IPAddress

if (-not $lanIp) { Die "Could not find a LAN IP. Run 'ipconfig', find the IPv4 Address, and share that with me so the script can be fixed." }
Ok "$lanIp"

# ---- PHP -------------------------------------------------------------------

Step "Checking PHP"

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    $laragonPhp = Get-ChildItem -Path 'C:\laragon\bin\php' -Recurse -Filter php.exe -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending | Select-Object -First 1
    if ($laragonPhp) {
        $env:Path = "$($laragonPhp.DirectoryName);$env:Path"
        Ok "Using Laragon's PHP at $($laragonPhp.DirectoryName)"
        $php = Get-Command php -ErrorAction SilentlyContinue
    }
}
if (-not $php) { Die "PHP is not on PATH and not found under C:\laragon\bin\php." }
Ok "PHP found"

# ---- caddy ------------------------------------------------------------------

Step "Getting Caddy (web server + certificate authority)"

$caddyDir = Join-Path $Root 'scripts\windows\bin'
$caddyExe = Join-Path $caddyDir 'caddy.exe'

if (Test-Path $caddyExe) {
    Ok "Already downloaded"
} else {
    New-Item -ItemType Directory -Force -Path $caddyDir | Out-Null
    $zip = Join-Path $env:TEMP 'caddy.zip'
    $url = 'https://github.com/caddyserver/caddy/releases/download/v2.8.4/caddy_2.8.4_windows_amd64.zip'
    try {
        Invoke-WebRequest -Uri $url -OutFile $zip -UseBasicParsing
    } catch {
        Die "Could not download Caddy. Get caddy_windows_amd64.zip from https://caddyserver.com/download, and put caddy.exe in $caddyDir"
    }
    Expand-Archive -Path $zip -DestinationPath $caddyDir -Force
    Remove-Item $zip -Force
    Ok "Downloaded"
}

# ---- start a dedicated PHP server just for this ------------------------------

Step "Starting a PHP server dedicated to LAN access on 127.0.0.1:$PhpPort"

if (Get-NetTCPConnection -LocalPort $PhpPort -State Listen -ErrorAction SilentlyContinue) {
    Ok "Already running"
} else {
    $env:PHP_CLI_SERVER_WORKERS = '8'
    Start-Process -FilePath 'php' -ArgumentList @('-S', "127.0.0.1:$PhpPort", '-t', 'public', 'public/index.php') `
        -WorkingDirectory $Root -WindowStyle Hidden
    Start-Sleep -Seconds 1
    Ok "Started"
}

# ---- write a standalone Caddyfile for this, on its own port -----------------

Step "Writing a LAN-only Caddy config on port $LanPort"

$publicPath   = (Join-Path $Root 'public') -replace '\\', '/'
$lanCaddyfile = Join-Path $Root 'Caddyfile.lan'

@"
# Generated by scripts/windows/lan-access.ps1 - independent of the main
# Caddyfile/Laragon vhost, so it never contends for port 443.
{
	local_certs
	admin off
}

${lanIp}:${LanPort} {
	tls internal

	root * $publicPath

	encode gzip

	@build path /build/*
	header @build Cache-Control "public, max-age=31536000, immutable"

	@sw path /sw.js
	header @sw Cache-Control "no-cache, must-revalidate"

	@static file
	handle @static {
		file_server
	}

	handle {
		reverse_proxy 127.0.0.1:$PhpPort
	}
}
"@ | Set-Content -Path $lanCaddyfile -Encoding UTF8

Ok "Written"

# ---- (re)start the LAN caddy instance ----------------------------------------

Step "Starting Caddy for LAN access"

Get-CimInstance Win32_Process -Filter "Name = 'caddy.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -like '*Caddyfile.lan*' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
Start-Sleep -Seconds 1

Start-Process -FilePath $caddyExe -ArgumentList @('run', '--config', $lanCaddyfile) `
    -WorkingDirectory $Root -WindowStyle Hidden
Ok "Caddy started - it will mint a certificate for $lanIp on first request"

# ---- firewall -----------------------------------------------------------------

Step "Opening the firewall for inbound TCP $LanPort on the private network"

$ruleName = "OPES360 LAN Access ($LanPort)"
if (Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue) {
    Ok "Firewall rule already present"
} else {
    New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -Protocol TCP -LocalPort $LanPort `
        -Profile Private -Action Allow | Out-Null
    Ok "Added"
}

# ---- locate the local CA root so it can be copied to the phone ----------------

Step "Locating the local certificate authority"

$caRoot = @(
    (Join-Path $env:AppData 'Caddy'),
    (Join-Path $env:LOCALAPPDATA 'Caddy')
) | Where-Object { Test-Path $_ } |
    ForEach-Object { Get-ChildItem -Path $_ -Recurse -Filter 'root.crt' -ErrorAction SilentlyContinue } |
    Select-Object -First 1 -ExpandProperty FullName

if ($caRoot) {
    $dest = Join-Path ([Environment]::GetFolderPath('Desktop')) 'OPES360-local-CA.crt'
    Copy-Item $caRoot $dest -Force
    Ok "Copied to your Desktop as OPES360-local-CA.crt"
} else {
    Warn "Could not find root.crt automatically."
    Warn "It's under %AppData%\Caddy or %LocalAppData%\Caddy\pki\authorities\local\ - search for root.crt."
}

# ---- verify -------------------------------------------------------------------

Step "Checking it actually answers"

# -SkipCertificateCheck is PowerShell 7+ only; this works on Windows
# PowerShell 5.1 too, which is what "powershell.exe" launches.
$originalCertCallback = [System.Net.ServicePointManager]::ServerCertificateValidationCallback
[System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }

$ready = $false
try {
    foreach ($i in 1..15) {
        Start-Sleep -Seconds 2
        try {
            $r = Invoke-WebRequest "https://${lanIp}:${LanPort}/up" -UseBasicParsing -TimeoutSec 5
            if ($r.StatusCode -eq 200) { $ready = $true; break }
        } catch { }
    }
} finally {
    [System.Net.ServicePointManager]::ServerCertificateValidationCallback = $originalCertCallback
}

if ($ready) {
    Ok "https://${lanIp}:${LanPort} is answering"
} else {
    Warn "Did not answer within 30 seconds. Check storage\logs\laravel.log,"
    Warn "and that nothing else already owns port $LanPort or $PhpPort."
}

# ---- done -----------------------------------------------------------------

Write-Host ""
Write-Host "  Phone (same WiFi) -> https://${lanIp}:${LanPort}" -ForegroundColor White
Write-Host ""
Write-Host "  The padlock will show a warning until the phone trusts this PC's CA." -ForegroundColor Gray
Write-Host "  One-time, per phone:" -ForegroundColor Gray
Write-Host "    1. Send OPES360-local-CA.crt from the Desktop to the phone (email, AirDrop, etc.)" -ForegroundColor Gray
Write-Host "    2. Android: open the file -> Install -> CA certificate." -ForegroundColor Gray
Write-Host "       iOS: open it to install the profile, then Settings -> General ->" -ForegroundColor Gray
Write-Host "       About -> Certificate Trust Settings -> turn full trust on for it." -ForegroundColor Gray
Write-Host "    3. Reload https://${lanIp}:${LanPort} on the phone." -ForegroundColor Gray
Write-Host ""
Write-Host "  Until then it still works with a 'not secure' warning you can click" -ForegroundColor Gray
Write-Host "  through - only the offline/PWA install features need the trusted cert." -ForegroundColor Gray
Write-Host ""
Write-Host "  This runs independently of opes360.test - re-run this script any time" -ForegroundColor Gray
Write-Host "  after a reboot to bring it back." -ForegroundColor Gray
Write-Host ""
