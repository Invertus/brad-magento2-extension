<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Resolver;

use BradSearch\SearchGraphQl\Model\Api\Auth\ApiKeyValidator;
use BradSearch\SearchGraphQl\Model\PopularityExtractor;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

class SortPopularitySalesResolver implements ResolverInterface
{
    /**
     * @var ApiKeyValidator
     */
    private ApiKeyValidator $apiKeyValidator;

    /**
     * @var PopularityExtractor
     */
    private PopularityExtractor $popularityExtractor;

    /**
     * @param ApiKeyValidator $apiKeyValidator
     * @param PopularityExtractor $popularityExtractor
     */
    public function __construct(
        ApiKeyValidator $apiKeyValidator,
        PopularityExtractor $popularityExtractor
    ) {
        $this->apiKeyValidator = $apiKeyValidator;
        $this->popularityExtractor = $popularityExtractor;
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
        $storeId = (int) $context->getExtensionAttributes()->getStore()->getId();
        if (!$this->apiKeyValidator->isValidRequest($storeId)) {
            return null;
        }

        if (isset($value['sort_popularity_sales'])) {
            return (int) $value['sort_popularity_sales'];
        }

        if (isset($value['model'])) {
            /** @var \Magento\Catalog\Model\Product $product */
            $product = $value['model'];
            return $this->popularityExtractor->getSortPopularitySales($product->getData('mm_popularity'));
        }

        return null;
    }
}
