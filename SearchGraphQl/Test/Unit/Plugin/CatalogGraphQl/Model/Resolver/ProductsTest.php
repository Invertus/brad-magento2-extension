<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Plugin\CatalogGraphQl\Model\Resolver;

use BradSearch\SearchGraphQl\Model\MockData\ProductsProvider;
use BradSearch\SearchGraphQl\Plugin\CatalogGraphQl\Model\Resolver\Products;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductsTest extends TestCase
{
    private Products $subject;
    private ScopeConfigInterface&MockObject $scopeConfigMock;
    private StoreManagerInterface&MockObject $storeManagerMock;
    private ProductsProvider&MockObject $productsProviderMock;
    private LoggerInterface&MockObject $loggerMock;
    private Field&MockObject $fieldMock;
    private ResolveInfo&MockObject $resolveInfoMock;

    /**
     * Test that plugin does not intercept when BradSearch is disabled
     */
    public function testAroundResolveWhenBradSearchDisabled(): void
    {
        $args = ['search' => 'test query'];
        $expectedResult = ['items' => [], 'total_count' => 0];

        // Mock BradSearch disabled
        $this->scopeConfigMock
            ->expects($this->once())
            ->method('getValue')
            ->willReturn(false);

        // Mock operation name
        $this->resolveInfoMock->operation = (object)['name' => (object)['value' => 'ProductSearch']];

        // Expect proceed to be called
        $proceed = function () use ($expectedResult) {
            return $expectedResult;
        };

        $result = $this->subject->aroundResolve(
            new \stdClass(),
            $proceed,
            $this->fieldMock,
            null,
            $this->resolveInfoMock,
            null,
            $args
        );

        $this->assertSame($expectedResult, $result);
    }

    /**
     * Test that plugin does not intercept when search parameter is missing
     */
    public function testAroundResolveWhenNoSearchParameter(): void
    {
        $args = ['filter' => ['category_id' => ['eq' => '5']]];
        $expectedResult = ['items' => [], 'total_count' => 0];

        // Expect proceed to be called without checking BradSearch config
        $proceed = function () use ($expectedResult) {
            return $expectedResult;
        };

        $result = $this->subject->aroundResolve(
            new \stdClass(),
            $proceed,
            $this->fieldMock,
            null,
            $this->resolveInfoMock,
            null,
            $args
        );

        $this->assertSame($expectedResult, $result);
    }

    /**
     * Test that plugin does not intercept when operation is not ProductSearch
     */
    public function testAroundResolveWhenNotProductSearchOperation(): void
    {
        $args = ['search' => 'test query'];
        $expectedResult = ['items' => [], 'total_count' => 0];

        // Mock different operation name
        $this->resolveInfoMock->operation = (object)['name' => (object)['value' => 'CategoryProducts']];

        // Expect proceed to be called
        $proceed = function () use ($expectedResult) {
            return $expectedResult;
        };

        $result = $this->subject->aroundResolve(
            new \stdClass(),
            $proceed,
            $this->fieldMock,
            null,
            $this->resolveInfoMock,
            null,
            $args
        );

        $this->assertSame($expectedResult, $result);
    }

    /**
     * Test that plugin intercepts when BradSearch is enabled with ProductSearch operation
     */
    public function testAroundResolveWhenBradSearchEnabledWithSearch(): void
    {
        $searchTerm = 'test query';
        $pageSize = 24;
        $currentPage = 2;
        $filters = ['color' => ['eq' => 'red']];
        $sort = ['price' => 'ASC'];

        $args = [
            'search' => $searchTerm,
            'pageSize' => $pageSize,
            'currentPage' => $currentPage,
            'filter' => $filters,
            'sort' => $sort,
        ];

        $bradSearchResult = [
            'items' => [['sku' => 'TEST-SKU']],
            'total_count' => 100,
        ];

        // Mock BradSearch enabled
        $this->scopeConfigMock
            ->expects($this->once())
            ->method('getValue')
            ->willReturn(true);

        // Mock operation name
        $this->resolveInfoMock->operation = (object)['name' => (object)['value' => 'ProductSearch']];

        // Expect ProductsProvider to be called
        $this->productsProviderMock
            ->expects($this->once())
            ->method('getSearchResults')
            ->with($searchTerm, $pageSize, $currentPage, $filters, $sort)
            ->willReturn($bradSearchResult);

        // Proceed should NOT be called
        $proceed = function () {
            $this->fail('Proceed should not be called when BradSearch intercepts');
        };

        $result = $this->subject->aroundResolve(
            new \stdClass(),
            $proceed,
            $this->fieldMock,
            null,
            $this->resolveInfoMock,
            null,
            $args
        );

        $this->assertSame($bradSearchResult, $result);
    }

    /**
     * Test operation name extraction from $_GET fallback
     */
    public function testAroundResolveWithGetParameterOperationName(): void
    {
        $_GET['operationName'] = 'ProductSearch';

        $args = ['search' => 'test'];
        $bradSearchResult = ['items' => [], 'total_count' => 0];

        // Mock BradSearch enabled
        $this->scopeConfigMock
            ->expects($this->once())
            ->method('getValue')
            ->willReturn(true);

        // Mock no operation in ResolveInfo
        $this->resolveInfoMock->operation = null;

        // Expect ProductsProvider to be called
        $this->productsProviderMock
            ->expects($this->once())
            ->method('getSearchResults')
            ->willReturn($bradSearchResult);

        $proceed = function () {
            $this->fail('Should not proceed');
        };

        $result = $this->subject->aroundResolve(
            new \stdClass(),
            $proceed,
            $this->fieldMock,
            null,
            $this->resolveInfoMock,
            null,
            $args
        );

        $this->assertSame($bradSearchResult, $result);

        unset($_GET['operationName']);
    }

    /**
     * Test with default pagination parameters
     */
    public function testAroundResolveWithDefaultPagination(): void
    {
        $args = ['search' => 'test'];

        // Mock BradSearch enabled
        $this->scopeConfigMock
            ->expects($this->once())
            ->method('getValue')
            ->willReturn(true);

        // Mock operation name
        $this->resolveInfoMock->operation = (object)['name' => (object)['value' => 'ProductSearch']];

        // Expect default pagination values
        $this->productsProviderMock
            ->expects($this->once())
            ->method('getSearchResults')
            ->with('test', 18, 1, [], [])
            ->willReturn(['items' => [], 'total_count' => 0]);

        $proceed = function () {
            return [];
        };

        $this->subject->aroundResolve(
            new \stdClass(),
            $proceed,
            $this->fieldMock,
            null,
            $this->resolveInfoMock,
            null,
            $args
        );
    }

    protected function setUp(): void
    {
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->productsProviderMock = $this->createMock(ProductsProvider::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->fieldMock = $this->createMock(Field::class);
        $this->resolveInfoMock = $this->createMock(ResolveInfo::class);

        // Mock store manager
        $storeMock = $this->createMock(Store::class);
        $storeMock->method('getId')->willReturn(1);
        $this->storeManagerMock->method('getStore')->willReturn($storeMock);

        $this->subject = new Products(
            $this->scopeConfigMock,
            $this->storeManagerMock,
            $this->productsProviderMock,
            $this->loggerMock
        );
    }
}
