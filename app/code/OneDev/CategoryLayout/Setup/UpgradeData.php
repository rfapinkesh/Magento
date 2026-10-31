<?php
namespace OneDev\CategoryLayout\Setup;

use Magento\Framework\Setup\UpgradeDataInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;

class UpgradeData implements UpgradeDataInterface
{
    /**
     * @var CollectionFactory
     */
    protected $categoryCollectionFactory;

    public function __construct(
        CollectionFactory $categoryCollectionFactory
    ) {
        $this->categoryCollectionFactory = $categoryCollectionFactory;
    }

    public function upgrade(
        ModuleDataSetupInterface $setup,
        ModuleContextInterface $context
    ) {
        $setup->startSetup();

        if (version_compare($context->getVersion(), '1.0.1', '<')) {

            $categories = $this->categoryCollectionFactory->create();
            $categories->addAttributeToSelect('page_layout');

            foreach ($categories as $category) {
                $category->setPageLayout('1column');

                // 🔥 IMPORTANT: save only this attribute
                $category->getResource()
                    ->saveAttribute($category, 'page_layout');
            }
        }

        $setup->endSetup();
    }
}