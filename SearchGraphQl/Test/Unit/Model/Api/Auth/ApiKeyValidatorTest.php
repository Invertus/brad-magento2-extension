<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\Api\Auth;

use BradSearch\SearchGraphQl\Model\Api\Auth\ApiKeyValidator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApiKeyValidatorTest extends TestCase
{
    private const STORE_ID = 1;
    private const VALID_API_KEY = 'test-api-key-12345';
    private const CONFIG_PATH_ENABLED = 'bradsearch_search/private_endpoint/enabled';
    private const CONFIG_PATH_API_KEY = 'bradsearch_search/private_endpoint/api_key';

    /**
     * @var RequestInterface|MockObject
     */
    private $requestMock;

    /**
     * @var ScopeConfigInterface|MockObject
     */
    private $scopeConfigMock;

    /**
     * @var EncryptorInterface|MockObject
     */
    private $encryptorMock;

    /**
     * @var LoggerInterface|MockObject
     */
    private $loggerMock;

    /**
     * @var ApiKeyValidator
     */
    private ApiKeyValidator $validator;

    protected function setUp(): void
    {
        $this->requestMock = $this->createMock(RequestInterface::class);
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->encryptorMock = $this->createMock(EncryptorInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->validator = new ApiKeyValidator(
            $this->requestMock,
            $this->scopeConfigMock,
            $this->encryptorMock,
            $this->loggerMock
        );
    }

    public function testIsValidRequestReturnsFalseWhenDisabled(): void
    {
        $this->scopeConfigMock
            ->method('isSetFlag')
            ->with(self::CONFIG_PATH_ENABLED, 'store', self::STORE_ID)
            ->willReturn(false);

        $this->assertFalse($this->validator->isValidRequest(self::STORE_ID));
    }

    public function testIsValidRequestReturnsFalseWhenNoApiKeyInRequest(): void
    {
        $this->scopeConfigMock
            ->method('isSetFlag')
            ->with(self::CONFIG_PATH_ENABLED, 'store', self::STORE_ID)
            ->willReturn(true);

        $this->requestMock
            ->method('getHeader')
            ->with('X-BradSearch-Api-Key')
            ->willReturn(false);

        $this->requestMock
            ->method('getServer')
            ->with('HTTP_X_BRADSEARCH_API_KEY')
            ->willReturn(null);

        $this->assertFalse($this->validator->isValidRequest(self::STORE_ID));
    }

    public function testIsValidRequestReturnsFalseWhenNoApiKeyConfigured(): void
    {
        $this->scopeConfigMock
            ->method('isSetFlag')
            ->with(self::CONFIG_PATH_ENABLED, 'store', self::STORE_ID)
            ->willReturn(true);

        $this->requestMock
            ->method('getHeader')
            ->with('X-BradSearch-Api-Key')
            ->willReturn(self::VALID_API_KEY);

        $this->scopeConfigMock
            ->method('getValue')
            ->with(self::CONFIG_PATH_API_KEY, 'store', self::STORE_ID)
            ->willReturn(null);

        $this->loggerMock
            ->expects($this->once())
            ->method('warning')
            ->with('BradSearch: Private endpoint enabled but no API key configured');

        $this->assertFalse($this->validator->isValidRequest(self::STORE_ID));
    }

    public function testIsValidRequestReturnsFalseWhenApiKeyMismatch(): void
    {
        $this->scopeConfigMock
            ->method('isSetFlag')
            ->with(self::CONFIG_PATH_ENABLED, 'store', self::STORE_ID)
            ->willReturn(true);

        $this->requestMock
            ->method('getHeader')
            ->with('X-BradSearch-Api-Key')
            ->willReturn('wrong-api-key');

        $this->scopeConfigMock
            ->method('getValue')
            ->with(self::CONFIG_PATH_API_KEY, 'store', self::STORE_ID)
            ->willReturn('encrypted-key');

        $this->encryptorMock
            ->method('decrypt')
            ->with('encrypted-key')
            ->willReturn(self::VALID_API_KEY);

        $this->assertFalse($this->validator->isValidRequest(self::STORE_ID));
    }

    public function testIsValidRequestReturnsTrueWithValidApiKey(): void
    {
        $this->scopeConfigMock
            ->method('isSetFlag')
            ->with(self::CONFIG_PATH_ENABLED, 'store', self::STORE_ID)
            ->willReturn(true);

        $this->requestMock
            ->method('getHeader')
            ->with('X-BradSearch-Api-Key')
            ->willReturn(self::VALID_API_KEY);

        $this->scopeConfigMock
            ->method('getValue')
            ->with(self::CONFIG_PATH_API_KEY, 'store', self::STORE_ID)
            ->willReturn('encrypted-key');

        $this->encryptorMock
            ->method('decrypt')
            ->with('encrypted-key')
            ->willReturn(self::VALID_API_KEY);

        $this->assertTrue($this->validator->isValidRequest(self::STORE_ID));
    }

    public function testIsValidRequestUsesServerFallbackWhenHeaderNotFound(): void
    {
        $this->scopeConfigMock
            ->method('isSetFlag')
            ->with(self::CONFIG_PATH_ENABLED, 'store', self::STORE_ID)
            ->willReturn(true);

        $this->requestMock
            ->method('getHeader')
            ->with('X-BradSearch-Api-Key')
            ->willReturn(false);

        $this->requestMock
            ->method('getServer')
            ->with('HTTP_X_BRADSEARCH_API_KEY')
            ->willReturn(self::VALID_API_KEY);

        $this->scopeConfigMock
            ->method('getValue')
            ->with(self::CONFIG_PATH_API_KEY, 'store', self::STORE_ID)
            ->willReturn('encrypted-key');

        $this->encryptorMock
            ->method('decrypt')
            ->with('encrypted-key')
            ->willReturn(self::VALID_API_KEY);

        $this->assertTrue($this->validator->isValidRequest(self::STORE_ID));
    }
}
