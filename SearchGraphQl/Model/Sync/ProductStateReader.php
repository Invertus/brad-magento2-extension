<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Sync;

use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;

/**
 * Reads, for a batch of products, the catalog rows that decide what BradSearch indexes.
 *
 * Covers every table that feeds the catalogsearch_fulltext changelog and reaches the
 * bradProducts response: the entity row, indexed EAV values, categories, websites, configurable
 * children, bundle selections and catalog rule prices. Stock does not feed that changelog, but it
 * is read too, so a stock change still goes out with the next changelog entry for the product.
 *
 * bradProducts reads categories from the scheduled category index, which can lag the assignment.
 * Both are read, so a product sent before that index caught up is sent again with its next
 * changelog entry.
 * Values are read as MD5 so long texts never reach PHP.
 */
class ProductStateReader
{
    private const EAV_TYPES = ['varchar', 'int', 'decimal', 'text', 'datetime'];

    /**
     * bradProducts is called without a customer token, so prices are the guest group's.
     */
    private const GUEST_CUSTOMER_GROUP = 0;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resource;

    /**
     * @var TableMaintainer
     */
    private TableMaintainer $categoryIndexTables;

    /**
     * @var array<string, bool>
     */
    private array $tableExists = [];

    /**
     * @param ResourceConnection $resource
     * @param TableMaintainer $categoryIndexTables
     */
    public function __construct(ResourceConnection $resource, TableMaintainer $categoryIndexTables)
    {
        $this->resource = $resource;
        $this->categoryIndexTables = $categoryIndexTables;
    }

    /**
     * Product attributes BradSearch reads: searchable or filterable ones plus the given codes.
     *
     * @param string[] $attributeCodes
     * @return array<string, int[]> backend type => attribute ids
     */
    public function getAttributeIdsByType(array $attributeCodes): array
    {
        $connection = $this->connection();
        $condition = 'cea.is_searchable = 1 OR cea.is_filterable > 0 OR cea.is_filterable_in_search = 1';
        if (!empty($attributeCodes)) {
            $condition .= ' OR ' . $connection->quoteInto('ea.attribute_code IN (?)', array_values($attributeCodes));
        }

        $select = $connection->select()
            ->from(['ea' => $this->table('eav_attribute')], ['attribute_id', 'backend_type'])
            ->joinInner(['cea' => $this->table('catalog_eav_attribute')], 'cea.attribute_id = ea.attribute_id', [])
            ->joinInner(['et' => $this->table('eav_entity_type')], 'et.entity_type_id = ea.entity_type_id', [])
            ->where('et.entity_type_code = ?', 'catalog_product')
            ->where('ea.backend_type IN (?)', self::EAV_TYPES)
            ->where($condition);

        $byType = [];
        foreach ($connection->fetchAll($select) as $row) {
            $byType[(string)$row['backend_type']][] = (int)$row['attribute_id'];
        }

        return $byType;
    }

    /**
     * Ids of the given product attribute codes.
     *
     * @param string[] $attributeCodes
     * @return int[]
     */
    public function getAttributeIds(array $attributeCodes): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from(['ea' => $this->table('eav_attribute')], ['attribute_id'])
            ->joinInner(['et' => $this->table('eav_entity_type')], 'et.entity_type_id = ea.entity_type_id', [])
            ->where('et.entity_type_code = ?', 'catalog_product')
            ->where('ea.attribute_code IN (?)', array_values($attributeCodes));

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Read the indexed rows of a batch of products.
     *
     * @param int[] $productIds
     * @param int[] $storeIds Store views to read EAV values for, including 0 (default scope)
     * @param array<string, int[]> $attributeIdsByType Output of getAttributeIdsByType()
     * @param array<int, string[]> $ruleDates website id => dates (Y-m-d) to read catalog rule prices for
     * @return array<string, array<int, mixed>> part => product id => rows
     */
    public function read(array $productIds, array $storeIds, array $attributeIdsByType, array $ruleDates): array
    {
        return [
            'entity' => $this->readEntities($productIds),
            'attributes' => $this->readAttributes($productIds, $storeIds, $attributeIdsByType),
            'categories' => $this->readList('catalog_category_product', 'product_id', 'category_id', $productIds),
            'categoryIndex' => $this->readCategoryIndex($productIds, $storeIds),
            'websites' => $this->readList('catalog_product_website', 'product_id', 'website_id', $productIds),
            'stock' => $this->readStock($productIds),
            'children' => $this->readList('catalog_product_super_link', 'parent_id', 'product_id', $productIds),
            'bundle' => $this->readBundleSelections($productIds),
            'rules' => $this->readRulePrices($productIds, $ruleDates),
            'specialDates' => $this->readSpecialPriceDates($productIds, $storeIds),
        ];
    }

