<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\ProductFeatures\Model\Resolver;

use BradSearch\ProductFeatures\Model\StockDataLoader;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ValueFactory;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Resolver for allows_backorders field on ProductInterface
 * Returns boolean indicating if product can be ordered when out of stock
 *
 * Magento backorders values:
 * - 0 = No Backorders
 * - 1 = Allow Qty Below 0
 * - 2 = Allow Qty Below 0 and Notify Customer
 *
 * Uses batch loading to avoid N+1 queries (shared with IsInStock resolver)
 */
class AllowsBackorders implements ResolverInterface
{
    /**
     * @var StockDataLoader
     */
    private StockDataLoader $stockDataLoader;

    /**
     * @var ValueFactory
     */
    private ValueFactory $valueFactory;

    /**
     * @param StockDataLoader $stockDataLoader
     * @param ValueFactory $valueFactory
     */
    public function __construct(
        StockDataLoader $stockDataLoader,
        ValueFactory $valueFactory
    ) {
        $this->stockDataLoader = $stockDataLoader;
        $this->valueFactory = $valueFactory;
    }

    /**
     * @inheritdoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        if (!isset($value['model'])) {
            return null;
        }

        $product = $value['model'];
        $productId = (int)$product->getId();

        // Add to batch queue (may already be queued by IsInStock)
        $this->stockDataLoader->addToQueue($productId);

        // Return deferred value - will be resolved after all products are queued
        return $this->valueFactory->create(function () use ($productId) {
            return $this->stockDataLoader->getAllowsBackorders($productId);
        });
    }
}
