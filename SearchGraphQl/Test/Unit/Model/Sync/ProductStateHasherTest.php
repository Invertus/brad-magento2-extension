<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\Sync;

use BradSearch\SearchGraphQl\Model\Sync\ProductStateHasher;
use PHPUnit\Framework\TestCase;

class ProductStateHasherTest extends TestCase
{
    private const STORE = 5;
    private const OTHER_STORE = 6;
    private const WEBSITE = 3;
    private const TODAY = '2026-11-11';

    /** @var ProductStateHasher */
    private $hasher;

    protected function setUp(): void
    {
        $this->hasher = new ProductStateHasher();
    }

    public function testTheSameStateGivesTheSameHash(): void
    {
        $this->assertSame($this->hash($this->state()), $this->hash($this->state()));
    }

    public function testRowOrderDoesNotChangeTheHash(): void
    {
        $state = $this->state();
        $reordered = $state;
        $reordered['attributes'][10] = array_reverse($state['attributes'][10], true);
        $reordered['categories'][10] = array_reverse($state['categories'][10]);

        $this->assertSame($this->hash($state), $this->hash($reordered));
    }

    public function testAStoreValueChangeChangesOnlyThatStoresHash(): void
    {
        $before = $this->state();
        $after = $before;
        $after['attributes'][10][73][self::STORE] = md5('Gręžtuvas');

        $this->assertNotSame($this->hash($before), $this->hash($after));
        $this->assertSame(
            $this->hash($before, self::OTHER_STORE),
            $this->hash($after, self::OTHER_STORE)
        );
    }

    public function testADefaultValueChangeChangesTheHashWhenTheStoreHasNoOverride(): void
    {
        $before = $this->state();
        $after = $before;
        $after['attributes'][10][77][0] = md5('120.00');

        $this->assertNotSame($this->hash($before), $this->hash($after));
    }

    public function testADefaultValueChangeIsIgnoredWhenTheStoreOverridesIt(): void
    {
        $before = $this->state();
        $after = $before;
        $after['attributes'][10][73][0] = md5('Drill renamed');

        $this->assertSame($this->hash($before), $this->hash($after));
        $this->assertNotSame(
            $this->hash($before, self::OTHER_STORE),
            $this->hash($after, self::OTHER_STORE)
        );
    }

    public function testADefaultScopeAttributeChangesTheHashEvenWhenTheStoreOverridesIt(): void
    {
        $before = $this->state();
        $after = $before;
        $after['attributes'][10][73][0] = md5('Drill renamed');

        $this->assertNotSame(
            $this->hasher->hash($before, 10, self::STORE, self::WEBSITE, self::TODAY, [73]),
            $this->hasher->hash($after, 10, self::STORE, self::WEBSITE, self::TODAY, [73])
        );
    }

    public function testADefaultScopeAttributeStillChangesTheHashAtStoreScope(): void
    {
        $before = $this->state();
        $after = $before;
        $after['attributes'][10][73][self::STORE] = md5('Gręžtuvas 2');

        $this->assertNotSame(
            $this->hasher->hash($before, 10, self::STORE, self::WEBSITE, self::TODAY, [73]),
            $this->hasher->hash($after, 10, self::STORE, self::WEBSITE, self::TODAY, [73])
        );
    }

    public function testADefaultScopeAttributeWithoutADefaultRowHashesStably(): void
    {
        $state = $this->state();

        $this->assertSame(
            $this->hasher->hash($state, 10, self::STORE, self::WEBSITE, self::TODAY, [999, 73]),
            $this->hasher->hash($state, 10, self::STORE, self::WEBSITE, self::TODAY, [73, 999])
        );
    }

    public function testAStoreOverrideWithANullValueStillWinsOverTheDefault(): void
    {
        $before = $this->state();
        $before['attributes'][10][77][self::STORE] = null;
        $after = $before;
        $after['attributes'][10][77][0] = md5('120.00');

        $this->assertSame($this->hash($before), $this->hash($after));
    }

