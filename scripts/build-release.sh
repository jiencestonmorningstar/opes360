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
# The archive holds the two halves the server needs, already separated:
#
#   opes360/      the application — must NOT be web-served, it holds .env
#   public_html/  only what the web may reach
#
# Extracting this in the cPanel home directory drops each half where it
# belongs and merges public_html into the one already there. Shipping a
# single folder instead would leave the operator to move the contents of
# public/ by hand, which is the step most often got wrong — and getting it
# wrong either 404s every page or exposes the key that decrypts stored tax IDs.
mkdir -p "$STAGE/$RELEASE/opes360" "$STAGE/$RELEASE/public_html"

APP="$STAGE/$RELEASE/opes360"

# Everything the application needs to run, and nothing it does not. Tests, the
# node toolchain and the local database are deliberately absent.
for path in \
    app bootstrap config database resources routes scripts storage vendor \
    artisan composer.json composer.lock
do
    cp -R "$path" "$APP/"
done

cp .env.example "$APP/.env.example"

# The phpMyAdmin import is copied beside the finished archive further down
# rather than into it: it has to be selected from the installer's own machine,
# and fishing it back out of a 28 MB zip they have already uploaded is the sort
# of small indignity that makes a deployment guide feel wrong.
if [ ! -f database/schema/opes360-install.sql ]; then
    echo "WARNING: database/schema/opes360-install.sql is missing." >&2
    echo "         Run: php artisan opes:export-schema" >&2
fi

# The public half, including the dotfiles — .htaccess is what makes every URL
# beyond the homepage work at all.
cp -R public/. "$STAGE/$RELEASE/public_html/"

echo "==> Pruning non-runtime files from vendor"
# Composer keeps a .git directory per package when anything was ever installed
# from source, and package history is far larger than package code — here it was
# 380 MB of the 450 MB tree. None of it is reachable at runtime, and a shared
# host's upload limit is the binding constraint, so it goes.
find "$APP/vendor" -type d -name '.git' -prune -exec rm -rf {} + 2>/dev/null || true
find "$APP/vendor" -type d -name '.github' -prune -exec rm -rf {} + 2>/dev/null || true
find "$APP/vendor" -type f \( \
        -name '*.md' -o -name '.gitignore' -o -name '.gitattributes' -o \
        -name '.editorconfig' -o -name 'phpunit.xml*' -o -name '.php-cs-fixer*' \
    \) -delete 2>/dev/null || true

echo "==> Clearing local state from the archive"
rm -rf "$APP"/storage/logs/*
rm -rf "$APP"/storage/framework/cache/data/*
rm -rf "$APP"/storage/framework/sessions/*
rm -rf "$APP"/storage/framework/views/*
rm -f  "$APP"/database/*.sqlite
# A cached config baked here would carry this machine's .env — including its
# APP_KEY — onto the server. It is rebuilt there instead.
rm -f  "$APP"/bootstrap/cache/*.php
# The symlink points at a path that does not exist on the server; it is recreated
# by the deploy steps.
rm -f  "$STAGE/$RELEASE"/public_html/storage

# Directories git does not carry but the framework needs to exist.
mkdir -p \
    "$APP"/storage/logs \
    "$APP"/storage/framework/{cache/data,sessions,views} \
    "$APP"/storage/app/public

echo "==> Verifying the archive is complete"
test -f "$APP/vendor/autoload.php"                       || { echo "FAIL: vendor missing"; exit 1; }
test -f "$STAGE/$RELEASE/public_html/build/manifest.json" || { echo "FAIL: assets not built"; exit 1; }
test ! -e "$APP/.env"                     || { echo "FAIL: .env must never ship"; exit 1; }
test ! -d "$APP/node_modules"                            || { echo "FAIL: node_modules must never ship"; exit 1; }
test -f "$APP/scripts/windows/install.ps1"               || { echo "FAIL: Windows installer missing"; exit 1; }

test -f "$STAGE/$RELEASE/public_html/index.php"          || { echo "FAIL: public_html/index.php missing"; exit 1; }
test -f "$STAGE/$RELEASE/public_html/.htaccess"          || { echo "FAIL: .htaccess missing — every URL would 404"; exit 1; }
test ! -d "$STAGE/$RELEASE/public_html/app"              || { echo "FAIL: application code inside the web root"; exit 1; }
test ! -e "$STAGE/$RELEASE/public_html/.env"             || { echo "FAIL: .env inside the web root"; exit 1; }
test ! -d "$APP/public"                                  || { echo "FAIL: public/ left in the app half"; exit 1; }

mkdir -p "$OUT"
ARCHIVE="$OUT/$RELEASE.zip"
rm -f "$ARCHIVE"

echo "==> Compressing"
(cd "$STAGE/$RELEASE" && zip -qr "$ARCHIVE" opes360 public_html)

# Beside the zip, ready to be selected in phpMyAdmin's Import tab.
if [ -f database/schema/opes360-install.sql ]; then
    cp database/schema/opes360-install.sql "$(dirname "$ARCHIVE")/opes360-install.sql"
fi

echo
# Put the development dependencies back, so the working copy is usable the
# moment this finishes rather than mysteriously missing phpunit and pint.
echo "==> Restoring development dependencies"
composer install --quiet

echo "Release: $ARCHIVE"
echo "Size:    $(du -h "$ARCHIVE" | cut -f1)"
echo
echo "Next: docs/DEPLOY-NAMECHEAP.md"
