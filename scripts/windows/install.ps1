<#
.SYNOPSIS
    Sets up OPES360 on this Windows machine at https://opes360.test

.DESCRIPTION
    Does the whole thing: hosts entry, a trusted certificate, the web server,
    the database, and the application itself.

    Certificates come from Caddy, which runs its own certificate authority and
    installs it into the Windows trust store. That matters for more than the
    padlock - the service worker will not register on an untrusted origin, so
    without it the offline half of the product silently does nothing.

    Run from an ADMINISTRATOR PowerShell. Editing the hosts file and adding a
    root certificate both require it; the script re-launches itself elevated if
    it is not already.

.EXAMPLE
    .\install.ps1
    .\install.ps1 -DbPassword "secret" -Domain opes360.test
#>

[CmdletBinding()]
param(
    [string] $Domain     = 'opes360.test',
    [string] $DbName     = 'opes360',
    [string] $DbUser     = 'root',
    [string] $DbPassword = '',
    [int]    $PhpPort    = 9001,
    [switch] $SkipSeed
)

$ErrorActionPreference = 'Stop'

# ---- elevation ---------------------------------------------------------------

$identity  = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)

if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "Re-launching as Administrator..." -ForegroundColor Yellow
    $argList = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"")
    $PSBoundParameters.GetEnumerator() | ForEach-Object {
        $argList += if ($_.Value -is [switch]) { "-$($_.Key)" } else { "-$($_.Key)", "`"$($_.Value)`"" }
    }
    Start-Process powershell -Verb RunAs -ArgumentList $argList
    exit
}

$Root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $Root

function Step($text) { Write-Host "`n==> $text" -ForegroundColor Cyan }
function Ok($text)   { Write-Host "    $text" -ForegroundColor Green }
function Warn($text) { Write-Host "    $text" -ForegroundColor Yellow }
function Die($text)  { Write-Host "`nFAILED: $text" -ForegroundColor Red; exit 1 }

Write-Host "OPES360 - local setup at https://$Domain" -ForegroundColor White

# ---- prerequisites -----------------------------------------------------------

Step "Checking PHP"

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    Die "PHP is not on PATH. Install PHP 8.2+ (or Laragon Full, which bundles it) and reopen this terminal."
}

$phpVersion = (& php -r "echo PHP_VERSION;")
if ([version]($phpVersion -replace '-.*$') -lt [version]'8.2.0') {
    Die "PHP $phpVersion found, but Laravel 12 needs 8.2 or newer."
}
Ok "PHP $phpVersion"

# Each of these is used somewhere the app will not start without: pdo_mysql for
# every query, gd for logo rendering, bcmath for money, openssl for the
# encrypted tax identifiers.
$required = @('pdo_mysql', 'mbstring', 'openssl', 'gd', 'bcmath', 'fileinfo', 'zip')
$loaded   = (& php -r "echo implode(',', get_loaded_extensions());").Split(',')
$missing  = $required | Where-Object { $_ -notin $loaded }

if ($missing) {
    Die "PHP is missing: $($missing -join ', ').`n    Enable them in php.ini (remove the leading ';' from the matching extension= lines), then re-run."
}
Ok "All required extensions present"

# ---- application files -------------------------------------------------------

Step "Checking the application"

if (-not (Test-Path (Join-Path $Root 'artisan'))) {
    Die "artisan not found in $Root. Run this script from inside the extracted release."
}
if (-not (Test-Path (Join-Path $Root 'vendor\autoload.php'))) {
    Die "vendor/ is missing. Use the release archive (it includes vendor), or run 'composer install'."
}
if (-not (Test-Path (Join-Path $Root 'public\build\manifest.json'))) {
    Warn "public/build is missing - the site will render unstyled. Run 'npm ci; npm run build' if you cloned from git."
}
Ok "Application files in place"

# ---- laragon -------------------------------------------------------------

# Laragon runs its own Apache/nginx on 80/443 and its own DNS resolver for
# every *.test hostname derived from a folder name under laragon\www - that
# is exactly what this script would otherwise install Caddy to do, and two
# servers cannot both bind port 443. Detect it and hand off instead of
# fighting it. Laragon also auto-detects a Laravel project (the presence of
# `artisan`) and points the vhost at public/ on its own.
$UseLaragon = (Test-Path 'C:\laragon\laragon.exe') -or (Get-Process laragon -ErrorAction SilentlyContinue)

if ($UseLaragon) {
    Ok "Laragon detected - it will serve the site instead of Caddy"
}

# ---- hosts file --------------------------------------------------------------

if ($UseLaragon) {
    Step "Skipping the hosts file - Laragon resolves *.test itself"
} else {
    Step "Pointing $Domain at this machine"

    $hostsPath = "$env:SystemRoot\System32\drivers\etc\hosts"
    $hosts     = Get-Content $hostsPath -Raw

    if ($hosts -match "(?m)^\s*127\.0\.0\.1\s+$([regex]::Escape($Domain))\s*$") {
        Ok "hosts entry already present"
    } else {
        Add-Content -Path $hostsPath -Value "`r`n127.0.0.1`t$Domain"
        Ok "Added '127.0.0.1 $Domain' to the hosts file"
    }

    ipconfig /flushdns | Out-Null

    if (-not (Resolve-DnsName $Domain -ErrorAction SilentlyContinue)) {
        Warn "$Domain still does not resolve. Some VPN and security tools override the hosts file."
    }
}

# ---- caddy -------------------------------------------------------------------

if (-not $UseLaragon) {
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
}

# ---- database ----------------------------------------------------------------

Step "Preparing the database"

$mysql = Get-Command mysql -ErrorAction SilentlyContinue
$pwArg = if ($DbPassword) { "-p$DbPassword" } else { '' }

if ($mysql) {
    $sql = "CREATE DATABASE IF NOT EXISTS ``$DbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    $out = & mysql -u $DbUser $pwArg -e $sql 2>&1

    if ($LASTEXITCODE -ne 0) {
        Die "Could not create the database:`n    $out`n    Check the -DbUser and -DbPassword arguments, and that MySQL/MariaDB is running."
    }
    Ok "Database '$DbName' ready"
} else {
    Warn "The mysql client is not on PATH - create the database yourself:"
    Warn "  CREATE DATABASE $DbName CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    Read-Host "Press Enter once the database exists"
}

# ---- environment -------------------------------------------------------------

Step "Writing .env"

$envPath = Join-Path $Root '.env'
if (-not (Test-Path $envPath)) {
    Copy-Item (Join-Path $Root '.env.example') $envPath
}

function Set-EnvValue($key, $value) {
    $content = Get-Content $envPath -Raw
    $line    = "$key=$value"

    if ($content -match "(?m)^$([regex]::Escape($key))=.*$") {
        $content = [regex]::Replace($content, "(?m)^$([regex]::Escape($key))=.*$", $line)
    } else {
        $content = $content.TrimEnd() + "`r`n" + $line
    }
    Set-Content -Path $envPath -Value $content -NoNewline
}

Set-EnvValue 'APP_ENV'     'production'
Set-EnvValue 'APP_DEBUG'   'false'
Set-EnvValue 'APP_URL'     "https://$Domain"
Set-EnvValue 'DB_CONNECTION' 'mysql'
Set-EnvValue 'DB_HOST'     '127.0.0.1'
Set-EnvValue 'DB_PORT'     '3306'
Set-EnvValue 'DB_DATABASE' $DbName
Set-EnvValue 'DB_USERNAME' $DbUser
Set-EnvValue 'DB_PASSWORD' $DbPassword
# Mail is written to storage/logs/laravel.log rather than sent. Reset links are
# still generated and still work - you read them out of the log.
Set-EnvValue 'MAIL_MAILER' 'log'
# Caddy (or Laragon's own Apache/nginx) terminates TLS in front of PHP.
# Without this Laravel decides the request is plain http and builds every URL
# that way, which breaks the service worker and points printed QR codes at a
# scheme the site does not answer on.
Set-EnvValue 'TRUSTED_PROXIES' '*'

Ok "Configured for https://$Domain"

if (-not (Select-String -Path $envPath -Pattern '^APP_KEY=base64:' -Quiet)) {
    & php artisan key:generate --force | Out-Null
    Ok "Application key generated"
} else {
    Ok "Application key already set"
}

# ---- schema ------------------------------------------------------------------

Step "Setting up the schema"

& php artisan migrate --force
if ($LASTEXITCODE -ne 0) { Die "Migration failed - check the database credentials above." }

if (-not $SkipSeed) {
    $hasData = (& php artisan tinker --execute="echo App\Models\Company::withoutGlobalScopes()->count();" 2>$null | Select-Object -Last 1)
    if ($hasData -match '^\s*0\s*$') {
        & php artisan db:seed --force
        Ok "Demo business seeded"
    } else {
        Ok "Existing data found - not seeding over it"
    }
}

& php artisan storage:link 2>$null | Out-Null

Step "Caching configuration"
& php artisan config:cache | Out-Null
& php artisan route:cache  | Out-Null
& php artisan view:cache   | Out-Null
Ok "Cached"

if ($UseLaragon) {

    # ---- laragon handoff -------------------------------------------------

    Step "Handing off to Laragon"
    Warn "Laragon needs two clicks it cannot be scripted into (no PowerShell hook"
    Warn "for its tray menu):"
    Write-Host ""
    Write-Host "    1. Right-click the Laragon tray icon -> Apache/Nginx -> SSL ->" -ForegroundColor Yellow
    Write-Host "       Create certificate for *.test" -ForegroundColor Yellow
    Write-Host "    2. Right-click the Laragon tray icon -> Apache/Nginx -> Reload" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "    Laragon serves the folder name as the domain, so" -ForegroundColor Gray
    Write-Host "    C:\laragon\www\$(Split-Path $Root -Leaf) becomes https://$(Split-Path $Root -Leaf).test" -ForegroundColor Gray
    Write-Host "    It detects the 'artisan' file and points the vhost at public/ itself." -ForegroundColor Gray
    Write-Host ""
    Read-Host "Press Enter once both are done"

    Step "Waiting for the site"

    $ready = $false
    foreach ($i in 1..40) {
        Start-Sleep -Seconds 2
        try {
            $r = Invoke-WebRequest "https://$Domain/up" -UseBasicParsing -TimeoutSec 5
            if ($r.StatusCode -eq 200) { $ready = $true; break }
        } catch { }
    }

    Write-Host ""
    if ($ready) {
        Write-Host "  https://$Domain is up." -ForegroundColor Green
        Write-Host "  Sign in: john@opesware.com / password" -ForegroundColor Green
        Start-Process "https://$Domain"
    } else {
        Warn "The site did not answer within 80 seconds."
        Warn "Check the domain matches the folder name (Laragon derives it, -Domain is ignored by Laragon's own vhost)."
        Warn "Check Laragon's tray icon shows Apache/Nginx and MySQL running."
        Warn "PHP errors appear in storage\logs\laravel.log"
    }

    Write-Host ""
    Write-Host "  Nothing else to start or stop - Laragon manages the web server." -ForegroundColor Gray
    Write-Host ""

} else {

    # ---- caddy config ------------------------------------------------------------

    Step "Writing the web server configuration"

    $publicPath = (Join-Path $Root 'public') -replace '\\', '/'

    @"
# Generated by scripts/windows/install.ps1
{
	local_certs
	admin off
}

$Domain {
	tls internal

	# Static files come off disk. Without this every asset is proxied to PHP,
	# which routes it through Laravel and returns an HTML 404 - the stylesheet
	# then fails MIME checking and the page renders unstyled.
	root * $publicPath

	encode gzip

	@build path /build/*
	header @build Cache-Control "public, max-age=31536000, immutable"

	# Never cache the service worker, or a new version cannot replace it.
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
"@ | Set-Content -Path (Join-Path $Root 'Caddyfile') -Encoding UTF8

    Ok "Caddyfile written"

    # ---- run ---------------------------------------------------------------------

    Step "Starting"

    Get-Process php, caddy -ErrorAction SilentlyContinue |
        Where-Object { $_.Path -like "$Root*" -or $_.ProcessName -eq 'caddy' } |
        Stop-Process -Force -ErrorAction SilentlyContinue

    $env:PHP_CLI_SERVER_WORKERS = '8'
    Start-Process -FilePath 'php' `
        -ArgumentList @('-S', "127.0.0.1:$PhpPort", '-t', 'public', 'public/index.php') `
        -WorkingDirectory $Root -WindowStyle Hidden
    Ok "PHP workers started on 127.0.0.1:$PhpPort"

    Start-Process -FilePath $caddyExe `
        -ArgumentList @('run', '--config', (Join-Path $Root 'Caddyfile')) `
        -WorkingDirectory $Root -WindowStyle Hidden
    Ok "Caddy started - it will ask to trust its certificate the first time"

    Step "Waiting for the site"

    $ready = $false
    foreach ($i in 1..40) {
        Start-Sleep -Seconds 2
        try {
            $r = Invoke-WebRequest "https://$Domain/up" -UseBasicParsing -TimeoutSec 5
            if ($r.StatusCode -eq 200) { $ready = $true; break }
        } catch { }
    }

    Write-Host ""
    if ($ready) {
        Write-Host "  https://$Domain is up." -ForegroundColor Green
        Write-Host "  Sign in: john@opesware.com / password" -ForegroundColor Green
        Start-Process "https://$Domain"
    } else {
        Warn "The site did not answer within 80 seconds."
        Warn "Check: is another program using port 443 (IIS, Skype, Docker Desktop)?"
        Warn "Run 'netstat -ano | findstr :443' to see."
        Warn "PHP errors appear in storage\logs\laravel.log"
    }

    Write-Host ""
    Write-Host "  To stop:    .\scripts\windows\stop.ps1" -ForegroundColor Gray
    Write-Host "  To restart: .\scripts\windows\start.ps1" -ForegroundColor Gray
    Write-Host ""
}
