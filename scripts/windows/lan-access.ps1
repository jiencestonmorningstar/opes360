<#
.SYNOPSIS
    Makes the local OPES360 install reachable from other devices on the same
    WiFi - phones included - over trusted HTTPS.

.DESCRIPTION
    install.ps1 already runs Caddy with `tls internal`, which stands up its own
    certificate authority and trusts it on this PC. That CA is the reason
    https://opes360.test has a padlock with no manual cert wrangling. Two
    things stop a phone on the same network from sharing that:

      1. The Caddyfile only lists the domain, so Caddy has nothing to serve
         for a request that arrives addressed to the LAN IP instead.
      2. The phone has never seen this PC's CA, so even a request that does
         reach the site shows "not secure."

    This script fixes both: it adds the LAN IP as a second site address in the
    Caddyfile (Caddy then issues/serves a local cert for it too), opens the
    firewall for 443 on the private profile, and locates the CA root cert so
    you can copy it to the phone once.

    Only handles the Caddy install (the one this repo's own installer sets
    up). If Laragon is serving the site instead, its SSL is *.test-only and
    does not extend to a bare LAN IP the same way - see the printed notes.

.EXAMPLE
    .\lan-access.ps1
#>

[CmdletBinding()]
param(
    [string] $Domain = 'opes360.test'
)

$ErrorActionPreference = 'Stop'

$identity  = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "Re-launching as Administrator - a new window will open and STAY OPEN." -ForegroundColor Yellow
    Write-Host "If Windows asks for permission, click Yes." -ForegroundColor Yellow
    # -NoExit: without it the elevated window runs the script and closes itself
    # immediately - including on error - before there is anything to read.
    Start-Process powershell -Verb RunAs -ArgumentList @('-NoExit', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"", '-Domain', "`"$Domain`"")
    exit
}

$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root

function Step($text) { Write-Host "`n==> $text" -ForegroundColor Cyan }
function Ok($text)   { Write-Host "    $text" -ForegroundColor Green }
function Warn($text) { Write-Host "    $text" -ForegroundColor Yellow }
function Die($text)  { Write-Host "`nFAILED: $text" -ForegroundColor Red; exit 1 }

Write-Host "OPES360 - LAN / phone access" -ForegroundColor White

# ---- find the LAN IP ----------------------------------------------------------

Step "Finding this machine's LAN IP"

$lanIp = Get-NetIPAddress -AddressFamily IPv4 |
    Where-Object {
        $_.IPAddress -notlike '169.254.*' -and $_.IPAddress -ne '127.0.0.1' -and
        ($_.InterfaceAlias -notmatch 'Loopback|vEthernet|WSL')
    } |
    Sort-Object -Property @{ Expression = { $_.InterfaceAlias -match 'Wi-?Fi' }; Descending = $true } |
    Select-Object -First 1 -ExpandProperty IPAddress

if (-not $lanIp) { Die "Could not find a LAN IP. Run 'ipconfig' and pass one manually via -Domain / edit the Caddyfile yourself." }
Ok "$lanIp"

# ---- Caddy or Laragon? ---------------------------------------------------------

$caddyfilePath = Join-Path $Root 'Caddyfile'
$UseLaragon    = -not (Test-Path $caddyfilePath)

if ($UseLaragon) {
    Step "Laragon install detected"
    Warn "This script only automates the Caddy path (what install.ps1 uses when"
    Warn "Laragon isn't present). Laragon's own *.test certificate does not cover"
    Warn "a bare LAN IP, so the padlock won't extend to the phone automatically."
    Write-Host ""
    Write-Host "  What still works right now, no changes needed:" -ForegroundColor Gray
    Write-Host "    Add '$lanIp `t$Domain' to the PHONE's hosts file if it supports one," -ForegroundColor Gray
    Write-Host "    or just browse to https://$lanIp directly and accept the" -ForegroundColor Gray
    Write-Host "    certificate warning - the app itself works, only the padlock is red." -ForegroundColor Gray
    Write-Host ""
    Write-Host "  For a real padlock on the phone, use mkcert against Laragon's vhost" -ForegroundColor Gray
    Write-Host "  instead - ask for that walkthrough if you want it." -ForegroundColor Gray
}

# ---- firewall -------------------------------------------------------------

Step "Opening the firewall for inbound HTTPS on the private network"

$ruleName = 'OPES360 LAN HTTPS'
if (Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue) {
    Ok "Firewall rule already present"
} else {
    New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -Protocol TCP -LocalPort 443 `
        -Profile Private -Action Allow | Out-Null
    Ok "Added inbound rule for TCP 443 (Private networks only)"
}

if ($UseLaragon) {
    Write-Host ""
    Write-Host "  Phone (same WiFi) -> https://$lanIp" -ForegroundColor White
    Write-Host ""
    exit
}

# ---- extend the Caddyfile to also serve the LAN IP ----------------------------

Step "Adding $lanIp to the Caddyfile"

$content = Get-Content $caddyfilePath -Raw
$escapedDomain = [regex]::Escape($Domain)

if ($content -match "(?m)^$escapedDomain,\s*$([regex]::Escape($lanIp))\s*\{") {
    Ok "Already configured for $lanIp"
} elseif ($content -match "(?m)^$escapedDomain\s*\{") {
    $content = [regex]::Replace($content, "(?m)^$escapedDomain\s*\{", "$Domain, $lanIp {")
    Set-Content -Path $caddyfilePath -Value $content -NoNewline
    Ok "Caddyfile now serves both $Domain and $lanIp"
} else {
    Die "Could not find a '$Domain {' block in the Caddyfile - has it been hand-edited? Add ', $lanIp' to the site address yourself and re-run."
}

# ---- restart caddy so it picks up the new site address and issues a cert ------

Step "Restarting Caddy"

$caddyExe = Join-Path $PSScriptRoot 'bin\caddy.exe'
if (-not (Test-Path $caddyExe)) { Die "caddy.exe not found at $caddyExe - run install.ps1 first." }

Get-Process caddy -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Sleep -Seconds 1
Start-Process -FilePath $caddyExe -ArgumentList @('run', '--config', $caddyfilePath) `
    -WorkingDirectory $Root -WindowStyle Hidden
Ok "Caddy restarted - it will mint a certificate for $lanIp on first request"

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

# ---- done -----------------------------------------------------------------

Write-Host ""
Write-Host "  Phone (same WiFi) -> https://$lanIp" -ForegroundColor White
Write-Host ""
Write-Host "  The padlock will show a warning until the phone trusts this PC's CA." -ForegroundColor Gray
Write-Host "  One-time, per phone:" -ForegroundColor Gray
Write-Host "    1. Send OPES360-local-CA.crt from the Desktop to the phone (email, AirDrop, etc.)" -ForegroundColor Gray
Write-Host "    2. Android: open the file -> Install -> CA certificate." -ForegroundColor Gray
Write-Host "       iOS: open it to install the profile, then Settings -> General ->" -ForegroundColor Gray
Write-Host "       About -> Certificate Trust Settings -> turn full trust on for it." -ForegroundColor Gray
Write-Host "    3. Reload https://$lanIp on the phone." -ForegroundColor Gray
Write-Host ""
Write-Host "  Until then, https://$lanIp still works with a 'not secure' warning" -ForegroundColor Gray
Write-Host "  you can click through - only the offline/PWA install features need" -ForegroundColor Gray
Write-Host "  the trusted cert." -ForegroundColor Gray
Write-Host ""
