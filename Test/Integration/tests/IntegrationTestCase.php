<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\Test\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Talks to the running stack: Magento's GraphQL endpoint, its database, its cron jobs and WireMock,
 * which stands in for BradSearch (search API and sync webhook).
 */
abstract class IntegrationTestCase extends TestCase
{
    protected const PRIVATE_API_KEY = 'integration-private-key';
    protected const SEARCH_TOKEN = 'integration-search-token';
    protected const WEBHOOK_TOKEN = 'integration-webhook-token';
    protected const SEEDED_SKUS = ['BRAD-IT-ALPHA', 'BRAD-IT-BETA', 'BRAD-IT-GAMMA'];
    protected const DEFAULT_STORE_ID = 1;

    private const MAGENTO_ROOT = '/var/www/html';

    private static ?PDO $db = null;

    /**
     * Send a GraphQL request as a POST, the way a storefront or the BradSearch backend does
     *
     * @param string $query
     * @param array<string, mixed> $variables
     * @param array<string, string> $headers
     * @param int $expectedStatus Magento answers an authorization error with 403
     * @return array<string, mixed> The decoded response
     */
    protected function graphQl(
        string $query,
        array $variables = [],
        array $headers = [],
        int $expectedStatus = 200
    ): array {
        [$status, $body] = $this->http(
            'POST',
            getenv('MAGENTO_URL') . '/graphql',
            ['query' => $query, 'variables' => (object)$variables],
            $headers
        );
        $this->assertSame($expectedStatus, $status, "GraphQL HTTP status, body: $body");

        $response = json_decode($body, true);
        $this->assertIsArray($response, "GraphQL response is not JSON: $body");

        return $response;
    }

    /**
     * @param array<string, mixed> $response
     */
    protected function assertNoGraphQlErrors(array $response): void
    {
        $this->assertArrayNotHasKey('errors', $response, json_encode($response['errors'] ?? null) ?: '');
    }

    /**
     * @param array<string, mixed> $response
     */
    protected function assertAuthorizationError(array $response): void
    {
        $this->assertSame('Invalid or missing BradSearch API key.', $response['errors'][0]['message'] ?? null);
        $this->assertSame('graphql-authorization', $response['errors'][0]['extensions']['category'] ?? null);
    }

    protected function db(): PDO
    {
        if (self::$db === null) {
            self::$db = new PDO('mysql:host=db;dbname=magento', 'magento', 'magento', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        return self::$db;
    }

    protected function productId(string $sku): int
    {
        $statement = $this->db()->prepare('SELECT entity_id FROM catalog_product_entity WHERE sku = ?');
        $statement->execute([$sku]);
        $id = $statement->fetchColumn();
        $this->assertNotFalse($id, "Seeded product $sku not found");

        return (int)$id;
    }

    /**
     * Run a cron job as Magento's cron would, see bin/run-cron-job.php
     */
    protected function runCronJob(string $group, string $jobName): void
    {
        $command = sprintf(
            'cd %s && php %s %s %s 2>&1',
            self::MAGENTO_ROOT,
            escapeshellarg(dirname(__DIR__) . '/bin/run-cron-job.php'),
            escapeshellarg($group),
            escapeshellarg($jobName)
        );
        exec($command, $output, $exitCode);
        $this->assertSame(0, $exitCode, "Cron job $group/$jobName failed:\n" . implode("\n", $output));
    }

    /**
     * Add a WireMock stub
     *
     * @param array<string, mixed> $mapping WireMock stub mapping (request + response)
     */
    protected function stubBradSearch(array $mapping): void
    {
        [$status, $body] = $this->http('POST', getenv('WIREMOCK_URL') . '/__admin/mappings', $mapping);
        $this->assertSame(201, $status, "WireMock stub not created: $body");
    }

    /**
     * Forget the stubs and the requests WireMock has seen
     */
    protected function resetBradSearch(): void
    {
        [$status] = $this->http('POST', getenv('WIREMOCK_URL') . '/__admin/reset', []);
        $this->assertSame(200, $status, 'WireMock reset failed');
    }

    /**
     * Forget the requests WireMock has seen, keep the stubs
     */
    protected function clearBradSearchJournal(): void
    {
        [$status] = $this->http('DELETE', getenv('WIREMOCK_URL') . '/__admin/requests', []);
        $this->assertSame(200, $status, 'WireMock journal reset failed');
    }

    /**
     * The requests WireMock received that match a pattern
     *
     * @param array<string, mixed> $pattern WireMock request pattern
     * @return array<int, array<string, mixed>>
     */
    protected function bradSearchRequests(array $pattern): array
    {
        [$status, $body] = $this->http('POST', getenv('WIREMOCK_URL') . '/__admin/requests/find', $pattern);
        $this->assertSame(200, $status, "WireMock request journal: $body");

        return json_decode($body, true)['requests'];
    }

    /**
     * @param string $method
     * @param string $url
     * @param array<string, mixed> $json
     * @param array<string, string> $headers
     * @return array{0: int, 1: string} status, body
     */
    private function http(string $method, string $url, array $json, array $headers = []): array
    {
        $headerLines = ['Content-Type: application/json'];
        foreach ($headers as $name => $value) {
            $headerLines[] = "$name: $value";
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($json),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $body = curl_exec($curl);
        $this->assertIsString($body, "$method $url failed: " . curl_error($curl));
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return [$status, $body];
    }
}
