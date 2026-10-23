<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\Resolver;

use BradSearch\SearchGraphQl\Model\Resolver\SearchTracking;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use PHPUnit\Framework\TestCase;

class SearchTrackingTest extends TestCase
{
    /** @var SearchTracking */
    private $resolver;

    protected function setUp(): void
    {
        $this->resolver = new SearchTracking();
    }

    public function testReturnsTheTrackingSetByTheResponseMapper(): void
    {
        $tracking = [
            'correlation_id' => 'abc-123',
            'ai_search' => ['used' => true, 'match' => 'exact'],
            'ai_matches' => [['product_id' => 5, 'ai_match' => 'exact']],
        ];

        $this->assertSame($tracking, $this->resolve(['items' => [], 'bradsearch_search' => $tracking]));
    }

    public function testReturnsNullWhenTheSearchHasNoTracking(): void
    {
        $this->assertNull($this->resolve(['items' => [], 'bradsearch_search' => null]));
        $this->assertNull($this->resolve(['items' => []]));
        $this->assertNull($this->resolve(null));
    }

    private function resolve(?array $value)
    {
        return $this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            $value
        );
    }
}
