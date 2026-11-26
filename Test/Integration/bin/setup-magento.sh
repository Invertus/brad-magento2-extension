#!/usr/bin/env bash
# Runs inside the magento container: installs Magento with the extension, seeds a few products and
# points the extension at WireMock. Each step is skipped when already done, so a re-run is quick.
set -euo pipefail

MAGENTO_VERSION="${MAGENTO_VERSION:-2.4.8-p5}"
MAGENTO_ROOT=/var/www/html
cd "$MAGENTO_ROOT"

step() { echo "==> $*"; }

if [ ! -f vendor/autoload.php ]; then
    step "composer: magento/project-community-edition $MAGENTO_VERSION from the Mage-OS mirror"
    composer create-project --no-interaction --no-progress --no-install \
        --repository-url=https://mirror.mage-os.org/ \
        "magento/project-community-edition=$MAGENTO_VERSION" .
    # repo.magento.com needs account keys; the Mage-OS mirror serves the same magento/* packages without.
    # The extension comes from the mounted checkout. Of the project's dev tools only PHPUnit is needed.
    php -r '
        $c = json_decode(file_get_contents("composer.json"), true);
        $c["repositories"] = [
            ["type" => "path", "url" => "/extension", "options" => ["symlink" => true]],
            ["type" => "composer", "url" => "https://mirror.mage-os.org/"],
        ];
        $c["require"]["bradsearch/magento-extension"] = "*@dev";
        $c["require-dev"] = ["phpunit/phpunit" => "^10.5"];
        file_put_contents("composer.json", json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    '
    composer update --no-interaction --no-progress --prefer-dist
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

    step "indexers: update by schedule, full reindex"
    # The sync reads the changelogs, which exist only for indexers on schedule (as in production).
    bin/magento indexer:set-mode schedule
    bin/magento indexer:reindex
    bin/magento cache:flush
fi

step "Magento is ready"
