#!/bin/sh
set -eu
cd /var/www/html
php /opt/campus-e2/check-runtime.php
# Runtime-only package discovery writes inside the container's temporary cache.
php artisan package:discover --no-interaction
# Local preview server. No worker, scheduler, migrations or key generation.
exec php artisan serve --host=0.0.0.0 --port=8080 --no-reload
