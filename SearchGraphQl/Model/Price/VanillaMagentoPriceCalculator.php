<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Price;

use BradSearch\SearchGraphQl\Api\Data\CalculatedPriceInterface;
use BradSearch\SearchGraphQl\Api\PriceCalculatorInterface;
use BradSearch\SearchGraphQl\Model\Data\CalculatedPrice;
use BradSearch\SearchGraphQl\Model\Data\Money;
use BradSearch\SearchGraphQl\Model\Data\PriceTuple;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogGraphQl\Model\Resolver\Product\Price\ProviderPool;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Default implementation that mirrors vanilla Magento's price_range output.
 *
 * Uses the same CatalogGraphQl ProviderPool the core price_range resolver
 * uses, so BradSearch's `calculated_price` matches vanilla storefront
 * pricing on any install without client-specific overrides.
 *
 * Clients with custom pricing logic should override via DI <preference> in
 * their own bridge module.
 */
class VanillaMagentoPriceCalculator implements PriceCalculatorInterface
{
    private ProviderPool $priceProviderPool;
    private StoreManagerInterface $storeManager;

    public function __construct(ProviderPool $priceProviderPool, StoreManagerInterface $storeManager)
    {
        $this->priceProviderPool = $priceProviderPool;
        $this->storeManager = $storeManager;
    }

    public function calculate(ProductInterface $product, int $storeId): ?CalculatedPriceInterface
    {
        $store = $this->storeManager->getStore($storeId);
        $currency = (string) $store->getCurrentCurrencyCode();
        $provider = $this->priceProviderPool->getProviderByProductType((string) $product->getTypeId());

        $minFinal = $provider->getMinimalFinalPrice($product);
        $maxFinal = $provider->getMaximalFinalPrice($product);

        $min = new PriceTuple(
            new Money((float) $provider->getMinimalRegularPrice($product)->getValue(), $currency),
            new Money((float) $minFinal->getValue(), $currency),
            new Money((float) $minFinal->getValue('tax'), $currency)
        );

        $max = new PriceTuple(
            new Money((float) $provider->getMaximalRegularPrice($product)->getValue(), $currency),
            new Money((float) $maxFinal->getValue(), $currency),
            new Money((float) $maxFinal->getValue('tax'), $currency)
        );

        return new CalculatedPrice($min, $max);
    }
}
