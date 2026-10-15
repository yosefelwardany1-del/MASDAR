#!/bin/sh
set -e

# Fix storage permissions
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Clear any stale caches first so a deploy can never serve old config/routes/views
# (e.g. a new config file or a changed Blade template not showing up after deploy),
# then rebuild the production caches from the freshly-deployed source.
php artisan optimize:clear || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run migrations automatically on deploy. Non-fatal: a transient DB hiccup during deploy
# (e.g. the database briefly saturated by a background job) must NOT abort the whole
# container boot with `set -e` — otherwise the fix that would relieve the DB can never
# deploy. Normally there are no pending migrations anyway, so a skipped run is harmless.
php artisan migrate --force || echo "WARNING: migrate failed (non-fatal — container will still boot)"

# Seed roles and permissions on every deploy. Idempotent: it creates what is missing and
# never revokes a grant changed in the role editor. Non-fatal so a database hiccup cannot
# stop the container booting.
php artisan db:seed --class=RolesAndPermissionsSeeder --force || echo "WARNING: role seeding failed (non-fatal)"

# Public storage symlink (public/storage -> storage/app/public) so Storage::url() and
# asset('storage/...') resolve. The ephemeral filesystem drops it on every redeploy, so
# (re)create it here. Without it, uploaded files referenced via the public URL 404.
php artisan storage:link 2>/dev/null || true

# nginx runs as www-data but the stock temp dirs are owned by 'nginx'; give it a
# www-data-owned temp tree (matches client_body_temp_path/etc. in nginx.conf) so it can
# buffer request bodies — otherwise every file upload fails with an nginx 500.
mkdir -p /tmp/nginx/client_body /tmp/nginx/fastcgi /tmp/nginx/proxy
chown -R www-data:www-data /tmp/nginx

# Make libpq SSL certs world-readable so www-data queue workers can connect.
# DB_URL may hardcode sslcert=/root/.postgresql/postgresql.crt; chmod lets all
# processes read these files regardless of which user runs them.
chmod -R 755 /root/.postgresql 2>/dev/null || true

# Create supervisor log dir
mkdir -p /var/log/supervisor

# Everything above ran as root, and some of it writes files: the permission registry and
# other answers now live on this container's disk (the 'local' cache store), and the PHP
# workers, queue worker and scheduler run as www-data. Hand the tree back to them, or a role
# edited later could not clear a registry root wrote at boot.
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
