<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\Test\Integration;

/**
 * bradProducts is the sync's product listing: open with the private API key, closed without it.
 */
class BradProductsTest extends IntegrationTestCase
{
    // Pages count from 0, as the sync asks for them (the schema's default of 1 is the second page).
    private const QUERY = 'query { bradProducts(pageSize: 50, currentPage: 0) { total_count items { sku name } } }';

    public function testReturnsTheSeededProductsWithAValidApiKey(): void
    {
        $response = $this->graphQl(self::QUERY, [], ['X-BradSearch-Api-Key' => self::PRIVATE_API_KEY]);

        $this->assertNoGraphQlErrors($response);
        $skus = array_column($response['data']['bradProducts']['items'], 'sku');
        sort($skus);
        $this->assertSame(self::SEEDED_SKUS, $skus);
        $this->assertSame(count(self::SEEDED_SKUS), $response['data']['bradProducts']['total_count']);
    }

    public function testRejectsARequestWithoutApiKey(): void
    {
        $response = $this->graphQl(self::QUERY, [], [], 403);

        $this->assertAuthorizationError($response);
        $this->assertNull($response['data']['bradProducts'] ?? null);
    }

    public function testRejectsAWrongApiKey(): void
    {
        $response = $this->graphQl(self::QUERY, [], ['X-BradSearch-Api-Key' => 'not-the-key'], 403);

        $this->assertAuthorizationError($response);
        $this->assertNull($response['data']['bradProducts'] ?? null);
    }
}
