#!/usr/bin/env bash
# Starts the stack, installs Magento with the extension (skipped when done) and runs the integration tests.
# Arguments go to PHPUnit, e.g. ./run.sh --filter SyncCronTest
set -euo pipefail
cd "$(dirname "$0")"

compose() { docker compose -f docker-compose.yml "$@"; }

compose up -d --wait
compose exec -T magento bash /extension/Test/Integration/bin/setup-magento.sh

# PHP's own web server is enough for GraphQL; Magento ships a router for it.
ready() { compose exec -T magento curl -fs -o /dev/null 'http://localhost:8080/graphql?query=%7BstoreConfig%7Bstore_code%7D%7D'; }
if ! ready; then
    compose exec -T -d magento php -S 0.0.0.0:8080 -t pub phpserver/router.php
    for _ in $(seq 1 30); do
        ready && break
        sleep 1
    done
    if ! ready; then
        echo "web server did not start" >&2
        exit 1
    fi
fi

compose exec -T magento vendor/bin/phpunit -c /extension/Test/Integration/phpunit.xml "$@"
