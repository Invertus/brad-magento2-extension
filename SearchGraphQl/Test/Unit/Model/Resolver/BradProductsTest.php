<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\Resolver;

use BradSearch\SearchGraphQl\Model\Resolver\BradProducts;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards the COLLECTION_ATTRIBUTES list that BradProducts selects on the
 * product collection before calling the price calculator.
 *
 * The price calculator delegates bundle product pricing to Magento's
 * bundle price provider, which reads `price_type` (FIXED vs DYNAMIC) off
 * the product. If `price_type` is missing from the selected attribute
 * list, the provider gets null, falls back to FIXED, and prices bundle
 * products against the parent's own value instead of the sum of
 * children. This regression surfaced once `special_price` was removed
 * from the Verkter (Irankiai) catalog (BRD-1070).
 */
class BradProductsTest extends TestCase
{
    /**
     * Codes the BradProducts resolver MUST load so downstream price
     * calculation, EAV-driven URL/image rendering, and tax routing all
     * have the values they need.
     */
    private const REQUIRED_ATTRIBUTES = [
        'name',
        'sku',
        'url_key',
        'image',
        'short_description',
        'description',
        'price',
        'price_type',
        'special_price',
        'special_from_date',
        'special_to_date',
        'tax_class_id',
        'mm_popularity',
    ];

    /**
     * @return string[]
     */
    private function getCollectionAttributes(): array
    {
        $reflection = new ReflectionClass(BradProducts::class);
        $constant = $reflection->getReflectionConstant('COLLECTION_ATTRIBUTES');
        $this->assertNotFalse(
            $constant,
            'BradProducts::COLLECTION_ATTRIBUTES constant must exist.'
        );

        $value = $constant->getValue();
        $this->assertIsArray(
            $value,
            'BradProducts::COLLECTION_ATTRIBUTES must be an array of attribute codes.'
        );

        return $value;
    }

    public function testCollectionAttributesIncludesPriceType(): void
    {
        $this->assertContains(
            'price_type',
            $this->getCollectionAttributes(),
            'BradProducts::COLLECTION_ATTRIBUTES must include `price_type` so bundle '
            . 'product prices resolve correctly in stores that no longer rely on '
            . '`special_price` (BRD-1070).'
        );
    }

    public function testCollectionAttributesPreservesRequiredFields(): void
    {
        $attributes = $this->getCollectionAttributes();

        foreach (self::REQUIRED_ATTRIBUTES as $required) {
            $this->assertContains(
                $required,
                $attributes,
                sprintf(
                    'BradProducts::COLLECTION_ATTRIBUTES must include `%s` — removing it '
                    . 'breaks downstream price/tax/URL rendering.',
                    $required
                )
            );
        }
    }

    public function testCollectionAttributesHasNoDuplicates(): void
    {
        $attributes = $this->getCollectionAttributes();

        $this->assertSame(
            count($attributes),
            count(array_unique($attributes)),
            sprintf(
                'BradProducts::COLLECTION_ATTRIBUTES must not contain duplicate codes. Got: %s',
                implode(', ', $attributes)
            )
        );
    }
}
