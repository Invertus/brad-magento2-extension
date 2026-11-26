#!/usr/bin/env bash
# Runs inside the magento container: installs Magento with the extension, seeds a few products and
# points the extension at WireMock. Install steps are skipped when done; a re-run applies the checkout's
# current module files with setup:upgrade.
set -euo pipefail

MAGENTO_ROOT=/var/www/html
PROJECT=/extension/Test/Integration/magento
cd "$MAGENTO_ROOT"

step() { echo "==> $*"; }

# magento/project-community-edition 2.4.8-p5 with the locked versions: packages from the Mage-OS mirror
# (repo.magento.com needs account keys), the extension from the mounted checkout. See README.md to update.
if [ ! -f vendor/autoload.php ] || ! cmp -s composer.lock "$PROJECT/composer.lock"; then
    step "composer install (Magento 2.4.8-p5 from the Mage-OS mirror)"
    cp "$PROJECT/composer.json" "$PROJECT/composer.lock" .
    composer install --no-interaction --no-progress --prefer-dist
fi

if ! mysql -hdb -umagento -pmagento magento -e 'SELECT 1 FROM core_config_data LIMIT 1' >/dev/null 2>&1; then
    step "setup:install"
    rm -f app/etc/env.php
    bin/magento setup:install --no-interaction \
        --base-url=http://localhost:8080/ \
        --db-host=db --db-name=magento --db-user=magento --db-password=magento \
        --admin-firstname=Admin --admin-lastname=User --admin-email=admin@example.com \
        --admin-user=admin --admin-password=admin12345 \
        --language=en_US --currency=USD --timezone=UTC --use-rewrites=1 \
        --search-engine=opensearch --opensearch-host=opensearch --opensearch-port=9200 \
        --cleanup-database \
        --disable-modules=Magento_TwoFactorAuth,Magento_AdminAdobeImsTwoFactorAuth
    # Developer mode puts the exception into GraphQL errors, which makes a failing test readable.
    bin/magento deploy:mode:set developer --skip-compilation

    step "BradSearch config"
    # Plain values; the encrypted fields (keys, token) are encrypted by their backend models on save.
    bin/magento config:set bradsearch_search/private_endpoint/enabled 1
    bin/magento config:set bradsearch_search/private_endpoint/api_key integration-private-key
    bin/magento config:set bradsearch_search/general/enabled 1
    bin/magento config:set bradsearch_search/general/api_url http://wiremock:8080/v1/search
    bin/magento config:set bradsearch_search/general/api_key integration-search-token
    bin/magento config:set bradsearch_search/sync/enabled 1
    bin/magento config:set bradsearch_search/sync/webhook_url http://wiremock:8080/webhook/products
    bin/magento config:set bradsearch_search/sync/secure_token integration-webhook-token

    step "seed products"
    php /extension/Test/Integration/bin/seed-products.php
    php /extension/Test/Integration/bin/seed-contract-product.php

    step "indexers: update by schedule, full reindex"
    # The sync reads the changelogs, which exist only for indexers on schedule (as in production).
    bin/magento indexer:set-mode schedule
    bin/magento indexer:reindex
else
    # Already installed (a local re-run): apply the checkout's current schema, DI, cron and GraphQL files.
    step "setup:upgrade"
    bin/magento setup:upgrade --keep-generated --no-interaction

    # The contract seed is newer than some local installs; it is safe to run again.
    step "seed contract product"
    php /extension/Test/Integration/bin/seed-contract-product.php
    bin/magento indexer:reindex catalog_category_product catalog_product_category catalog_product_attribute
fi

bin/magento cache:flush

step "Magento is ready"
