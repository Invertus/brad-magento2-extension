<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Sync;

/**
 * Turns what ProductStateReader read for one product into one hash per store view.
 *
 * Two hashes are equal only when everything BradSearch indexes for that store view is equal,
 * so a changelog entry whose hash did not move is a rewrite of the same data.
 */
class ProductStateHasher
{
    private const LIST_PARTS = ['categories', 'websites', 'stock', 'children', 'bundle'];

    /**
     * Hash one product's indexed data as one store view sees it.
     *
     * @param array<string, array<int, mixed>> $state Output of ProductStateReader::read()
     * @param int $productId
     * @param int $storeId
     * @param int $websiteId Website of the store view, for catalog rule prices
     * @param string $date Today in the store view's timezone (Y-m-d), for catalog rule prices
     * @param int[] $defaultScopeAttributeIds Attributes whose default value is hashed too, even under a store value
     * @return string
     * @throws \JsonException
     */
    public function hash(
        array $state,
        int $productId,
        int $storeId,
        int $websiteId,
        string $date,
        array $defaultScopeAttributeIds = []
    ): string {
        $values = $state['attributes'][$productId] ?? [];
        $data = [
            'entity' => $state['entity'][$productId] ?? null,
            'attributes' => $this->effectiveAttributes($values, $storeId),
            'defaults' => $this->defaultValues($values, $defaultScopeAttributeIds),
            'rule' => $state['rules'][$productId][$websiteId][$date] ?? null,
            'categoryIndex' => $this->sorted($state['categoryIndex'][$productId][$storeId] ?? []),
            'specialActive' => $state['specialActive'][$productId][$storeId] ?? null,
        ];

        foreach (self::LIST_PARTS as $part) {
            $data[$part] = $this->sorted($state[$part][$productId] ?? []);
        }

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<int, string|int> $values
     * @return string[]
     */
    private function sorted(array $values): array
    {
        $values = array_map('strval', $values);
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * Pick each attribute's value the way Magento resolves it: a store row wins, even a NULL one.
     *
     * @param array<int, array<int, string|null>> $valuesByAttribute attribute id => store id => value hash
     * @param int $storeId
     * @return array<int, string|null>
     */
    private function effectiveAttributes(array $valuesByAttribute, int $storeId): array
    {
        $effective = [];
        foreach ($valuesByAttribute as $attributeId => $valuesByStore) {
            if (array_key_exists($storeId, $valuesByStore)) {
                $effective[$attributeId] = $valuesByStore[$storeId];
            } elseif (array_key_exists(0, $valuesByStore)) {
                $effective[$attributeId] = $valuesByStore[0];
            }
        }
        ksort($effective);

        return $effective;
    }

    /**
     * Default-scope values of attributes a price calculator reads from the default-scope product.
     *
     * @param array<int, array<int, string|null>> $valuesByAttribute attribute id => store id => value hash
     * @param int[] $attributeIds
     * @return array<int, string|null>
     */
    private function defaultValues(array $valuesByAttribute, array $attributeIds): array
    {
        $defaults = [];
        foreach ($attributeIds as $attributeId) {
            if (isset($valuesByAttribute[$attributeId]) && array_key_exists(0, $valuesByAttribute[$attributeId])) {
                $defaults[$attributeId] = $valuesByAttribute[$attributeId][0];
            }
        }
        ksort($defaults);

        return $defaults;
    }
}