    /**
     * Raw special price dates: whether a special price applies depends on today, which a value hash
     * cannot show.
     *
     * @param int[] $productIds
     * @param int[] $storeIds
     * @return array<int, array<string, array<int, string|null>>> product id => from|to => store id => value
     */
    private function readSpecialPriceDates(array $productIds, array $storeIds): array
    {
        $connection = $this->connection();
        $codes = $connection->fetchPairs(
            $connection->select()
                ->from(['ea' => $this->table('eav_attribute')], ['attribute_id', 'attribute_code'])
                ->joinInner(['et' => $this->table('eav_entity_type')], 'et.entity_type_id = ea.entity_type_id', [])
                ->where('et.entity_type_code = ?', 'catalog_product')
                ->where('ea.attribute_code IN (?)', ['special_from_date', 'special_to_date'])
        );
        if (empty($codes)) {
            return [];
        }

        $select = $connection->select()
            ->from($this->table('catalog_product_entity_datetime'), ['entity_id', 'attribute_id', 'store_id', 'value'])
            ->where('entity_id IN (?)', $productIds)
            ->where('attribute_id IN (?)', array_keys($codes))
            ->where('store_id IN (?)', $storeIds);

        $dates = [];
        foreach ($connection->fetchAll($select) as $row) {
            $part = $codes[$row['attribute_id']] === 'special_from_date' ? 'from' : 'to';
            $dates[(int)$row['entity_id']][$part][(int)$row['store_id']] =
                $row['value'] === null ? null : (string)$row['value'];
        }

        return $dates;
    }

    /**
     * @param int[] $productIds
     * @return array<int, array<string, string>>
     */
    private function readEntities(array $productIds): array
    {
        $select = $this->connection()->select()
            ->from($this->table('catalog_product_entity'), [
                'entity_id',
                'sku' => new Expression('MD5(sku)'),
                'type_id',
                'attribute_set_id',
            ])
            ->where('entity_id IN (?)', $productIds);

        $entities = [];
        foreach ($this->connection()->fetchAll($select) as $row) {
            $entities[(int)$row['entity_id']] = [
                'sku' => (string)$row['sku'],
                'type_id' => (string)$row['type_id'],
                'attribute_set_id' => (string)$row['attribute_set_id'],
            ];
        }

        return $entities;
    }

    /**
     * @param int[] $productIds
     * @param int[] $storeIds
     * @param array<string, int[]> $attributeIdsByType
     * @return array<int, array<int, array<int, string|null>>> product id => attribute id => store id => value hash
     */
    private function readAttributes(array $productIds, array $storeIds, array $attributeIdsByType): array
    {
        $values = [];
        foreach ($attributeIdsByType as $type => $attributeIds) {
            if (empty($attributeIds) || !in_array($type, self::EAV_TYPES, true)) {
                continue;
            }

            $select = $this->connection()->select()
                ->from($this->table('catalog_product_entity_' . $type), [
                    'entity_id',
                    'attribute_id',
                    'store_id',
                    'value' => new Expression('MD5(value)'),
                ])
                ->where('entity_id IN (?)', $productIds)
                ->where('attribute_id IN (?)', $attributeIds)
                ->where('store_id IN (?)', $storeIds);

            foreach ($this->connection()->fetchAll($select) as $row) {
                $values[(int)$row['entity_id']][(int)$row['attribute_id']][(int)$row['store_id']] =
                    $row['value'] === null ? null : (string)$row['value'];
            }
        }

        return $values;
    }

    /**
     * @param string $table
     * @param string $productColumn
     * @param string $valueColumn
     * @param int[] $productIds
     * @return array<int, string[]>
     */
    private function readList(string $table, string $productColumn, string $valueColumn, array $productIds): array
    {
        if (!$this->exists($table)) {
            return [];
        }

        $select = $this->connection()->select()
            ->from($this->table($table), [$productColumn, $valueColumn])
            ->where($productColumn . ' IN (?)', $productIds);

        $lists = [];
        foreach ($this->connection()->fetchAll($select) as $row) {
            $lists[(int)$row[$productColumn]][] = (string)$row[$valueColumn];
        }

        return $lists;
    }

