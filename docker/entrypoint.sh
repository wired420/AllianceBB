#!/bin/sh
set -e

# First start: copy the release into the /app volume.
if [ ! -f /app/src/App.php ]; then
    echo "AllianceBB: installing files into /app"
    cp -a /usr/src/alliancebb/. /app/
fi
mkdir -p /app/var/cache /app/plugins /app/themes

# Wait for PostgreSQL, then apply any new migrations.
tries=0
until php -r 'require "/app/vendor/autoload.php"; AllianceBB\Core\Database::fromEnv()->pdo();' 2>/dev/null; do
    tries=$((tries + 1))
    if [ "$tries" -gt 60 ]; then echo "AllianceBB: database not reachable"; exit 1; fi
    sleep 1
done
[ "${SKIP_MIGRATE:-0}" = "1" ] || php /app/bin/console migrate

exec "$@"
