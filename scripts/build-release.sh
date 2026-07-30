#!/usr/bin/env bash
#
# Builds an upload-ready release for cPanel shared hosting.
#
# Shared hosting has no Node and often no reliable Composer, so both have to run
# here and ship in the archive. The result is a zip you extract on the server and
# nothing else — no build step, no network fetch, no root.
#
# Usage:  ./scripts/build-release.sh [output-directory]

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/build}"
STAMP="$(date -u +%Y%m%d-%H%M)"
STAGE="$(mktemp -d)"
RELEASE="opes360-$STAMP"

cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT

cd "$ROOT"

echo "==> Installing PHP dependencies (production)"
# --no-dev matters for more than size: dev packages register debug routes and
# error pages that must never exist on a live financial application.
composer install \
    --no-dev \
    --optimize-autoloader \
    --classmap-authoritative \
    --prefer-dist \
    --no-interaction \
    --quiet

echo "==> Building front-end assets"
npm ci --silent
npm run build

echo "==> Staging $RELEASE"
mkdir -p "$STAGE/$RELEASE"

# Everything the application needs to run, and nothing it does not. Tests, the
# node toolchain and the local database are deliberately absent.
for path in \
    app bootstrap config database public resources routes storage vendor \
    artisan composer.json composer.lock
do
    cp -R "$path" "$STAGE/$RELEASE/"
done

cp .env.example "$STAGE/$RELEASE/.env.example"

echo "==> Pruning non-runtime files from vendor"
# Composer keeps a .git directory per package when anything was ever installed
# from source, and package history is far larger than package code — here it was
# 380 MB of the 450 MB tree. None of it is reachable at runtime, and a shared
# host's upload limit is the binding constraint, so it goes.
find "$STAGE/$RELEASE/vendor" -type d -name '.git' -prune -exec rm -rf {} + 2>/dev/null || true
find "$STAGE/$RELEASE/vendor" -type d -name '.github' -prune -exec rm -rf {} + 2>/dev/null || true
find "$STAGE/$RELEASE/vendor" -type f \( \
        -name '*.md' -o -name '.gitignore' -o -name '.gitattributes' -o \
        -name '.editorconfig' -o -name 'phpunit.xml*' -o -name '.php-cs-fixer*' \
    \) -delete 2>/dev/null || true

echo "==> Clearing local state from the archive"
rm -rf "$STAGE/$RELEASE"/storage/logs/*
rm -rf "$STAGE/$RELEASE"/storage/framework/cache/data/*
rm -rf "$STAGE/$RELEASE"/storage/framework/sessions/*
rm -rf "$STAGE/$RELEASE"/storage/framework/views/*
rm -f  "$STAGE/$RELEASE"/database/*.sqlite
# A cached config baked here would carry this machine's .env — including its
# APP_KEY — onto the server. It is rebuilt there instead.
rm -f  "$STAGE/$RELEASE"/bootstrap/cache/*.php
# The symlink points at a path that does not exist on the server; it is recreated
# by the deploy steps.
rm -f  "$STAGE/$RELEASE"/public/storage

# Directories git does not carry but the framework needs to exist.
mkdir -p \
    "$STAGE/$RELEASE"/storage/logs \
    "$STAGE/$RELEASE"/storage/framework/{cache/data,sessions,views} \
    "$STAGE/$RELEASE"/storage/app/public

echo "==> Verifying the archive is complete"
test -f "$STAGE/$RELEASE/vendor/autoload.php"       || { echo "FAIL: vendor missing"; exit 1; }
test -f "$STAGE/$RELEASE/public/build/manifest.json" || { echo "FAIL: assets not built"; exit 1; }
test ! -e "$STAGE/$RELEASE/.env"                     || { echo "FAIL: .env must never ship"; exit 1; }
test ! -d "$STAGE/$RELEASE/node_modules"             || { echo "FAIL: node_modules must never ship"; exit 1; }

mkdir -p "$OUT"
ARCHIVE="$OUT/$RELEASE.zip"
rm -f "$ARCHIVE"

echo "==> Compressing"
(cd "$STAGE" && zip -qr "$ARCHIVE" "$RELEASE")

echo
echo "Release: $ARCHIVE"
echo "Size:    $(du -h "$ARCHIVE" | cut -f1)"
echo
echo "Next: docs/DEPLOY-NAMECHEAP.md"
