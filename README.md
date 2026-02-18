# BradSearch Magento 2 Extension

[![License: Apache-2.0](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](https://opensource.org/licenses/Apache-2.0)

Magento 2 modules for integrating BradSearch with your store's GraphQL layer.

## Requirements

- Magento 2.4.x
- PHP 7.4, 8.1, 8.2, 8.3, or 8.4
- Composer 2.x

## Installation

```bash
composer require bradsearch/magento-extension:^1.0
bin/magento module:enable BradSearch_Analytics BradSearch_Autocomplete BradSearch_ProductFeatures BradSearch_SearchGraphQl
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:clean
```

## Configuration

**Stores → Configuration → BradSearch** in Magento Admin.

You'll need API credentials from your BradSearch account.

## Updating

```bash
composer update bradsearch/magento-extension
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:clean
```

## Uninstalling

```bash
bin/magento module:disable BradSearch_Analytics BradSearch_Autocomplete BradSearch_ProductFeatures BradSearch_SearchGraphQl
bin/magento setup:upgrade
composer remove bradsearch/magento-extension
```

## License

Apache License 2.0 - see [LICENSE](LICENSE).