    /**
     * Same rows as ProductFeatures StockDataLoader: backorders and the default stock's status.
     *
     * @param int[] $productIds
     * @return array<int, string[]>
     */
    private function readStock(array $productIds): array
    {
        if (!$this->exists('cataloginventory_stock_item')) {
            return [];
        }

        $select = $this->connection()->select()
            ->from(['item' => $this->table('cataloginventory_stock_item')], ['product_id', 'backorders'])
            ->joinLeft(
                ['status' => $this->table('cataloginventory_stock_status')],
                'item.product_id = status.product_id AND status.stock_id = 1',
                ['stock_status']
            )
            ->where('item.product_id IN (?)', $productIds);

        $stock = [];
        foreach ($this->connection()->fetchAll($select) as $row) {
            $stock[(int)$row['product_id']][] = $row['backorders'] . '|' . $row['stock_status'];
        }

        return $stock;
    }

    /**
     * Category ids each store view's category index holds, as bradProducts reads them.
     *
     * @param int[] $productIds
     * @param int[] $storeIds
     * @return array<int, array<int, string[]>> product id => store id => category ids
     */
    private function readCategoryIndex(array $productIds, array $storeIds): array
    {
        $categories = [];
        foreach ($storeIds as $storeId) {
            if ($storeId === 0) {
                continue;
            }

            $table = $this->categoryIndexTables->getMainTable($storeId);
            if (!$this->connection()->isTableExists($table)) {
                continue;
            }

            $select = $this->connection()->select()
                ->from($table, ['product_id', 'category_id'])
                ->where('product_id IN (?)', $productIds);

            foreach ($this->connection()->fetchAll($select) as $row) {
                $categories[(int)$row['product_id']][$storeId][] = (string)$row['category_id'];
            }
        }

        return $categories;
    }

    /**
     * @param int[] $productIds
     * @return array<int, string[]>
     */
    private function readBundleSelections(array $productIds): array
    {
        if (!$this->exists('catalog_product_bundle_selection')) {
            return [];
        }

        $select = $this->connection()->select()
            ->from($this->table('catalog_product_bundle_selection'), [
                'parent_product_id',
                'option_id',
                'product_id',
                'selection_qty',
                'selection_price_type',
                'selection_price_value',
            ])
            ->where('parent_product_id IN (?)', $productIds);

        $selections = [];
        foreach ($this->connection()->fetchAll($select) as $row) {
            $selections[(int)$row['parent_product_id']][] = implode('|', [
                $row['option_id'],
                $row['product_id'],
                $row['selection_qty'],
                $row['selection_price_type'],
                $row['selection_price_value'],
            ]);
        }

        return $selections;
    }

    /**
     * @param int[] $productIds
     * @param array<int, string[]> $ruleDates
     * @return array<int, array<int, array<string, string>>> product id => website id => date => price
     */
    private function readRulePrices(array $productIds, array $ruleDates): array
    {
        if (empty($ruleDates) || !$this->exists('catalogrule_product_price')) {
            return [];
        }

        $dates = array_values(array_unique(array_merge(...array_values($ruleDates))));
        $select = $this->connection()->select()
            ->from($this->table('catalogrule_product_price'), ['product_id', 'website_id', 'rule_date', 'rule_price'])
            ->where('product_id IN (?)', $productIds)
            ->where('customer_group_id = ?', self::GUEST_CUSTOMER_GROUP)
            ->where('website_id IN (?)', array_keys($ruleDates))
            ->where('rule_date IN (?)', $dates);

        $prices = [];
        foreach ($this->connection()->fetchAll($select) as $row) {
            $prices[(int)$row['product_id']][(int)$row['website_id']][(string)$row['rule_date']] =
                (string)$row['rule_price'];
        }

        return $prices;
    }

    /**
     * @param string $table
     * @return bool
     */
    private function exists(string $table): bool
    {
        if (!isset($this->tableExists[$table])) {
            $this->tableExists[$table] = $this->connection()->isTableExists($this->table($table));
        }

        return $this->tableExists[$table];
    }

    /**
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    /**
     * @param string $table
     * @return string
     */
    private function table(string $table): string
    {
        return $this->resource->getTableName($table);
    }
}
