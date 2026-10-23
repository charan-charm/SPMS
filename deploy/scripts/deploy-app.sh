#!/usr/bin/env bash
# ==============================================================================
# SPMS Application Deployment Script
# Target: /var/www/spms on Ubuntu EC2
# Usage: sudo bash /var/www/spms/deploy/scripts/deploy-app.sh
# ==============================================================================

set -euo pipefail

APP_DIR="/var/www/spms"
ENV_FILE="${APP_DIR}/.env"

echo "======================================================================"
echo "Starting SPMS Application Deployment"
echo "Timestamp: $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo "======================================================================"

# 1. Environment File Safeguard
if [ ! -f "${ENV_FILE}" ]; then
    echo "ERROR: Production .env file not found at ${ENV_FILE}"
    echo "Copy deploy/env/production.env.example to ${ENV_FILE} and configure secrets first."
    exit 1
fi

# Ensure production environment cannot accidentally run in demo mode
if grep -q "SPMS_DATA_ENVIRONMENT=demo" "${ENV_FILE}"; then
    echo "CRITICAL ERROR: .env has SPMS_DATA_ENVIRONMENT=demo. Production must run with 'real'."
    exit 1
fi

# 2. Secure Permissions on .env
chmod 640 "${ENV_FILE}"
chown root:www-data "${ENV_FILE}"
echo "✓ Permissions secured on .env (0640, root:www-data)"

# 3. Install/Update PHP Dependencies (No dev dependencies)
echo ">>> Installing PHP production dependencies..."
cd "${APP_DIR}"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# 4. Storage & Cache Permissions
mkdir -p "${APP_DIR}/storage/cache"
mkdir -p "${APP_DIR}/storage/locks"
mkdir -p "${APP_DIR}/storage/logs"
mkdir -p "${APP_DIR}/storage/mail"
mkdir -p "${APP_DIR}/storage/private"
mkdir -p "${APP_DIR}/storage/temp"

chown -R www-data:www-data "${APP_DIR}/storage"
chmod -R 770 "${APP_DIR}/storage"
echo "✓ Storage directories prepared and secured"

# 5. Database Migrations
echo ">>> Applying database migrations..."
php -r "
require '${APP_DIR}/vendor/autoload.php';
\App\Support\Config::initialize('${APP_DIR}');
\App\Support\Database::switchTo('real');
echo 'Checking database connectivity...\n';
\$pdo = \App\Support\Database::connection();
echo 'Database connected: ' . \$pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . '\n';
"

# Run migrations
if [ -f "${APP_DIR}/database/migrate.php" ]; then
    php "${APP_DIR}/database/migrate.php"
elif [ -f "${APP_DIR}/scripts/migrate.php" ]; then
    php "${APP_DIR}/scripts/migrate.php"
fi


# 6. Install Nginx & PHP-FPM Configuration
echo ">>> Configuring Nginx and PHP-FPM..."
cp "${APP_DIR}/deploy/nginx/spms.conf" /etc/nginx/sites-available/spms.conf
if [ ! -f /etc/nginx/sites-enabled/spms.conf ]; then
    ln -s /etc/nginx/sites-available/spms.conf /etc/nginx/sites-enabled/spms.conf
fi
# Remove default nginx welcome site if present
rm -f /etc/nginx/sites-enabled/default

cp "${APP_DIR}/deploy/php/spms-php-fpm.conf" /etc/php/8.4/fpm/pool.d/spms.conf

nginx -t
systemctl restart php8.4-fpm
systemctl reload nginx
echo "✓ Nginx and PHP-FPM reloaded successfully"

# 7. Post-Deployment Verification Probe
echo ">>> Executing health check probe..."
sleep 1
HEALTH_RESPONSE=$(curl -s http://127.0.0.1/api/health || true)
echo "Health Response: ${HEALTH_RESPONSE}"

if echo "${HEALTH_RESPONSE}" | grep -q '"overall":"healthy"'; then
    echo "======================================================================"
    echo "✓ DEPLOYMENT SUCCEEDED: System reports healthy and database connected."
    echo "======================================================================"
else
    echo "======================================================================"
    echo "WARNING: Health check did not return healthy state. Check logs:"
    echo "  tail -n 50 /var/log/php-fpm/spms-error.log"
    echo "  tail -n 50 /var/log/nginx/error.log"
    echo "======================================================================"
    exit 1
fi
