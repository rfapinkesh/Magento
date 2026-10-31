<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;

/**
 * Shared product helpers: status/visibility labels, stock joins and category lookups.
 */
class ProductLabels
{
    public const STOCK_TABLE = 'cataloginventory_stock_item';
    public const DEFAULT_STOCK_ID = 1;

    public function __construct(
        private CategoryCollectionFactory $categoryCollectionFactory
    ) {
    }

    public function status($status): string
    {
        return (int)$status === Status::STATUS_ENABLED ? 'Enabled' : 'Disabled';
    }

    public function visibility($visibility): ?string
    {
        if ($visibility === null || $visibility === '') {
            return null;
        }
        $options = Visibility::getOptionArray();
        return isset($options[(int)$visibility]) ? (string)$options[(int)$visibility] : (string)$visibility;
    }

    /**
     * Adds qty and is_in_stock (default stock) to a product collection.
     */
    public function joinStock(ProductCollection $collection): void
    {
        $condition = '{{table}}.stock_id=' . self::DEFAULT_STOCK_ID;
        $collection->joinField('qty', self::STOCK_TABLE, 'qty', 'product_id=entity_id', $condition, 'left');
        $collection->joinField('is_in_stock', self::STOCK_TABLE, 'is_in_stock', 'product_id=entity_id', $condition, 'left');
    }

    /**
     * Category IDs (including children) matching a category ID or a (partial) category name.
     *
     * @return int[]
     */
    public function resolveCategoryIds(string $category): array
    {
        $category = trim($category);
        if ($category === '') {
            return [];
        }
        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect('name');
        if (ctype_digit($category)) {
            $collection->addFieldToFilter('entity_id', (int)$category);
        } else {
            $collection->addAttributeToFilter('name', ['like' => '%' . addcslashes($category, '%_\\') . '%']);
        }
        $collection->setPageSize(50);

        $ids = [];
        foreach ($collection as $item) {
            $ids[] = (int)$item->getId();
            $children = (string)$item->getAllChildren();
            foreach (explode(',', $children) as $childId) {
                if ($childId !== '') {
                    $ids[] = (int)$childId;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param int[] $categoryIds
     * @return string[] category names
     */
    public function categoryNames(array $categoryIds, ?int $storeId = null): array
    {
        if (!$categoryIds) {
            return [];
        }
        $collection = $this->categoryCollectionFactory->create();
        if ($storeId !== null) {
            $collection->setStoreId($storeId);
        }
        $collection->addAttributeToSelect('name')
            ->addFieldToFilter('entity_id', ['in' => $categoryIds])
            ->addFieldToFilter('level', ['gt' => 1]);
        $names = [];
        foreach ($collection as $item) {
            $names[] = (string)$item->getName();
        }
        return $names;
    }
}
