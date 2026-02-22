#!/bin/bash
set -e

# =========================
# CONFIG BÁSICA
# =========================
APP_DIR=/var/www/html
APACHE_USER=www-data
APACHE_GROUP=www-data

cd $APP_DIR

# Asegurar permisos heredables
umask 002

# =========================
# APACHE + MPM
# =========================
echo "Disabling all MPM modules..."
a2dismod mpm_event mpm_worker mpm_prefork worker event 2>/dev/null || true

echo "Enabling mpm_prefork..."
a2enmod mpm_prefork headers rewrite

echo "Enabled MPM modules:"
ls -la /etc/apache2/mods-enabled/mpm_* 2>/dev/null || true

# =========================
# PUERTO DINÁMICO (Railway)
# =========================
PORT=${PORT:-80}
echo "Configuring Apache on port $PORT"

sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/*.conf

# =========================
# CORS (Apache)
# =========================
echo "Configuring CORS headers..."
grep -q "CORS Configuration" /etc/apache2/apache2.conf || cat >> /etc/apache2/apache2.conf << 'EOF'

# CORS Configuration
<IfModule mod_headers.c>
    Header always set Access-Control-Allow-Origin "*"
    Header always set Access-Control-Allow-Methods "GET, POST, PUT, PATCH, DELETE, OPTIONS"
    Header always set Access-Control-Allow-Headers "Content-Type, Authorization, X-Requested-With, Accept"
    Header always set Access-Control-Max-Age "3600"
</IfModule>
EOF

# =========================
# STORAGE LARAVEL (CRÍTICO)
# =========================
echo "Setting up Laravel storage..."

mkdir -p storage/logs
mkdir -p storage/framework/{cache,sessions,views}
mkdir -p bootstrap/cache

chown -R ${APACHE_USER}:${APACHE_GROUP} storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

# Archivo de log obligatorio
touch storage/logs/laravel.log
chmod 666 storage/logs/laravel.log

# =========================
# STORAGE LINK (SEGURO)
# =========================
if [ -d "public/storage" ] || [ -L "public/storage" ]; then
  echo "Removing existing public/storage"
  rm -rf public/storage
fi

if [ -f "artisan" ]; then
  echo "Linking storage..."
  php artisan storage:link || true
else
  echo "❌ artisan not found — aborting"
  exit 1
fi

# =========================
# LIMPIEZA CONTROLADA
# =========================
echo "Cleaning Laravel caches..."
php artisan optimize:clear || true

# =========================
# ESPERAR DB
# =========================
echo "Waiting for database..."
timeout=30
elapsed=0

until php artisan migrate:status >/dev/null 2>&1 || [ $elapsed -ge $timeout ]; do
  sleep 2
  elapsed=$((elapsed + 2))
  echo "Waiting for DB... (${elapsed}s)"
done

if [ $elapsed -lt $timeout ]; then
  echo "Running migrations..."
  php artisan migrate --force || true
else
  echo "⚠️ Database not ready, skipping migrations"
fi

# =========================
# CACHE FINAL (ORDEN CORRECTO)
# =========================
echo "Caching configuration..."
php artisan config:clear
php artisan config:cache
php artisan route:cache || true
php artisan view:cache || true

# =========================
# QUEUE CLEAN
# =========================
php artisan queue:clear 2>/dev/null || true
php artisan queue:flush 2>/dev/null || true

# =========================
# START SERVICES
# =========================
echo "Starting Apache on port $PORT..."
apache2-foreground &

echo "Starting Laravel queue worker..."
php artisan queue:work \
  --tries=3 \
  --timeout=60 \
  --sleep=3 \
  --max-jobs=1000 &

wait