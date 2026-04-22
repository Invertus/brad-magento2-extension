# Implementing a custom `PriceCalculatorInterface`

The `bradProducts` GraphQL query exposes a `calculated_price` field on every
product it returns. The value of that field is produced by whichever
implementation of
[`BradSearch\SearchGraphQl\Api\PriceCalculatorInterface`](Api/PriceCalculatorInterface.php)
is bound in DI.

This module ships a default implementation —
[`VanillaMagentoPriceCalculator`](Model/Price/VanillaMagentoPriceCalculator.php)
— that mirrors Magento's stock `price_range` output. If your store has any
customisation to storefront pricing (coefficients, special_price used as an
absolute override, country-specific VAT overrides, dynamic markups, …), the
default will **not** match what customers see, and BradSearch's index will
drift from the storefront.

This document explains how to write a bridge module that plugs your store's
real pricing logic into BradSearch.

---

## The contract

```php
namespace BradSearch\SearchGraphQl\Api;

interface PriceCalculatorInterface
{
    public function calculate(
        \Magento\Catalog\Api\Data\ProductInterface $product,
        int $storeId
    ): ?\BradSearch\SearchGraphQl\Api\Data\CalculatedPriceInterface;
}
```

Return `null` only when a product should be synced with no price (rare;
typically only for disabled or archived products). Otherwise return a
`CalculatedPriceInterface` — a wrapper around two `PriceTupleInterface`
buckets (`minimum_price`, `maximum_price`), each carrying three
`MoneyInterface` values (`regular_price`, `final_price`,
`final_price_excl_tax`).

All interfaces live under
[`SearchGraphQl/Api/`](Api/) and
[`SearchGraphQl/Api/Data/`](Api/Data/). Plain value-object implementations
live under [`SearchGraphQl/Model/Data/`](Model/Data/) — `new` them directly;
no factories required.

---

## Implementation checklist

For a client whose storefront price for product `249603` in store
`lt_store` is `363.90 EUR`, your goal is: make
`bradProducts(filter: {entity_id: {eq: 249603}})` return
`calculated_price.minimum_price.final_price.value == 363.90` under the same
store header.

A bridge module needs four files. By convention the module lives **inside
the client's Magento project**, not inside the BradSearch extension:

```
<your-magento-project>/app/code/<Vendor>/BradSearchPrice/
├── registration.php
├── etc/
│   ├── module.xml
│   └── di.xml
└── Model/
    └── YourPriceCalculator.php
```

### 1. `registration.php`

```php
<?php
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    '<Vendor>_BradSearchPrice',
    __DIR__
);
```

### 2. `etc/module.xml`

Declare both dependencies in `<sequence>` so Magento bootstraps them before
this module's DI is parsed.

Do **not** add `setup_version` — a DI-only bridge has no schema or data to
upgrade. Declaring it forces Magento to track a `setup_module` row and
error out on every request until `setup:upgrade` runs, which breaks dev
environments where the RDBMS version guard blocks `setup:upgrade`. Add
`setup_version` only if you later introduce `db_schema.xml` or an
`Install/Upgrade{Schema,Data}` class.

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Module/etc/module.xsd">
    <module name="<Vendor>_BradSearchPrice">
        <sequence>
            <module name="BradSearch_SearchGraphQl"/>
            <!-- Add whatever pricing module you're bridging to here -->
            <module name="<Vendor>_YourPricingModule"/>
        </sequence>
    </module>
</config>
```

### 3. `Model/YourPriceCalculator.php`

Implement the contract. Reuse whatever helper already produces your
storefront prices — that's the whole point of the bridge pattern:

```php
<?php
declare(strict_types=1);

namespace <Vendor>\BradSearchPrice\Model;

use BradSearch\SearchGraphQl\Api\Data\CalculatedPriceInterface;
use BradSearch\SearchGraphQl\Api\PriceCalculatorInterface;
use BradSearch\SearchGraphQl\Model\Data\CalculatedPrice;
use BradSearch\SearchGraphQl\Model\Data\Money;
use BradSearch\SearchGraphQl\Model\Data\PriceTuple;
use Magento\Catalog\Api\Data\ProductInterface;

