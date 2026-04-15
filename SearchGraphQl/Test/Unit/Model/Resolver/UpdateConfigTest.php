<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\Resolver;

use BradSearch\SearchGraphQl\Model\Api\Auth\ApiKeyValidator;
use BradSearch\SearchGraphQl\Model\Resolver\UpdateConfig;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UpdateConfigTest extends TestCase
{
    private const STORE_ID = 1;

    /** @var UpdateConfig */
    private $resolver;

    /** @var ApiKeyValidator|MockObject */
    private $apiKeyValidator;

    /** @var WriterInterface|MockObject */
    private $configWriter;

    /** @var ReinitableConfigInterface|MockObject */
    private $reinitableConfig;

    /** @var TypeListInterface|MockObject */
    private $cacheTypeList;

    /** @var ScopeConfigInterface|MockObject */
    private $scopeConfig;

    /** @var LoggerInterface|MockObject */
    private $logger;

    /** @var Field|MockObject */
    private $field;

    /** @var ResolveInfo|MockObject */
    private $resolveInfo;

    /** @var object */
    private $context;

    protected function setUp(): void
    {
        $this->apiKeyValidator = $this->createMock(ApiKeyValidator::class);
        $this->configWriter = $this->createMock(WriterInterface::class);
        $this->reinitableConfig = $this->createMock(ReinitableConfigInterface::class);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->field = $this->createMock(Field::class);
        $this->resolveInfo = $this->createMock(ResolveInfo::class);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);

        $extensionAttributes = new class($store) {
            private $store;
            public function __construct($store) { $this->store = $store; }
            public function getStore() { return $this->store; }
        };

        $this->context = new class($extensionAttributes) {
            private $ext;
            public function __construct($ext) { $this->ext = $ext; }
            public function getExtensionAttributes() { return $this->ext; }
        };

        $this->resolver = new UpdateConfig(
            $this->apiKeyValidator,
            $this->configWriter,
            $this->reinitableConfig,
            $this->cacheTypeList,
            $this->scopeConfig,
            $this->logger
        );
    }

    public function testRejectsInvalidApiKey(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(false);

        $this->expectException(GraphQlAuthorizationException::class);

        $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => [['path' => 'bradsearch_search/general/enabled', 'value' => '1']]]
        );
    }

    public function testRejectsEmptyItems(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(true);

        $this->expectException(GraphQlInputException::class);

        $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => []]
        );
    }

    public function testSuccessfulSingleUpdate(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(true);

        $this->configWriter->expects($this->once())
            ->method('save')
            ->with(
                'bradsearch_search/general/enabled',
                '1',
                ScopeInterface::SCOPE_STORES,
                self::STORE_ID
            );

        $this->reinitableConfig->expects($this->once())->method('reinit');
        $this->cacheTypeList->expects($this->once())->method('cleanType')->with(\Magento\Framework\App\Cache\Type\Config::TYPE_IDENTIFIER);

        $result = $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => [['path' => 'bradsearch_search/general/enabled', 'value' => '1']]]
        );

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['success']);
        $this->assertEquals('bradsearch_search/general/enabled', $result[0]['path']);
        $this->assertNull($result[0]['message']);
    }

    public function testSuccessfulBatchUpdate(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(true);

        $this->configWriter->expects($this->exactly(3))->method('save');

        $result = $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => [
                ['path' => 'bradsearch_search/general/enabled', 'value' => '1'],
                ['path' => 'bradsearch_search/general/api_url', 'value' => 'https://api.example.com'],
                ['path' => 'bradsearch_analytics/general/website_id', 'value' => 'abc-123'],
            ]]
        );

        $this->assertCount(3, $result);
        foreach ($result as $item) {
            $this->assertTrue($item['success']);
        }
    }

    public function testRejectsPathNotInWhitelist(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(true);

        $this->configWriter->expects($this->never())->method('save');
        $this->reinitableConfig->expects($this->never())->method('reinit');

        $result = $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => [['path' => 'web/secure/base_url', 'value' => 'https://evil.com']]]
        );

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['success']);
        $this->assertStringContainsString('not allowed', $result[0]['message']);
    }

    public function testRejectsSecuritySensitivePaths(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(true);

        $result = $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => [
                ['path' => 'bradsearch_search/private_endpoint/api_key', 'value' => 'new-key'],
                ['path' => 'bradsearch_search/private_endpoint/enabled', 'value' => '0'],
                ['path' => 'bradsearch_search/sync/secure_token', 'value' => 'new-token'],
            ]]
        );

        foreach ($result as $item) {
            $this->assertFalse($item['success']);
            $this->assertStringContainsString('not allowed', $item['message']);
        }
    }

    public function testRejectsBothValueAndJsonMerge(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(true);

        $result = $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => [[
                'path' => 'bradsearch_autocomplete/general/config',
                'value' => '{}',
                'json_merge' => '{"styles":{}}',
            ]]]
        );

        $this->assertFalse($result[0]['success']);
        $this->assertStringContainsString('Cannot specify both', $result[0]['message']);
    }

    public function testRejectsNeitherValueNorJsonMerge(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(true);

        $result = $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => [['path' => 'bradsearch_search/general/enabled']]]
        );

        $this->assertFalse($result[0]['success']);
        $this->assertStringContainsString('Must specify either', $result[0]['message']);
    }

    public function testJsonMergePartialUpdate(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(true);

        $existingConfig = json_encode([
            'apiConfig' => ['url' => 'https://api.example.com', 'limit' => 10],
            'options' => ['currency' => 'EUR', 'columns' => 3],
            'styles' => ['global' => ['fontFamily' => 'Arial']],
        ]);

        $this->scopeConfig->method('getValue')
            ->with('bradsearch_autocomplete/general/config', ScopeInterface::SCOPE_STORE, self::STORE_ID)
            ->willReturn($existingConfig);

        $savedValue = null;
        $this->configWriter->method('save')
            ->willReturnCallback(function ($path, $value) use (&$savedValue) {
                $savedValue = $value;
            });

        $result = $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => [[
                'path' => 'bradsearch_autocomplete/general/config',
                'json_merge' => '{"styles":{"global":{"fontFamily":"Inter"}},"options":{"currency":"SEK"}}',
            ]]]
        );

        $this->assertTrue($result[0]['success']);

        $merged = json_decode($savedValue, true);
        // Merged values
        $this->assertEquals('Inter', $merged['styles']['global']['fontFamily']);
        $this->assertEquals('SEK', $merged['options']['currency']);
        // Preserved values
        $this->assertEquals('https://api.example.com', $merged['apiConfig']['url']);
        $this->assertEquals(10, $merged['apiConfig']['limit']);
        $this->assertEquals(3, $merged['options']['columns']);
    }

    public function testJsonMergeOnEmptyExistingValue(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(true);

        $this->scopeConfig->method('getValue')->willReturn(null);

        $savedValue = null;
        $this->configWriter->method('save')
            ->willReturnCallback(function ($path, $value) use (&$savedValue) {
                $savedValue = $value;
            });

        $result = $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => [[
                'path' => 'bradsearch_autocomplete/general/config',
                'json_merge' => '{"styles":{"global":{"fontFamily":"Inter"}}}',
            ]]]
        );

        $this->assertTrue($result[0]['success']);

        $merged = json_decode($savedValue, true);
        $this->assertEquals('Inter', $merged['styles']['global']['fontFamily']);
    }

    public function testJsonMergeWithInvalidMergeJson(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(true);

        $result = $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => [[
                'path' => 'bradsearch_autocomplete/general/config',
                'json_merge' => 'not valid json{',
            ]]]
        );

        $this->assertFalse($result[0]['success']);
        $this->assertStringContainsString('Invalid JSON', $result[0]['message']);
    }

    public function testJsonMergeWithCorruptedExistingConfig(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(true);

        $this->scopeConfig->method('getValue')->willReturn('not valid json{');

        $result = $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => [[
                'path' => 'bradsearch_autocomplete/general/config',
                'json_merge' => '{"styles":{}}',
            ]]]
        );

        $this->assertFalse($result[0]['success']);
        $this->assertStringContainsString('Invalid JSON', $result[0]['message']);
    }

    public function testRejectsDuplicateJsonMergeSamePath(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(true);

        $this->scopeConfig->method('getValue')->willReturn('{"existing":"data"}');

        // Only the first json_merge should write; the second should be rejected
        $this->configWriter->expects($this->once())->method('save');

        $result = $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => [
                [
                    'path' => 'bradsearch_autocomplete/general/config',
                    'json_merge' => '{"styles":{"color":"red"}}',
                ],
                [
                    'path' => 'bradsearch_autocomplete/general/config',
                    'json_merge' => '{"options":{"limit":5}}',
                ],
            ]]
        );

        $this->assertTrue($result[0]['success']);
        $this->assertFalse($result[1]['success']);
        $this->assertStringContainsString('Duplicate json_merge', $result[1]['message']);
    }

    public function testValidationRejectsInvalidBoolean(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(true);

        $result = $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => [['path' => 'bradsearch_search/general/enabled', 'value' => 'yes']]]
        );

        $this->assertFalse($result[0]['success']);
        $this->assertStringContainsString('"0" or "1"', $result[0]['message']);
    }

    public function testValidationRejectsInvalidUrl(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(true);

        $result = $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => [['path' => 'bradsearch_search/general/api_url', 'value' => 'not-a-url']]]
        );

        $this->assertFalse($result[0]['success']);
        $this->assertStringContainsString('valid URL', $result[0]['message']);
    }

    public function testValidationAllowsEmptyUrl(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(true);

        $this->configWriter->expects($this->once())->method('save');

        $result = $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => [['path' => 'bradsearch_search/general/api_url', 'value' => '']]]
        );

        $this->assertTrue($result[0]['success']);
    }

    public function testValidationRejectsInvalidJson(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(true);

        $result = $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => [['path' => 'bradsearch_autocomplete/general/config', 'value' => '{invalid}']]]
        );

        $this->assertFalse($result[0]['success']);
        $this->assertStringContainsString('valid JSON', $result[0]['message']);
    }

    public function testValidationAllowsEmptyJsonValue(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(true);

        $this->configWriter->expects($this->once())->method('save');

        $result = $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => [['path' => 'bradsearch_autocomplete/general/config', 'value' => '']]]
        );

        $this->assertTrue($result[0]['success']);
    }

    public function testPartialBatchFailureStillWritesValidItems(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(true);

        // 2 valid items, 1 invalid — should write 2, cache clean once
        $this->configWriter->expects($this->exactly(2))->method('save');
        $this->reinitableConfig->expects($this->once())->method('reinit');
        $this->cacheTypeList->expects($this->once())->method('cleanType');

        $result = $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => [
                ['path' => 'bradsearch_search/general/enabled', 'value' => '1'],
                ['path' => 'bradsearch_search/general/enabled', 'value' => 'invalid'],
                ['path' => 'bradsearch_analytics/general/website_id', 'value' => 'test-id'],
            ]]
        );

        $this->assertTrue($result[0]['success']);
        $this->assertFalse($result[1]['success']);
        $this->assertTrue($result[2]['success']);
    }

    public function testNoCacheCleanWhenAllItemsFail(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(true);

        $this->configWriter->expects($this->never())->method('save');
        $this->reinitableConfig->expects($this->never())->method('reinit');
        $this->cacheTypeList->expects($this->never())->method('cleanType');

        $result = $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => [
                ['path' => 'invalid/path/here', 'value' => '1'],
                ['path' => 'bradsearch_search/general/enabled', 'value' => 'bad'],
            ]]
        );

        $this->assertFalse($result[0]['success']);
        $this->assertFalse($result[1]['success']);
    }

    public function testAllAllowedPathsAcceptValues(): void
    {
        $this->apiKeyValidator->method('isValidRequest')->willReturn(true);
        $this->scopeConfig->method('getValue')->willReturn(null);

        $items = [
            ['path' => 'bradsearch_search/general/enabled', 'value' => '1'],
            ['path' => 'bradsearch_search/general/api_url', 'value' => 'https://api.example.com'],
            ['path' => 'bradsearch_search/general/facets_api_url', 'value' => 'https://facets.example.com'],
            ['path' => 'bradsearch_search/general/api_key', 'value' => 'some-key'],
            ['path' => 'bradsearch_search/general/debug_logging', 'value' => '0'],
            ['path' => 'bradsearch_search/sync/enabled', 'value' => '1'],
            ['path' => 'bradsearch_search/sync/webhook_url', 'value' => 'https://webhook.example.com'],
            ['path' => 'bradsearch_autocomplete/general/enabled', 'value' => '1'],
            ['path' => 'bradsearch_autocomplete/general/public_key', 'value' => 'pub-key'],
            ['path' => 'bradsearch_autocomplete/general/config', 'value' => '{"test":true}'],
            ['path' => 'bradsearch_analytics/general/enabled', 'value' => '1'],
            ['path' => 'bradsearch_analytics/general/api_url', 'value' => 'https://analytics.example.com'],
            ['path' => 'bradsearch_analytics/general/website_id', 'value' => 'site-uuid'],
        ];

        $result = $this->resolver->resolve(
            $this->field,
            $this->context,
            $this->resolveInfo,
            null,
            ['items' => $items]
        );

        foreach ($result as $i => $item) {
            $this->assertTrue($item['success'], "Item {$i} ({$items[$i]['path']}) should succeed but failed: " . ($item['message'] ?? ''));
        }
    }
}