    public function testAnAddedAttributeValueChangesTheHash(): void
    {
        $before = $this->state();
        $after = $before;
        $after['attributes'][10][90] = [0 => md5('Makita')];

        $this->assertNotSame($this->hash($before), $this->hash($after));
    }

    public function testEntityCategoryWebsiteStockAndChildChangesChangeTheHash(): void
    {
        $before = $this->state();
        $changes = [
            'entity' => ['sku' => 'D-2', 'type_id' => 'simple', 'attribute_set_id' => '4'],
            'categories' => ['7', '9'],
            'websites' => ['1'],
            'stock' => ['0|0'],
            'children' => ['11', '12', '13'],
        ];

        foreach ($changes as $part => $value) {
            $after = $before;
            $after[$part][10] = $value;
            $this->assertNotSame($this->hash($before), $this->hash($after), $part);
        }
    }

    public function testACategoryIndexChangeChangesOnlyThatStoresHash(): void
    {
        $before = $this->state();
        $after = $before;
        $after['categoryIndex'][10][self::STORE] = ['2', '7', '8', '9'];

        $this->assertNotSame($this->hash($before), $this->hash($after));
        $this->assertSame(
            $this->hash($before, self::OTHER_STORE),
            $this->hash($after, self::OTHER_STORE)
        );
    }

    public function testACategorySentBeforeTheIndexCaughtUpIsSentAgainOnceItDid(): void
    {
        $sent = $this->state();
        $sent['categories'][10] = ['7', '8', '9'];
        $indexed = $sent;
        $indexed['categoryIndex'][10][self::STORE] = ['2', '7', '8', '9'];

        $this->assertNotSame($this->hash($sent), $this->hash($indexed));
    }

    public function testOnlyTodaysCatalogRulePriceForTheStoresWebsiteCounts(): void
    {
        $before = $this->state();

        $tomorrow = $before;
        $tomorrow['rules'][10][self::WEBSITE]['2026-11-12'] = '80.000000';
        $this->assertSame($this->hash($before), $this->hash($tomorrow));

        $otherWebsite = $before;
        $otherWebsite['rules'][10][4][self::TODAY] = '70.000000';
        $this->assertSame($this->hash($before), $this->hash($otherWebsite));

        $today = $before;
        $today['rules'][10][self::WEBSITE][self::TODAY] = '85.000000';
        $this->assertNotSame($this->hash($before), $this->hash($today));
    }

    public function testADeletedProductHashesDifferentlyFromTheProductItWas(): void
    {
        $deleted = $this->state();
        foreach (array_keys($deleted) as $part) {
            unset($deleted[$part][10]);
        }

        $this->assertNotSame($this->hash($this->state()), $this->hash($deleted));
    }

    public function testAProductWithNoRowsAlwaysGetsTheSameHash(): void
    {
        $this->assertSame(
            $this->hasher->hash([], 404, self::STORE, self::WEBSITE, self::TODAY),
            $this->hasher->hash($this->state(), 405, self::STORE, self::WEBSITE, self::TODAY)
        );
    }

    private function hash(array $state, int $storeId = self::STORE): string
    {
        return $this->hasher->hash($state, 10, $storeId, self::WEBSITE, self::TODAY);
    }

    /**
     * Product 10: name 73 overridden in STORE, price 77 only at default scope.
     */
    private function state(): array
    {
        return [
            'entity' => [10 => ['sku' => 'D-1', 'type_id' => 'simple', 'attribute_set_id' => '4']],
            'attributes' => [10 => [
                73 => [0 => md5('Drill'), self::STORE => md5('Gręžtuvas LT')],
                77 => [0 => md5('99.00')],
            ]],
            'categories' => [10 => ['7', '8']],
            'websites' => [10 => ['1', '3']],
            'stock' => [10 => ['0|1']],
            'children' => [10 => ['11', '12']],
            'categoryIndex' => [10 => [self::STORE => ['2', '7', '8'], self::OTHER_STORE => ['2', '7', '8']]],
            'rules' => [10 => [self::WEBSITE => [self::TODAY => '90.000000']]],
        ];
    }
}
