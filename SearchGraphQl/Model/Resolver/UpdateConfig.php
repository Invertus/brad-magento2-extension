<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Resolver;

use BradSearch\SearchGraphQl\Model\Api\Auth\ApiKeyValidator;
use Magento\Framework\App\Cache\Type\Config as ConfigCacheType;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolver for bradUpdateConfig mutation — remote configuration update for BradSearch.
 *
 * Allows the Brad Laravel app to push configuration to Magento stores.
 * Requires valid X-BradSearch-Api-Key header.
 * Only whitelisted BradSearch config paths are writable.
 */
class UpdateConfig implements ResolverInterface
{
    /**
     * Whitelist of config paths that can be updated remotely.
     */
    private const ALLOWED_PATHS = [
        // Search
        'bradsearch_search/general/enabled',
        'bradsearch_search/general/api_url',
        'bradsearch_search/general/facets_api_url',
        'bradsearch_search/general/api_key',
        'bradsearch_search/private_endpoint/api_key',
        'bradsearch_search/general/debug_logging',
        'bradsearch_search/sync/enabled',
        'bradsearch_search/sync/webhook_url',
        // Autocomplete
        'bradsearch_autocomplete/general/enabled',
        'bradsearch_autocomplete/general/public_key',
        'bradsearch_autocomplete/general/config',
        // Analytics
        'bradsearch_analytics/general/enabled',
        'bradsearch_analytics/general/api_url',
        'bradsearch_analytics/general/website_id',
    ];

    private const PRIVATE_API_KEY_PATH = 'bradsearch_search/private_endpoint/api_key';

    private const PRIVATE_API_KEY_MAX_LENGTH = 2048;

    private const BOOLEAN_PATHS = [
        'bradsearch_search/general/enabled',
        'bradsearch_search/general/debug_logging',
        'bradsearch_search/sync/enabled',
        'bradsearch_autocomplete/general/enabled',
        'bradsearch_analytics/general/enabled',
    ];

    private const URL_PATHS = [
        'bradsearch_search/general/api_url',
        'bradsearch_search/general/facets_api_url',
        'bradsearch_search/sync/webhook_url',
        'bradsearch_analytics/general/api_url',
    ];

    private const JSON_PATHS = [
        'bradsearch_autocomplete/general/config',
    ];

    /**
     * @var ApiKeyValidator
     */
    private ApiKeyValidator $apiKeyValidator;

    /**
     * @var WriterInterface
     */
    private WriterInterface $configWriter;

    /**
     * @var ReinitableConfigInterface
     */
    private ReinitableConfigInterface $reinitableConfig;

    /**
     * @var TypeListInterface
     */
    private TypeListInterface $cacheTypeList;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var EncryptorInterface
     */
    private EncryptorInterface $encryptor;

    /**
     * @param ApiKeyValidator $apiKeyValidator
     * @param WriterInterface $configWriter
     * @param ReinitableConfigInterface $reinitableConfig
     * @param TypeListInterface $cacheTypeList
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        ApiKeyValidator $apiKeyValidator,
        WriterInterface $configWriter,
        ReinitableConfigInterface $reinitableConfig,
        TypeListInterface $cacheTypeList,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        EncryptorInterface $encryptor
    ) {
        $this->apiKeyValidator = $apiKeyValidator;
        $this->configWriter = $configWriter;
        $this->reinitableConfig = $reinitableConfig;
        $this->cacheTypeList = $cacheTypeList;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->encryptor = $encryptor;
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        $storeId = (int)$context->getExtensionAttributes()->getStore()->getId();

        if (!$this->apiKeyValidator->isValidRequest($storeId)) {
            throw new GraphQlAuthorizationException(__('Invalid or missing BradSearch API key.'));
        }

        $items = $args['items'] ?? [];
        if (empty($items)) {
            throw new GraphQlInputException(__('At least one config item is required.'));
        }

        $results = [];
        $hasChanges = false;
        $jsonMergePaths = [];

        foreach ($items as $item) {
            $path = $item['path'] ?? '';
            $jsonMerge = $item['json_merge'] ?? null;

            // Prevent two json_merge operations on the same path in one batch —
            // scopeConfig reads from the pre-batch cache, so the second merge
            // would silently overwrite the first.
            if ($jsonMerge !== null && isset($jsonMergePaths[$path])) {
                $results[] = $this->error($path, 'Duplicate json_merge for same path in one batch is not supported.');
                continue;
            }
            if ($jsonMerge !== null) {
                $jsonMergePaths[$path] = true;
            }

            $result = $this->processItem($item, $storeId);
            $results[] = $result;
            if ($result['success']) {
                $hasChanges = true;
            }
        }

        if ($hasChanges) {
            $this->reinitableConfig->reinit();
            $this->cacheTypeList->cleanType(ConfigCacheType::TYPE_IDENTIFIER);

            $updatedPaths = array_column(
                array_filter($results, function ($r) {
                    return $r['success'];
                }),
                'path'
            );
            $this->logger->info('BradSearch: Config updated remotely', [
                'store_id' => $storeId,
                'paths' => $updatedPaths,
            ]);
        }

        return $results;
    }

    /**
     * Process a single config item
     *
     * @param array $item
     * @param int $storeId
     * @return array
     */
    private function processItem(array $item, int $storeId): array
    {
        $path = $item['path'] ?? '';
        $value = $item['value'] ?? null;
        $jsonMerge = $item['json_merge'] ?? null;

        if (!in_array($path, self::ALLOWED_PATHS, true)) {
            return $this->error($path, 'Path not allowed.');
        }

        if ($value !== null && $jsonMerge !== null) {
            return $this->error($path, 'Cannot specify both value and json_merge.');
        }

        if ($value === null && $jsonMerge === null) {
            return $this->error($path, 'Must specify either value or json_merge.');
        }

        if ($jsonMerge !== null && $path === self::PRIVATE_API_KEY_PATH) {
            return $this->error($path, 'json_merge is not supported for this path.');
        }

        if ($jsonMerge !== null) {
            $mergedValue = $this->processJsonMerge($path, $jsonMerge, $storeId);
            if ($mergedValue === null) {
                return $this->error($path, 'Invalid JSON in json_merge or existing config value.');
            }
            $value = $mergedValue;
        }

        $validationError = $this->validateValue($path, $value);
        if ($validationError !== null) {
            return $this->error($path, $validationError);
        }

        if ($path === self::PRIVATE_API_KEY_PATH) {
            $value = trim($value);
        }

        $currentValue = (string)$this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($path === self::PRIVATE_API_KEY_PATH) {
            if ($this->storeAlreadyHasPrivateApiKey($currentValue, $value)) {
                return ['path' => $path, 'success' => false, 'message' => 'No change.'];
            }

            $value = $this->encryptor->encrypt($value);
        } elseif ($currentValue === $value) {
            return ['path' => $path, 'success' => false, 'message' => 'No change.'];
        }

        $this->configWriter->save(
            $path,
            $value,
            ScopeInterface::SCOPE_STORES,
            $storeId
        );

        return ['path' => $path, 'success' => true, 'message' => null];
    }

