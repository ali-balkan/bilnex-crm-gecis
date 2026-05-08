#!/bin/sh
set -eu

mkdir -p /var/www/html/apps/crm-web/data
chown -R www-data:www-data /var/www/html/apps/crm-web/data

exec "$@"
