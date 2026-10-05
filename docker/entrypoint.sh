#!/bin/sh
# Composes the harness (this core + every gateway), installs it when it
# changed, then runs the command: the console by default, "test" for PHPUnit,
# "bare" for the script that uses the packages with no bundle, no container
# and no console, "serve" for the demo page (PHP's own web server, no
# framework), "composer ..." or "sh" as they are.
set -e
php /omnimeet/core/docker/harness/setup.php
cd /harness
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --no-progress
elif [ composer.json -nt composer.lock ]; then
    composer update --no-interaction --no-progress
fi
case "${1:-}" in
    test) shift; exec vendor/bin/phpunit "$@" ;;
    bare) shift; exec php /omnimeet/core/docker/harness/bin/bare "$@" ;;
    serve) echo "The demo: http://localhost:${OMNIMEET_PORT:-8799}/"; exec php -S 0.0.0.0:8799 /omnimeet/core/docker/harness/public/index.php ;;
    composer|sh|php) exec "$@" ;;
    *) exec php /omnimeet/core/docker/harness/bin/omnimeet "$@" ;;
esac
