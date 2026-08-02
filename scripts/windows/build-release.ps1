<#
    Builds the upload-ready release for cPanel shared hosting, on Windows.

    The same job as scripts/build-release.sh, which is bash and so of no use on a
    Windows machine. Shared hosting has no Node and unreliable Composer, so both
    run here and ship inside the zip: what you upload needs no build step, no
    network fetch and no shell on the far side.

    Run from the project root in an ordinary PowerShell — Administrator is not
    needed:

        .\scripts\windows\build-release.ps1
#>

$ErrorActionPreference = 'Stop'

$root = (Resolve-Path "$PSScriptRoot\..\..").Path
$stamp = Get-Date -Format 'yyyyMMdd-HHmm'
$release = "opes360-$stamp"
$outDir = Join-Path $root 'build'
$stage = Join-Path ([System.IO.Path]::GetTempPath()) "opes-build-$stamp"
$stageRelease = Join-Path $stage $release

function Step($text) { Write-Host "==> $text" -ForegroundColor Cyan }
function Die($text) { Write-Host "ERROR: $text" -ForegroundColor Red; exit 1 }

Set-Location $root

foreach ($tool in 'php', 'composer', 'npm') {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) {
        Die "$tool is not on PATH. With Laragon, use its Terminal, which puts php, composer and npm there."
    }
}

# --- dependencies -----------------------------------------------------------
# --no-dev matters for more than size: dev packages register debug routes and
# error pages that must never exist on a live financial application.
Step 'Installing PHP dependencies (production)'
composer install --no-dev --optimize-autoloader --classmap-authoritative --prefer-dist --no-interaction --quiet
if ($LASTEXITCODE -ne 0) { Die 'composer install failed.' }

Step 'Building front-end assets'
npm ci --silent
if ($LASTEXITCODE -ne 0) { Die 'npm ci failed.' }
npm run build
if ($LASTEXITCODE -ne 0) { Die 'npm run build failed.' }

if (-not (Test-Path "$root\public\build\manifest.json")) {
    Die 'The asset manifest is missing — the front-end build did not produce anything.'
}

# --- staging ----------------------------------------------------------------
Step "Staging $release"
# The archive holds the two halves the server needs, already separated:
#
#   opes360\      the application — must NOT be web-served, it holds .env
#   public_html\  only what the web may reach
#
# Extracting this in the cPanel home directory drops each half where it belongs
# and merges public_html into the one already there, so nothing has to be moved
# by hand — the step most often got wrong, and getting it wrong either 404s
# every page or exposes the key that decrypts stored tax IDs.
$app = Join-Path $stageRelease 'opes360'
$web = Join-Path $stageRelease 'public_html'
New-Item -ItemType Directory -Force -Path $app, $web | Out-Null

$include = @(
    'app', 'bootstrap', 'config', 'database', 'resources',
    'routes', 'scripts', 'storage', 'vendor', 'artisan', 'composer.json', 'composer.lock'
)

foreach ($item in $include) {
    $source = Join-Path $root $item
    if (Test-Path $source) {
        Copy-Item $source -Destination $app -Recurse -Force
    }
}

Copy-Item (Join-Path $root '.env.example') (Join-Path $app '.env.example') -Force

# The phpMyAdmin import is copied beside the finished archive further down, not
# into it: it has to be selected from this machine during the install, and
# fishing it back out of a 28 MB zip that has already been uploaded is a small
# indignity a deployment guide does not need.
$schema = Join-Path $root 'database\schema\opes360-install.sql'
if (-not (Test-Path $schema)) {
    Write-Host 'WARNING: database\schema\opes360-install.sql is missing. Run: php artisan opes:export-schema' -ForegroundColor Yellow
}

# -Force so the dotfiles come too: .htaccess is what makes every URL beyond the
# homepage work at all.
Copy-Item (Join-Path $root 'public\*') -Destination $web -Recurse -Force

