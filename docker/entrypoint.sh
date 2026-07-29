#!/bin/sh
set -e

# Runs before every container start — web, queue and scheduler alike.

: "${APP_KEY:?APP_KEY must be set. Generate it ONCE with 'php artisan key:generate --show' and keep it: encrypted tax IDs and 2FA secrets are unreadable without the key that wrote them.}"

# Config is cached at start rather than at build, because the values come from
# the environment and are not known when the image is made.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Uploaded logos and avatars are served from public/storage. The link lives on a
# volume, so it has to be made at start rather than baked into the image.
php artisan storage:link --quiet || true

# Off by default. Migrating from the web container means N replicas racing the
# same schema change, and a failed migration takes the site down rather than
# leaving the previous release serving. Set RUN_MIGRATIONS=true only where a
# single instance owns the deploy.
if [ "${RUN_MIGRATIONS}" = "true" ]; then
    php artisan migrate --force --isolated
fi

exec "$@"
