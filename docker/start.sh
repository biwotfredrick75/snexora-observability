#!/bin/bash
set -e

cd /var/www/html

# Clear stale bootstrap cache then re-cache with injected env vars
php artisan config:clear
php artisan route:clear
php artisan config:cache
php artisan route:cache
php artisan event:cache

# Run any pending migrations
php artisan migrate --force --no-interaction

# Seed roles, permissions, admin user, transaction refs, GL, etc.
# Uses firstOrCreate — safe to run on every boot.
php artisan db:seed --class=FreshInstallSeeder --force --no-interaction || \
    echo "[start.sh] WARNING: FreshInstallSeeder failed, continuing..."

# ── Sample / demo data seeding ────────────────────────────────────────────
# Set SEED_SAMPLE_DATA=true in Container Apps env vars to seed farmers,
# suppliers, customers, milk prices, and milk collections.
# Remove the env var (or set to false) once seeding is complete —
# the seeder is guarded by table-count checks so re-running is safe.
# ─────────────────────────────────────────────────────────────────────────
if [ "${SEED_SAMPLE_DATA:-false}" = "true" ]; then
    echo "[start.sh] SEED_SAMPLE_DATA=true — running SampleDataSeeder…"
    php artisan db:seed --class=SampleDataSeeder --force --no-interaction || \
        echo "[start.sh] WARNING: SampleDataSeeder failed, continuing..."
    echo "[start.sh] Sample data seeding complete. Set SEED_SAMPLE_DATA=false to skip next time."
fi

# The commands above run as root and write new files under storage/ and
# bootstrap/cache/ (migrations, seeders, config/route/event cache). php-fpm's
# workers run as www-data, so any new file/dir left root-owned here causes a
# "Permission denied" the first time a request needs to write a sibling cache
# entry in the same directory (e.g. Spatie's permission cache). Re-sync
# ownership right before handing off so php-fpm can always write its caches.
chown -R www-data:www-data storage bootstrap/cache

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
