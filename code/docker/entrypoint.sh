#!/bin/bash
set -e
echo "=== Scrap POS web entrypoint ==="

# Fix uploads ownership on every start
# (volume mounts override image chown — this keeps photos working after any redeploy)
mkdir -p /var/www/html/uploads
chown -R www-data:www-data /var/www/html/uploads
echo "Uploads ownership: www-data (fixed)"

echo "Starting Apache..."
exec apache2-foreground
