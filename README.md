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

Turn live sync on in every store view your BradSearch application reads. Each store view
sends only the products whose data changed in that store view, so a change seen only in a
store view with live sync off is not sent.

Set the **Stock** indexer to "Update by Schedule" (`bin/magento indexer:set-mode schedule
cataloginventory_stock`). Live sync reads its changelog to send stock changes, and waits for
it before sending a product whose stock changed.

## Extending

- **Custom price logic for the `bradProducts` sync?** Implement
  `BradSearch\SearchGraphQl\Api\PriceCalculatorInterface` in a bridge
  module inside your project. See
  [`SearchGraphQl/PRICE_CALCULATOR.md`](SearchGraphQl/PRICE_CALCULATOR.md)
  for the full walkthrough.

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

## Development

```bash
composer install              # installs captainhook pre-commit + pre-push hooks
composer phpcbf               # auto-fix Magento2 coding standard
composer phpcs                # Magento2 coding standard check
composer phpstan              # static analysis at level 6
composer psalm                # static analysis at error level 4
composer test                 # phpunit unit tests
composer check                # all of the above
```

Pre-commit hooks run `phpcbf` + `phpcs` + `phpstan` on staged PHP files.
Pre-push runs `psalm` + full test suite. CI (GitHub Actions) runs the same
checks across PHP 7.4 / 8.1 / 8.2 / 8.3 on every PR.

## License

Apache License 2.0 - see [LICENSE](LICENSE).
