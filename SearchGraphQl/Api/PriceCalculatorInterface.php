<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Api;

use BradSearch\SearchGraphQl\Api\Data\CalculatedPriceInterface;
use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Contract for computing the effective price BradSearch should index for a
 * product in the context of a store view.
 *
 * The default implementation shipped with this module
 * (BradSearch\SearchGraphQl\Model\Price\VanillaMagentoPriceCalculator) uses
 * Magento's standard CatalogGraphQl ProviderPool. Clients with bespoke
 * pricing logic (coefficients, special_price as absolute override, etc.)
 * should implement this interface in their own bridge module and register
 * a DI <preference>.
 */
interface PriceCalculatorInterface
{
    /**
     * Compute the effective price for $product in the context of $storeId.
     *
     * Return null only for products that must be synced with no price.
     */
    public function calculate(ProductInterface $product, int $storeId): ?CalculatedPriceInterface;
}
