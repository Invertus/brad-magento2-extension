# BradSearch Modules

Custom Magento 2 modules for integrating BradSearch API with Magento GraphQL.

## Modules

- **SearchGraphQl**: Core search integration with BradSearch API
- **ProductFeatures**: Enhanced product attributes for search
- **Analytics**: Order event tracking and analytics
- **Autocomplete**: Search autocomplete functionality

## Installation

### Prerequisites

- Magento 2.4.x
- PHP 7.4, 8.1, or 8.2
- Composer 2.x
- GitHub account with access to the repository

### Step 1: Authenticate with GitHub

Since this is a private repository, you need to authenticate Composer with GitHub.

#### Option A: Using a Fine-Grained Personal Access Token (Recommended)

Fine-grained tokens provide read-only access to a single repository, which is more secure.

1. Go to GitHub → Settings → Developer settings → Personal access tokens → **Fine-grained tokens**
2. Click "Generate new token"
3. Give it a name (e.g., "Composer - BradSearch")
4. Set expiration as needed
5. Under "Repository access", select "Only select repositories" and choose `Invertus/brad-magento-extension`
6. Under "Permissions" → "Repository permissions", set "Contents" to **Read-only**
7. Click "Generate token" and copy the token

Configure Composer to use the token (choose one method):

**Global configuration** (applies to all projects):
```bash
composer config --global github-oauth.github.com YOUR_GITHUB_TOKEN
```

**Project-level configuration** (recommended for CI/CD):

Create an `auth.json` file in your Magento root directory:
```json
{
    "github-oauth": {
        "github.com": "YOUR_GITHUB_TOKEN"
    }
}
```

> **Note:** Add `auth.json` to your `.gitignore` to avoid committing credentials.

#### Option B: Using SSH Keys

If you have SSH keys configured with GitHub, Composer will use them automatically when the repository URL uses the SSH format (`git@github.com:...`).

### Step 2: Add the Repository

Add the BradSearch repository to your Magento project's `composer.json`:

```bash
composer config repositories.bradsearch vcs git@github.com:Invertus/brad-magento-extension.git
```

Or manually add to `composer.json`:

```json
{
    "repositories": {
        "bradsearch": {
            "type": "vcs",
            "url": "git@github.com:Invertus/brad-magento-extension.git"
        }
    }
}
```

### Step 3: Install the Package

```bash
composer require bradsearch/magento-extension:^1.0
```

This installs all four BradSearch modules to `vendor/bradsearch/magento-extension/`.

### Step 4: Enable the Modules

```bash
# Check module status
bin/magento module:status | grep BradSearch

# Enable all BradSearch modules
bin/magento module:enable BradSearch_Analytics BradSearch_Autocomplete BradSearch_ProductFeatures BradSearch_SearchGraphQl

# Run setup upgrade
bin/magento setup:upgrade

# Compile DI
bin/magento setup:di:compile

# Deploy static content (production mode)
bin/magento setup:static-content:deploy

# Clear cache
bin/magento cache:clean
```

### Step 5: Configure BradSearch

After installation, configure the modules in Magento Admin:

**Stores → Configuration → BradSearch → Search Settings**

You'll need API credentials from your BradSearch account.

### Updating the Package

```bash
composer update bradsearch/magento-extension
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:clean
```

### Uninstalling

```bash
bin/magento module:disable BradSearch_Analytics BradSearch_Autocomplete BradSearch_ProductFeatures BradSearch_SearchGraphQl
bin/magento setup:upgrade
composer remove bradsearch/magento-extension
```

## Features

- GraphQL search integration
- Real-time product sync via webhooks
- Unified logging to `var/log/bradsearch.log`
- Faceted search support
- Search result ranking from BradSearch API

## Development

### Running Tests

The BradSearch modules include comprehensive unit tests. Tests are located in `Test/Unit/` directories within each module.

#### Prerequisites

Tests require PHPUnit which is included in Magento's dev dependencies. Make sure you have run `composer install` with dev dependencies.

#### Running All BradSearch Tests

```bash
# If using Docker (recommended for this project):
bin/cli bash
cd /var/www/html
vendor/bin/phpunit app/code/BradSearch/SearchGraphQl/Test/Unit/

# Or from host machine if PHPUnit is available:
vendor/bin/phpunit app/code/BradSearch/SearchGraphQl/Test/Unit/
```

#### Running Specific Test Classes

```bash
# Products Plugin Test
vendor/bin/phpunit app/code/BradSearch/SearchGraphQl/Test/Unit/Plugin/CatalogGraphQl/Model/Resolver/ProductsTest.php

# API Client Test
vendor/bin/phpunit app/code/BradSearch/SearchGraphQl/Test/Unit/Model/Api/ClientTest.php

# Response Mapper Test
vendor/bin/phpunit app/code/BradSearch/SearchGraphQl/Test/Unit/Model/Api/ResponseMapperTest.php

# Products Provider Test
vendor/bin/phpunit app/code/BradSearch/SearchGraphQl/Test/Unit/Model/MockData/ProductsProviderTest.php
```

### Logging

All BradSearch modules log to a unified log file: `var/log/bradsearch.log`

Logging uses Magento's PSR-3 LoggerInterface with structured context:

```php
$this->logger->debug('Search query', [
    'search_term' => $searchTerm,
    'page_size' => $pageSize,
    'filters' => $filters,
]);
```

## Configuration

Configure BradSearch in Magento Admin:

**Stores → Configuration → BradSearch → Search Settings**

- **Enabled**: Enable/disable BradSearch integration
- **API URL**: BradSearch search endpoint
- **Facets API URL**: BradSearch facets endpoint
- **API Token**: Authentication token

## Architecture

### Request Flow

1. GraphQL `products` query with `search` parameter
2. `Products` plugin checks if operation is `ProductSearch`
3. If enabled, intercepts and calls `ProductsProvider`
4. `Client` makes HTTP request to BradSearch API
5. `ResponseMapper` transforms API response to Magento GraphQL format
6. Results returned preserving BradSearch ranking

### Sync Flow (Piggyback on ElasticSuite Changelog)

BradSearch piggybacks on ElasticSuite by reading from the same changelog table:

1. **Cron runs every minute**: `BradSearch\SearchGraphQl\Cron\SyncChangedProducts`
2. **Read changelog**: Gets MAX version from `catalogsearch_fulltext_cl` table
3. **Compare versions**: Gets BradSearch's last synced version from `bradsearch_sync_state`
4. **Sync if behind**: If changelog MAX > BradSearch version:
   - Reads changed product IDs from `catalogsearch_fulltext_cl` between versions
   - Sends webhook notification to BradSearch backend
   - Updates BradSearch sync state to match changelog MAX

**Why piggyback?** BradSearch uses the same changelog table that ElasticSuite uses. When products change, MySQL triggers populate this table. Both ElasticSuite and BradSearch read from it independently.

## Troubleshooting

### Search Not Working

1. Check if BradSearch is enabled in admin config
2. Check logs: `tail -f var/log/bradsearch.log`
3. Verify API credentials are correct
4. Test API manually: `curl -H "Authorization: Bearer TOKEN" "API_URL?q=test&token=TOKEN"`

### Tests Failing

1. Ensure you're in Docker container: `bin/cli bash`
2. Check PHPUnit is available: `vendor/bin/phpunit --version`
3. Run verification: `php verify-bradsearch-tests.php`
4. Clear generated code: `rm -rf generated/code generated/metadata`
