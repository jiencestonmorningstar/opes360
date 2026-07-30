#!/usr/bin/env bash
#
# Brings the local https://opes360.test deployment back up.
#
# The container does not run an init system, so nothing here survives a restart.
# This starts the four pieces in the order they depend on each other and waits
# for the site to actually answer rather than assuming it did.

set -euo pipefail

APP=/var/www/opes360
SOCK=/var/run/mariadb/mariadb.sock

start_if_absent() { pgrep -f "$1" >/dev/null || eval "$2"; }

echo "==> MariaDB"
start_if_absent "mariadbd --user=mysql --datadir=/var/lib/mariadb" \
    "(mariadbd --user=mysql --datadir=/var/lib/mariadb --port=3307 --socket=$SOCK >/var/log/opes-mariadb.log 2>&1 &)"
until mariadb --socket="$SOCK" -uroot -e "SELECT 1" >/dev/null 2>&1; do sleep 1; done

echo "==> PHP workers"
start_if_absent "php -S 127.0.0.1:9001" \
    "(cd $APP && PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:9001 -t public public/index.php >/var/log/opes-php.log 2>&1 &)"

echo "==> nginx"
start_if_absent "nginx: master" "(nginx -g 'daemon off;' >/var/log/opes-nginx.log 2>&1 &)"

echo "==> cron (queue worker + scheduler)"
start_if_absent "^cron" "(cron -f >/var/log/opes-cron.log 2>&1 &)"

until curl -s --noproxy opes360.test -o /dev/null https://opes360.test/up; do sleep 1; done

echo
echo "  https://opes360.test   ready"
echo "  sign in: john@opesware.com / password"
