<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Api;

/**
 * Names extra product attributes whose change must reach BradSearch.
 *
 * Real-time sync sends a product only when the data BradSearch indexes has changed. A module
 * whose PriceCalculatorInterface reads attributes that are neither searchable nor filterable
 * (a weight used for a shipping markup, for example) registers a provider in di.xml, on the
 * attributeProviders argument of BradSearch\SearchGraphQl\Model\Sync\ChangeFilter.
 */
interface ProductHashAttributeProviderInterface
{
    /**
     * @param int $storeId
     * @return string[] Attribute codes
     */
    public function getAttributeCodes(int $storeId): array;
}
