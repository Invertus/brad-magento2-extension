<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\ProductFeatures\Plugin;

use Magento\CatalogGraphQl\Model\Resolver\Products;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Plugin to cap pageSize on products GraphQL query
 *
 * Security measure to prevent abuse of public GraphQL endpoint
 * by limiting maximum products per request
 */
class ProductsQueryPageSizeCap
{
    /**
     * Maximum allowed pageSize for products query
     */
    private const MAX_PAGE_SIZE = 500;

    /**
     * Default pageSize if not specified
     */
    private const DEFAULT_PAGE_SIZE = 20;

    /**
     * Cap pageSize before products query execution
     *
     * @param Products $subject
     * @param Field $field
     * @param mixed $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array
     */
    public function beforeResolve(
        Products $subject,
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ): array {
        // Get pageSize from args, default to DEFAULT_PAGE_SIZE
        $pageSize = (int)($args['pageSize'] ?? self::DEFAULT_PAGE_SIZE);

        // Cap at maximum allowed
        if ($pageSize > self::MAX_PAGE_SIZE) {
            $args['pageSize'] = self::MAX_PAGE_SIZE;
        }

        // Ensure pageSize is at least 1
        if ($pageSize < 1) {
            $args['pageSize'] = self::DEFAULT_PAGE_SIZE;
        }

        return [$field, $context, $info, $value, $args];
    }
}
