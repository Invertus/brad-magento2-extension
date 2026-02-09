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
 * Resolver for is_in_stock field on ProductInterface
 * Returns boolean indicating product stock availability
 *
 * Uses batch loading to avoid N+1 queries:
 * - All product IDs are collected via addToQueue()
 * - Single batch query is executed when values are resolved
 */
class IsInStock implements ResolverInterface
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

        // Add to batch queue
        $this->stockDataLoader->addToQueue($productId);

        // Return deferred value - will be resolved after all products are queued
        return $this->valueFactory->create(function () use ($productId) {
            return $this->stockDataLoader->getIsInStock($productId);
        });
    }
}
