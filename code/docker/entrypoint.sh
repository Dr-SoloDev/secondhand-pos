#!/bin/bash
set -e
echo "=== Scrap POS web entrypoint ==="

# Fix uploads ownership on every start
# (volume mounts override image chown — this keeps photos working after any redeploy)
mkdir -p /var/www/html/uploads
chown -R www-data:www-data /var/www/html/uploads
echo "Uploads ownership: www-data (fixed)"

a2enmod ssl headers rewrite >/dev/null 2>&1

# Self-signed SSL cert (HTTPS for camera) — create if missing
mkdir -p /etc/apache2/ssl
if [ ! -f /etc/apache2/ssl/server.crt ]; then
  openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
    -keyout /etc/apache2/ssl/server.key \
    -out /etc/apache2/ssl/server.crt \
    -subj "/CN=localhost" >/dev/null 2>&1
  echo "SSL cert: self-signed generated"
fi

echo "Starting Apache..."
exec apache2-foreground
