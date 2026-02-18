# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

BradSearch Magento 2 Extension (`bradsearch/magento-extension`) - four independent Magento 2 modules that integrate BradSearch API with Magento's GraphQL layer. Installed into a Magento project at `app/code/BradSearch/` via Composer mapping.

## Modules

- **SearchGraphQl** - Core module. Intercepts Magento's `products` GraphQL resolver via plugin to route keyword searches through BradSearch API instead of ElasticSuite. Includes real-time product sync via cron that reads ElasticSuite's `catalogsearch_fulltext_cl` changelog table and notifies BradSearch via webhook.
- **ProductFeatures** - Extends GraphQL product type with additional attributes: `is_in_stock`, `allows_backorders`, `full_url`, `image_optimized`, and dynamic product features.
- **Autocomplete** - Configuration-only module exposing BradSearch autocomplete widget settings (script URL, public key, config JSON) via GraphQL StoreConfig.
- **Analytics** - Observes `sales_order_place_after` event to notify BradSearch analytics API of completed orders.

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

**Sync flow**: Cron runs every minute → reads `catalogsearch_fulltext_cl` changelog MAX version → compares with `bradsearch_sync_state` table → if behind, reads changed product IDs and sends webhook to BradSearch backend.

**Key files in SearchGraphQl**:
- `Plugin/CatalogGraphQl/Model/Resolver/Products.php` - Main intercept point (decides BradSearch vs ElasticSuite)
- `Model/Api/Client.php` - HTTP client for BradSearch API
- `Model/Api/ResponseMapper.php` - API response → GraphQL format
- `Model/Sync/ChangelogReader.php` - Reads ElasticSuite changelog
- `Cron/SyncChangedProducts.php` - Sync orchestration
- `etc/di.xml` - 125-line DI config, virtual types for dedicated logger

**Logging**: All modules log to `var/log/bradsearch.log` via a dedicated virtual type logger configured in `di.xml`.

**Admin config**: Stores → Configuration → BradSearch (API URLs, tokens, enable/disable, sync webhook URL, autocomplete settings, analytics settings).

## Important Notes

- Modules have no inter-dependencies; each can be deployed independently
- PHP compatibility: 7.4, 8.1, or 8.2
- Test files and `Test/` directories are excluded from deployment automatically
- The `bradsearch_sync_state` database table is created via `db_schema.xml` in SearchGraphQl
