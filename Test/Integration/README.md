# Integration tests

Run the extension in a real Magento and check it from the outside: GraphQL over HTTP, the database,
the cron jobs, and WireMock in place of BradSearch.

```bash
Test/Integration/run.sh                          # everything
Test/Integration/run.sh --filter SyncCronTest    # PHPUnit arguments pass through
```

Needs only Docker. The first run downloads Magento and installs it (about 5 minutes). Later runs
reuse that install while the stack is up. To stop it and remove everything:

```bash
docker compose -f Test/Integration/docker-compose.yml down -v
```

## The stack

| Service    | What it is |
|------------|------------|
| magento    | Magento Open Source 2.4.8-p5 on PHP 8.3, packages from the Mage-OS mirror (no repo.magento.com keys). The extension is the mounted checkout, installed as a Composer path package. PHP's own web server serves GraphQL on port 8080 inside the container. |
| db         | MariaDB 11.4, data in memory |
| opensearch | OpenSearch 2.19, Magento's search engine |
| wiremock   | Fake BradSearch: the search API (`/v1/search`) and the sync webhook (`/webhook/products`). Tests add stubs and read the requests it received. |

`bin/setup-magento.sh` installs Magento, sets the BradSearch config (keys, WireMock URLs), seeds three
products (`bin/seed-products.php`) and puts every indexer on "Update by Schedule", so the changelogs
the sync reads exist. Each step is skipped when already done.

## The tests

The tests run in the magento container with Magento's PHPUnit.

| Test | Checks |
|------|--------|
| `BradProductsTest` | `bradProducts` returns the seeded products with the private API key, and refuses a missing or wrong key |
| `UpdateConfigTest` | `bradUpdateConfig` stores a value for the store view, refuses a request without the key and a path outside its list |
| `ProductSearchTest` | A `ProductSearch` operation returns the products and order WireMock gives, with the request's correlation id; another operation name is answered by Magento (OpenSearch) and does not call BradSearch |
| `SyncCronTest` | After a product changes, the `bradsearch_sync_changed_products` job (run as Magento's cron runs it, from `crontab.xml`) posts the product id to the webhook once |

Not covered: the ElasticSuite resolver. `di.xml` attaches the same plugin to it when ElasticSuite is
installed; this stack uses Magento's own search.
