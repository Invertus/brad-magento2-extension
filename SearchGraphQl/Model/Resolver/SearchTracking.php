<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Resolves Products.bradsearch_search from what ResponseMapper set on the search result.
 *
 * The field exists so the schema can mark it @cache(cacheable: false): a cached GET response
 * would hand every shopper the same old correlation_id and break the join with the engine log.
 */
class SearchTracking implements ResolverInterface
{
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
        return $value['bradsearch_search'] ?? null;
    }
}
