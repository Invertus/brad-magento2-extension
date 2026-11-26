<?php
/**
 * Creates the products the integration tests use: three simple products, in stock, on the main website.
 * Skips a SKU that already exists.
 */
declare(strict_types=1);

use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\Area;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

require '/var/www/html/app/bootstrap.php';

$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode(Area::AREA_ADMINHTML);
// Admin store, so the values are saved as defaults and not for one store view.
$objectManager->get(StoreManagerInterface::class)->setCurrentStore(Store::DEFAULT_STORE_ID);

$repository = $objectManager->get(ProductRepositoryInterface::class);
$factory = $objectManager->get(ProductInterfaceFactory::class);

$products = [
    'BRAD-IT-ALPHA' => ['Brad Integration Alpha', 10.00],
    'BRAD-IT-BETA' => ['Brad Integration Beta', 20.00],
    'BRAD-IT-GAMMA' => ['Brad Integration Gamma', 30.00],
];

foreach ($products as $sku => [$name, $price]) {
    try {
        $repository->get($sku);
        continue;
    } catch (NoSuchEntityException $e) {
        // Not there yet: create it below.
    }

    $product = $factory->create();
    $product->setSku($sku)
        ->setName($name)
        ->setUrlKey(strtolower($sku))
        ->setAttributeSetId(4)
        ->setTypeId(Type::TYPE_SIMPLE)
        ->setPrice($price)
        ->setVisibility(Visibility::VISIBILITY_BOTH)
        ->setStatus(Status::STATUS_ENABLED)
        ->setWebsiteIds([1])
        ->setStockData(['qty' => 10, 'is_in_stock' => 1, 'use_config_manage_stock' => 1]);
    $repository->save($product);
    echo "Created $sku\n";
}
