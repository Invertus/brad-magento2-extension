<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\ProductFeatures\Model\Resolver;

use BradSearch\ProductFeatures\Model\AttributeFilter;
use BradSearch\SearchGraphQl\Model\Api\Auth\ApiKeyValidator;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Resolver for bradFeatures query — catalog-level attribute definitions for field mapping generation.
 *
 * Returns all searchable/filterable catalog attributes without requiring product context.
 * Requires valid X-BradSearch-Api-Key header.
 */
class FeatureDefinitions implements ResolverInterface
{
    /**
     * @var ApiKeyValidator
     */
    private ApiKeyValidator $apiKeyValidator;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @param ApiKeyValidator $apiKeyValidator
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        ApiKeyValidator $apiKeyValidator,
        ResourceConnection $resourceConnection
    ) {
        $this->apiKeyValidator = $apiKeyValidator;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        $storeId = (int)$context->getExtensionAttributes()->getStore()->getId();

        if (!$this->apiKeyValidator->isValidRequest($storeId)) {
            throw new GraphQlAuthorizationException(__('Invalid or missing BradSearch API key.'));
        }

        return $this->getFeatureDefinitions($storeId);
    }

    /**
     * Get all searchable/filterable catalog attribute definitions
     *
     * @param int $storeId
     * @return array
     */
    private function getFeatureDefinitions(int $storeId): array
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(
                ['ea' => $connection->getTableName('eav_attribute')],
                ['attribute_code', 'attribute_id', 'frontend_label']
            )
            ->joinInner(
                ['cea' => $connection->getTableName('catalog_eav_attribute')],
                'ea.attribute_id = cea.attribute_id',
                ['is_searchable', 'is_filterable_in_search', 'position']
            )
            ->joinLeft(
                ['eal' => $connection->getTableName('eav_attribute_label')],
                'ea.attribute_id = eal.attribute_id AND eal.store_id = ' . $storeId,
                ['store_label' => 'value']
            )
            ->where('ea.entity_type_id = ?', 4) // catalog_product entity type
            ->where('(cea.is_searchable = 1 OR cea.is_filterable = 1 OR cea.is_filterable_in_search = 1)');

        $result = $connection->fetchAll($select);
        $features = [];

        foreach ($result as $row) {
            $code = $row['attribute_code'];

            if (AttributeFilter::isExcluded($code)) {
                continue;
            }

            $label = $row['store_label'] ?? $row['frontend_label'] ?? AttributeFilter::formatLabel($code);
            $isFilterable = (bool)($row['is_filterable_in_search'] ?? false);

            // Slider-type attributes are not supported by PWA frontend
            if ($isFilterable && str_ends_with($code, '_slider')) {
                $isFilterable = false;
            }

            $features[] = [
                'code' => $code,
                'label' => $label ?: AttributeFilter::formatLabel($code),
                'is_searchable' => (bool)$row['is_searchable'],
                'is_filterable' => $isFilterable,
                'position' => $row['position'] !== null ? (int)$row['position'] : null,
            ];
        }

        return $features;
    }
}
