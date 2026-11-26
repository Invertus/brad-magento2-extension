<?php
/**
 * Fills BRAD-IT-ALPHA the way a real catalog product is filled, so the bradProducts contract test sees
 * every field of the shared fixture with a value: a category, descriptions and a custom attribute
 * (brad_material, created here and added to the default attribute set). Safe to run again.
 */
declare(strict_types=1);

use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

require '/var/www/html/app/bootstrap.php';

$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode(Area::AREA_ADMINHTML);
$objectManager->get(StoreManagerInterface::class)->setCurrentStore(Store::DEFAULT_STORE_ID);

$eavSetup = $objectManager->get(EavSetupFactory::class)->create(['setup' => $objectManager->get(ModuleDataSetupInterface::class)]);
if (!$eavSetup->getAttributeId(Product::ENTITY, 'brad_material')) {
    $eavSetup->addAttribute(Product::ENTITY, 'brad_material', [
        'type' => 'varchar',
        'label' => 'Material',
        'input' => 'text',
        'required' => false,
        'global' => 1,
        'visible_on_front' => true,
        'searchable' => true,
        'filterable_in_search' => true,
        'user_defined' => true,
        'group' => 'General',
        'attribute_set' => 'Default',
    ]);
    echo "Created attribute brad_material\n";
}

$categories = $objectManager->get(CategoryCollectionFactory::class)->create()
    ->addAttributeToFilter('name', 'Contract Category')
    ->setPageSize(1);
$category = $categories->getFirstItem();
if (!$category->getId()) {
    $category = $objectManager->get(CategoryInterfaceFactory::class)->create()
        ->setName('Contract Category')
        ->setParentId(2)
        ->setIsActive(true)
        ->setUrlKey('contract-category');
    $category = $objectManager->get(CategoryRepositoryInterface::class)->save($category);
    echo "Created category {$category->getId()}\n";
}

$repository = $objectManager->get(ProductRepositoryInterface::class);
$product = $repository->get('BRAD-IT-ALPHA', true, Store::DEFAULT_STORE_ID, true);
$product->setCustomAttribute('brad_material', 'Steel');
$product->setDescription('<p>Brad Integration Alpha, the long description.</p>');
$product->setShortDescription('<p>Brad Integration Alpha.</p>');
$repository->save($product);
$objectManager->get(CategoryLinkManagementInterface::class)->assignProductToCategories('BRAD-IT-ALPHA', [(int)$category->getId()]);
echo "Filled BRAD-IT-ALPHA\n";
