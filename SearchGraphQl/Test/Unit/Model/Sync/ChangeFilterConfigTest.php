<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\Sync;

use BradSearch\ProductFeatures\Model\ProductDataLoader;
use BradSearch\SearchGraphQl\Model\Resolver\BradProducts;
use PHPUnit\Framework\TestCase;

/**
 * An attribute the sync reads but the change filter does not hash would never be sent again.
 */
class ChangeFilterConfigTest extends TestCase
{
    public function testDiConfigHashesEveryAttributeTheSyncAlwaysReads(): void
    {
        $required = array_merge(
            $this->privateConstant(BradProducts::class, 'COLLECTION_ATTRIBUTES'),
            $this->privateConstant(ProductDataLoader::class, 'WHITELIST_ATTRIBUTES'),
            ['status', 'visibility']
        );

        $this->assertSame([], array_values(array_diff($required, $this->configuredCodes())));
    }

    /**
     * @return string[]
     */
    private function configuredCodes(): array
    {
        $config = simplexml_load_file(__DIR__ . '/../../../../etc/di.xml');
        $items = $config->xpath(
            '//type[@name="BradSearch\SearchGraphQl\Model\Sync\ChangeFilter"]/arguments/argument[@name="attributeCodes"]/item'
        );

        return array_map('strval', $items ?: []);
    }

    /**
     * @param string $class
     * @param string $name
     * @return string[]
     */
    private function privateConstant(string $class, string $name): array
    {
        return (new \ReflectionClassConstant($class, $name))->getValue();
    }
}
