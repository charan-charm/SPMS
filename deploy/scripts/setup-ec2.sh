#!/usr/bin/env bash
# ==============================================================================
# SPMS EC2 Host Initialization Script (Ubuntu 22.04 / 24.04 LTS)
# Target Architecture: Amazon EC2 (t3.small or t4g.small) in ap-south-1 (Mumbai)
# Run as root: sudo bash deploy/scripts/setup-ec2.sh
# ==============================================================================

set -euo pipefail

echo ">>> [1/7] Updating package index and installing base utilities..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get upgrade -y
apt-get install -y curl wget git unzip zip software-properties-common ufw fail2ban logrotate htop

echo ">>> [2/7] Configuring UFW Firewall..."
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp comment 'SSH'
ufw allow 80/tcp comment 'HTTP'
ufw allow 443/tcp comment 'HTTPS'
# Explicitly ensure MySQL is NOT exposed externally
ufw deny 3306/tcp comment 'Block external MySQL'
ufw --force enable

echo ">>> [3/7] Installing PHP 8.4 runtime and required extensions..."
add-apt-repository -y ppa:ondrej/php
apt-get update -y
apt-get install -y \
    php8.4-fpm \
    php8.4-cli \
    php8.4-mysql \
    php8.4-curl \
    php8.4-mbstring \
    php8.4-xml \
    php8.4-zip \
    php8.4-intl \
    php8.4-bcmath \
    php8.4-gd \
    php8.4-opcache

echo ">>> [4/7] Installing Nginx and MySQL Server..."
apt-get install -y nginx mysql-server

# Secure MySQL locally
systemctl start mysql
systemctl enable mysql

echo ">>> [5/7] Installing Composer..."
if ! command -v composer &> /dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

echo ">>> [6/7] Setting up SPMS system directories..."
mkdir -p /var/www/spms
mkdir -p /var/log/spms
mkdir -p /var/log/php-fpm
mkdir -p /var/backups/spms

chown -R www-data:www-data /var/log/spms
chown -R www-data:www-data /var/log/php-fpm
chown -R root:root /var/backups/spms
chmod 700 /var/backups/spms

echo ">>> [7/7] EC2 Provisioning Complete!"
echo "----------------------------------------------------------------------"
echo "NEXT STEPS:"
echo "1. Create database and least-privilege user in MySQL:"
echo "   sudo mysql -e \"CREATE DATABASE spms_real CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\""
echo "   sudo mysql -e \"CREATE USER 'spms_app_user'@'127.0.0.1' IDENTIFIED BY 'YOUR_STRONG_PASSWORD';\""
echo "   sudo mysql -e \"GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER, REFERENCES ON spms_real.* TO 'spms_app_user'@'127.0.0.1';\""
echo "   sudo mysql -e \"FLUSH PRIVILEGES;\""
echo ""
echo "2. Clone or deploy code to /var/www/spms"
echo "3. Copy deploy/env/production.env.example to /var/www/spms/.env and populate secrets"
echo "4. Run sudo bash deploy/scripts/deploy-app.sh"
echo "----------------------------------------------------------------------"
