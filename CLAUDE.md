# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

BradSearch Magento 2 Extension (`bradsearch/magento-extension`) - four independent Magento 2 modules that integrate BradSearch API with Magento's GraphQL layer. Installed into a Magento project at `app/code/BradSearch/` via Composer mapping.

## Modules

- **SearchGraphQl** - Core module. Intercepts Magento's `products` GraphQL resolver via plugin to route keyword searches through BradSearch API instead of ElasticSuite. Includes real-time product sync via cron that reads ElasticSuite's `catalogsearch_fulltext_cl` changelog table and notifies BradSearch via webhook.
- **ProductFeatures** - Extends GraphQL product type with additional attributes: `is_in_stock`, `allows_backorders`, `full_url`, `image_optimized`, and dynamic product features.
- **Autocomplete** - Configuration-only module exposing BradSearch autocomplete widget settings (script URL, public key, config JSON) via GraphQL StoreConfig.
- **Analytics** - Observes `checkout_submit_all_after` and queues the order-paid event in `bradsearch_order_outbox`; the `bradsearch_send_order_events` cron sends it every minute and retries failures.

## Commands

Tests run inside a Magento Docker container:

```bash
# All SearchGraphQl tests
vendor/bin/phpunit app/code/BradSearch/SearchGraphQl/Test/Unit/

# Single test class
vendor/bin/phpunit app/code/BradSearch/SearchGraphQl/Test/Unit/Plugin/CatalogGraphQl/Model/Resolver/ProductsTest.php

# ProductFeatures tests
vendor/bin/phpunit app/code/BradSearch/ProductFeatures/Test/Unit/
```

After modifying code:
```bash
bin/magento setup:di:compile        # After changing constructor dependencies or plugins
bin/magento setup:upgrade           # After changing db_schema.xml
bin/magento cache:clean config      # After changing schema.graphqls
```

Deployment: `./deploy-bradsearch.sh` (use `--dry-run` first, `--compile` for DI compilation).

## Architecture

**Request flow**: GraphQL `products` query → `Products` plugin checks if operation is `ProductSearch` and BradSearch is enabled → `ProductsProvider` calls `Client` → `ResponseMapper` transforms API response to Magento GraphQL format.

**Sync flow**: Cron runs every minute → reads `catalogsearch_fulltext_cl` changelog MAX version → compares with `bradsearch_sync_state` table → if behind, reads changed product IDs → `ChangeFilter` keeps only the products whose indexed data changed (a hash per product and store view, compared with `bradsearch_product_state`) → sends them by webhook to the BradSearch backend. Hashes are stored only after a 2xx. If the filter fails, every changelog product is sent.

**Key files in SearchGraphQl**:
- `Plugin/CatalogGraphQl/Model/Resolver/Products.php` - Main intercept point (decides BradSearch vs ElasticSuite)
- `Model/Api/Client.php` - HTTP client for BradSearch API
- `Model/Api/ResponseMapper.php` - API response → GraphQL format
- `Model/Sync/ChangelogReader.php` - Reads ElasticSuite changelog
- `Model/Sync/ChangeFilter.php` - Drops changelog products whose indexed data did not change (`ProductStateReader` reads the rows, `ProductStateHasher` hashes them)
- `Cron/SyncChangedProducts.php` - Sync orchestration
- `etc/di.xml` - 125-line DI config, virtual types for dedicated logger

**Logging**: All modules log to `var/log/bradsearch.log` via a dedicated virtual type logger configured in `di.xml`.

**Admin config**: Stores → Configuration → BradSearch (API URLs, tokens, enable/disable, sync webhook URL, autocomplete settings, analytics settings).

## Important Notes

- Modules have no inter-dependencies; each can be deployed independently
- PHP compatibility: 7.4, 8.1, or 8.2
- Test files and `Test/` directories are excluded from deployment automatically
- The `bradsearch_sync_state`, `bradsearch_product_state` and `bradsearch_held_product` tables (SearchGraphQl) and the `bradsearch_order_outbox` table (Analytics) are created via `db_schema.xml`
- A new attribute that the sync always reads (`BradProducts::COLLECTION_ATTRIBUTES`, `ProductDataLoader::WHITELIST_ATTRIBUTES`) must also go into `ChangeFilter`'s `attributeCodes` in `SearchGraphQl/etc/di.xml`; `ChangeFilterConfigTest` fails otherwise
- After installing the release that adds `bradsearch_product_state`, the first wave sends every changelog product once, because no hashes are stored yet
- A filtered push carries `X-BradSearch-Changes-Only: 1`; the backend then does not drop the same ids a minute later as a duplicate. An unfiltered push (filter failed) sends `0`
- `bradProducts` reads categories from the scheduled category index (`catalog_category_product_index_store*`), which Magento's index cron job updates after the sync job has run. So the sync holds a product while `catalog_product_category_cl` has rows for it past that view's `mview_state.version_id` (`CategoryIndexState`), keeps it in `bradsearch_held_product` and sends it once the index has caught up. A product held over 15 minutes logs a warning on every run. If the check fails, nothing is held