class YourPriceCalculator implements PriceCalculatorInterface
{
    // Inject your project's existing storefront-price helper here.
    public function __construct(
        private readonly \<Vendor>\YourPricingModule\YourPriceHelper $helper,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {}

    public function calculate(ProductInterface $product, int $storeId): ?CalculatedPriceInterface
    {
        // Call whatever your storefront calls to get final price + tax split.
        // The return shape below is illustrative — map from your helper's output.
        $raw = $this->helper->getPriceFor($product, $storeId);

        $currency = (string) $this->storeManager->getStore($storeId)->getCurrentCurrencyCode();

        $min = new PriceTuple(
            new Money((float) $raw['regular_price'],        $currency),
            new Money((float) $raw['final_price'],          $currency),
            new Money((float) $raw['final_price_excl_tax'], $currency)
        );
        // If min == max, reuse the same tuple.
        return new CalculatedPrice($min, $min);
    }
}
```

### 4. `etc/di.xml`

Override the default preference:

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <preference for="BradSearch\SearchGraphQl\Api\PriceCalculatorInterface"
                type="<Vendor>\BradSearchPrice\Model\YourPriceCalculator"/>
</config>
```

### Enable, compile, verify

```bash
bin/magento module:enable <Vendor>_BradSearchPrice
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:clean config
```

Then run the query below with a product that has known storefront pricing
and confirm `calculated_price` matches what the storefront shows.

---

## Verifying your implementation

### Query

```graphql
query {
  bradProducts(filter: {entity_id: {eq: 249603}}, pageSize: 1, currentPage: 0) {
    items {
      sku
      type_id
      calculated_price {
        minimum_price {
          regular_price        { value currency }
          final_price          { value currency }
          final_price_excl_tax { value currency }
        }
      }
    }
  }
}
```

Headers: `Store: <your_store_code>`, `X-BradSearch-Api-Key: <your_key>`.

### Cross-check against the storefront

Pick any GraphQL query your storefront already uses to render product price
— e.g. `products(filter: …)` with `price_range`, or a module-specific query
like `productPrice`. Fire both queries side-by-side for the same SKU / store
/ country. Every value must match.

If `calculated_price` differs from the storefront, your implementation is
not reusing the same code path as the storefront — that's the leak that
caused you to reach for this document in the first place.

### Loud-failure canary

Temporarily disable the bridge module:

```bash
bin/magento module:disable <Vendor>_BradSearchPrice
bin/magento setup:di:compile
```

Re-run the query. `calculated_price` should revert to stock Magento values
(likely wrong for your storefront). That divergence is the signal to check
bridge-module deployment. Re-enable before you commit.

---

## Design notes

- **Why a new field, not an override of `price_range`.** Overriding
  `price_range` would require plugging into whichever resolver your project
  has bound to it (Magento's own, or a module-specific preference). If that
  class path changes, the plugin silently stops firing. A dedicated field
  with a DI-preference seam fails loudly: either your implementation exists
  and is injected, or DI compilation errors.
- **Why DTOs instead of raw arrays.** PHP type errors on construct or
  return catch contract drift at deploy time, not in production pricing.
  The DTOs are plain readonly-ish classes under
  [`SearchGraphQl/Model/Data/`](Model/Data/) — cheap to construct, no
  factories, no ObjectManager indirection.
- **Why country is not an argument.** The bridge derives country from the
  incoming `Store` header (via
  `general/country/default` scoped to the store). Keeping that detail out
  of the contract means the BradSearch sync consumer never has to know or
  care about country mapping.
- **What to do for multi-country sync.** If your backend sync needs to
  quote per-country prices that differ from the store's default country,
  expose a separate resolver or pass country as an argument on the
  `bradProducts` query schema. The contract itself doesn't need to change;
  wrap your helper with a per-country facade inside the bridge module.

---

## Reference implementation

The Verkter project ships
[`Magenmagic_BradSearchPrice`](../../verkter/app/code/Magenmagic/BradSearchPrice/)
(outside this repo). It reuses
`Magenmagic\ProductPrices\Helper\Price::getCalculatePrice($country, $product, $storeId)`
— the same helper that powers the storefront's `productPrice` GraphQL
query. That's why the cross-check against `productPrice` is byte-identical.
Mirror this shape for your own project.
