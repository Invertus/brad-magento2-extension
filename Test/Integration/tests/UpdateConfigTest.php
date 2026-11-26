<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\Test\Integration;

/**
 * bradUpdateConfig is how the BradSearch backend configures a store: it writes allowed paths, for the
 * request's store view, only with the private API key.
 */
class UpdateConfigTest extends IntegrationTestCase
{
    private const MUTATION = 'mutation ($items: [BradConfigInput!]!) {
        bradUpdateConfig(items: $items) { path success message }
    }';

    private const PATH = 'bradsearch_search/general/facets_api_url';

    public function testStoresTheValueForTheStoreView(): void
    {
        $value = 'http://wiremock:8080/v1/facets/' . uniqid();

        $response = $this->update(self::PATH, $value, self::PRIVATE_API_KEY);

        $this->assertNoGraphQlErrors($response);
        $this->assertSame(
            [['path' => self::PATH, 'success' => true, 'message' => null]],
            $response['data']['bradUpdateConfig']
        );
        $this->assertSame($value, $this->storedValue(self::PATH));

        // Magento reads the new value back (config cache cleaned), so the same value is no change.
        $again = $this->update(self::PATH, $value, self::PRIVATE_API_KEY);
        $this->assertSame(
            [['path' => self::PATH, 'success' => false, 'message' => 'No change.']],
            $again['data']['bradUpdateConfig']
        );
    }

    public function testRejectsARequestWithoutApiKey(): void
    {
        $before = $this->storedValue(self::PATH);

        $response = $this->update(self::PATH, 'http://wiremock:8080/v1/facets/' . uniqid(), null);

        $this->assertAuthorizationError($response);
        $this->assertSame($before, $this->storedValue(self::PATH));
    }

    public function testRefusesAPathThatIsNotAllowed(): void
    {
        $path = 'bradsearch_search/private_endpoint/api_key';
        $before = $this->storedValue($path);

        $response = $this->update($path, 'taken-over', self::PRIVATE_API_KEY);

        $this->assertNoGraphQlErrors($response);
        $this->assertSame(
            [['path' => $path, 'success' => false, 'message' => 'Path not allowed.']],
            $response['data']['bradUpdateConfig']
        );
        $this->assertSame($before, $this->storedValue($path));
    }

    /**
     * @return array<string, mixed>
     */
    private function update(string $path, string $value, ?string $apiKey): array
    {
        return $this->graphQl(
            self::MUTATION,
            ['items' => [['path' => $path, 'value' => $value]]],
            $apiKey === null ? [] : ['X-BradSearch-Api-Key' => $apiKey],
            $apiKey === null ? 403 : 200
        );
    }

    private function storedValue(string $path): ?string
    {
        $statement = $this->db()->prepare(
            "SELECT value FROM core_config_data WHERE path = ? AND scope = 'stores' AND scope_id = ?"
        );
        $statement->execute([$path, self::DEFAULT_STORE_ID]);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }
}
