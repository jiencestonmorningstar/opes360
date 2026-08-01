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
New-Item -ItemType Directory -Force -Path $stageRelease | Out-Null

$include = @(
    'app', 'bootstrap', 'config', 'database', 'public', 'resources',
    'routes', 'scripts', 'storage', 'vendor', 'artisan', 'composer.json', 'composer.lock'
)

foreach ($item in $include) {
    $source = Join-Path $root $item
    if (Test-Path $source) {
        Copy-Item $source -Destination $stageRelease -Recurse -Force
    }
}

Copy-Item (Join-Path $root '.env.example') (Join-Path $stageRelease '.env.example') -Force

# --- pruning ----------------------------------------------------------------
# Composer keeps a .git directory per package when anything was installed from
# source, and package history dwarfs package code. None is reachable at runtime,
# and a shared host's upload limit is the binding constraint.
Step 'Pruning non-runtime files'
# -Include is matched against the leaf name and quietly finds nothing in some
# PowerShell versions, so filter explicitly instead of trusting it.
Get-ChildItem $stageRelease -Recurse -Force -Directory -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -eq '.git' -or $_.Name -eq '.github' } |
    Sort-Object { $_.FullName.Length } -Descending |
    Remove-Item -Recurse -Force -ErrorAction SilentlyContinue

# --- local state ------------------------------------------------------------
# A .env or a SQLite file from this machine must never reach the server: one
# holds the key that decrypts every stored tax ID, the other your test data.
Step 'Clearing local state'
foreach ($leak in '.env', 'storage\app\installed', 'database\database.sqlite') {
    $path = Join-Path $stageRelease $leak
    if (Test-Path $path) { Remove-Item $path -Force }
}

Get-ChildItem (Join-Path $stageRelease 'storage\logs') -Filter '*.log' -ErrorAction SilentlyContinue | Remove-Item -Force
foreach ($cache in 'framework\cache\data', 'framework\sessions', 'framework\views') {
    $path = Join-Path $stageRelease "storage\$cache"
    if (Test-Path $path) {
        Get-ChildItem $path -Exclude '.gitignore' -Force | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
    }
}
Get-ChildItem (Join-Path $stageRelease 'bootstrap\cache') -Filter '*.php' -ErrorAction SilentlyContinue | Remove-Item -Force

# --- verification -----------------------------------------------------------
Step 'Verifying the archive is complete'
foreach ($needed in 'vendor\autoload.php', 'public\index.php', 'public\install.php', 'public\build\manifest.json', 'artisan') {
    if (-not (Test-Path (Join-Path $stageRelease $needed))) { Die "$needed is missing from the release." }
}
if (Test-Path (Join-Path $stageRelease '.env')) { Die 'A .env made it into the release. Refusing to package it.' }

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
        $stage, $zip, [System.IO.Compression.CompressionLevel]::Optimal, $false)
} catch {
    Write-Host 'Falling back to Compress-Archive (slower)...' -ForegroundColor Yellow
    Compress-Archive -Path $stageRelease -DestinationPath $zip -CompressionLevel Optimal
}

if (-not (Test-Path $zip)) { Die 'The archive was not created.' }

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