# --- pruning ----------------------------------------------------------------
# Composer keeps a .git directory per package when anything was installed from
# source, and package history dwarfs package code. None is reachable at runtime,
# and a shared host's upload limit is the binding constraint.
Step 'Pruning non-runtime files'
# -Include is matched against the leaf name and quietly finds nothing in some
# PowerShell versions, so filter explicitly instead of trusting it.
Get-ChildItem $app -Recurse -Force -Directory -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -eq '.git' -or $_.Name -eq '.github' } |
    Sort-Object { $_.FullName.Length } -Descending |
    Remove-Item -Recurse -Force -ErrorAction SilentlyContinue

# --- local state ------------------------------------------------------------
# A .env or a SQLite file from this machine must never reach the server: one
# holds the key that decrypts every stored tax ID, the other your test data.
Step 'Clearing local state'
foreach ($leak in '.env', 'storage\app\installed', 'database\database.sqlite') {
    $path = Join-Path $app $leak
    if (Test-Path $path) { Remove-Item $path -Force }
}

Get-ChildItem (Join-Path $app 'storage\logs') -Filter '*.log' -ErrorAction SilentlyContinue | Remove-Item -Force
foreach ($cache in 'framework\cache\data', 'framework\sessions', 'framework\views') {
    $path = Join-Path $app "storage\$cache"
    if (Test-Path $path) {
        Get-ChildItem $path -Exclude '.gitignore' -Force | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
    }
}
Get-ChildItem (Join-Path $app 'bootstrap\cache') -Filter '*.php' -ErrorAction SilentlyContinue | Remove-Item -Force

# --- verification -----------------------------------------------------------
Step 'Verifying the archive is complete'
foreach ($needed in 'vendor\autoload.php', 'artisan') {
    if (-not (Test-Path (Join-Path $app $needed))) { Die "$needed is missing from the release." }
}
foreach ($needed in 'index.php', 'install.php', '.htaccess', 'build\manifest.json') {
    if (-not (Test-Path (Join-Path $web $needed))) { Die "public_html\$needed is missing from the release." }
}
# Prove the separation held and that nothing secret sits in the web half.
if (Test-Path (Join-Path $app '.env')) { Die 'A .env made it into the release. Refusing to package it.' }
if (Test-Path (Join-Path $web '.env')) { Die 'A .env is inside the web root. Refusing to package it.' }
if (Test-Path (Join-Path $web 'app')) { Die 'Application code is inside the web root. Refusing to package it.' }
if (Test-Path (Join-Path $app 'public')) { Die 'public\ was left in the application half.' }

# --- compress ---------------------------------------------------------------
Step 'Compressing'
New-Item -ItemType Directory -Force -Path $outDir | Out-Null
$zip = Join-Path $outDir "$release.zip"
if (Test-Path $zip) { Remove-Item $zip -Force }
# .NET's zipper rather than Compress-Archive: vendor is roughly ten thousand
# files, where Compress-Archive is extremely slow and trips over paths beyond
# the old 260-character limit. Fall back to it only if the assembly is missing.
try {
    Add-Type -AssemblyName System.IO.Compression.FileSystem -ErrorAction Stop
    [System.IO.Compression.ZipFile]::CreateFromDirectory(
        $stageRelease, $zip, [System.IO.Compression.CompressionLevel]::Optimal, $false)
} catch {
    Write-Host 'Falling back to Compress-Archive (slower)...' -ForegroundColor Yellow
    Compress-Archive -Path (Join-Path $stageRelease '*') -DestinationPath $zip -CompressionLevel Optimal
}

if (-not (Test-Path $zip)) { Die 'The archive was not created.' }

# Beside the zip, ready to be selected in phpMyAdmin's Import tab.
if (Test-Path $schema) { Copy-Item $schema (Join-Path $outDir 'opes360-install.sql') -Force }

Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue

# Restore the dev dependencies this script stripped, so the working copy is
# usable again the moment it finishes.
Step 'Restoring development dependencies'
composer install --quiet

$size = '{0:N0} MB' -f ((Get-Item $zip).Length / 1MB)
Write-Host ''
Write-Host "Release: $zip" -ForegroundColor Green
Write-Host "Size:    $size"
Write-Host ''
Write-Host 'Next: docs/DEPLOY-NAMECHEAP.md'
