#!/bin/sh
set -e

mkdir -p /var/www/html/storage/app/public/profile-pictures/male \
         /var/www/html/storage/app/public/profile-pictures/female \
         /var/www/html/storage/app/public/team-icons \
         /var/www/html/storage/app/public/project-photos \
         /var/www/html/storage/app/public/users \
         /var/www/html/storage/app/private \
         /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache

if [ -d "/var/www/html/public/assets/images" ]; then
    cp -rn /var/www/html/public/assets/images/* /var/www/html/storage/app/public/ 2>/dev/null || true
fi

php /var/www/html/artisan storage:link --force || true
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache || true

exec "$@"