    /**
     * Deep-merge JSON into existing config value
     *
     * @param string $path
     * @param string $jsonMerge
     * @param int $storeId
     * @return string|null Merged JSON string, or null on error
     */
    private function processJsonMerge(string $path, string $jsonMerge, int $storeId): ?string
    {
        $mergeData = json_decode($jsonMerge, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $currentValue = $this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (empty($currentValue)) {
            $currentData = [];
        } else {
            $currentData = json_decode($currentValue, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }
        }

        $mergeData = is_array($mergeData) ? $this->normalizeTopLevelKeys($mergeData) : $mergeData;
        $currentData = is_array($currentData) ? $this->normalizeTopLevelKeys($currentData) : $currentData;

        $merged = array_replace_recursive($currentData, $mergeData);

        return json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Rewrite top-level kebab-case keys (`foo-bar`) to camelCase (`fooBar`).
     * Only the outermost layer is touched; nested keys are left alone. On a
     * collision the camelCase form in the source wins, regardless of order.
     *
     * @param array $data
     * @return array
     */
    private function normalizeTopLevelKeys(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || strpos($key, '-') === false) {
                $out[$key] = $value;
            }
        }
        foreach ($data as $key => $value) {
            if (!is_string($key) || strpos($key, '-') === false) {
                continue;
            }
            $normalized = preg_replace_callback(
                '/-([a-z])/',
                function ($m) {
                    return strtoupper($m[1]);
                },
                $key
            );
            if (!array_key_exists($normalized, $out)) {
                $out[$normalized] = $value;
            }
        }
        return $out;
    }

    /**
     * Validate value based on path type
     *
     * @param string $path
     * @param string $value
     * @return string|null Error message, or null if valid
     */
    private function validateValue(string $path, string $value): ?string
    {
        if (in_array($path, self::BOOLEAN_PATHS, true)) {
            if ($value !== '0' && $value !== '1') {
                return 'Value must be "0" or "1" for this path.';
            }
        } elseif (in_array($path, self::URL_PATHS, true)) {
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_URL) === false) {
                return 'Value must be a valid URL or empty string.';
            }
        } elseif ($path === self::PRIVATE_API_KEY_PATH) {
            $privateKey = trim($value);

            if ($privateKey === '') {
                return 'Value cannot be empty for this path, the dashboard would lose access to this store.';
            }

            if (strlen($privateKey) > self::PRIVATE_API_KEY_MAX_LENGTH) {
                return 'Value is longer than ' . self::PRIVATE_API_KEY_MAX_LENGTH . ' characters for this path.';
            }

            if (preg_match('/[^\x21-\x7E]/', $privateKey)) {
                return 'Value must be printable ASCII without spaces for this path, it travels in an HTTP header.';
            }
        } elseif (in_array($path, self::JSON_PATHS, true) && $value !== '') {
            json_decode($value);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return 'Value must be valid JSON for this path.';
            }
        }

        return null;
    }

    /**
     * @param string $currentValue
     * @param string $value
     * @return bool
     */
    private function storeAlreadyHasPrivateApiKey(string $currentValue, string $value): bool
    {
        if ($currentValue === '') {
            return false;
        }

        return $currentValue === $value || $this->encryptor->decrypt($currentValue) === $value;
    }

    /**
     * Build error result
     *
     * @param string $path
     * @param string $message
     * @return array
     */
    private function error(string $path, string $message): array
    {
        return ['path' => $path, 'success' => false, 'message' => $message];
    }
}
