<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model\Tool;

use IceCube\AiAgent\Model\DataFormatter;
use IceCube\AiAgent\Model\ProductLabels;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;

class SearchProducts implements ToolInterface
{
    public function __construct(
        private ProductCollectionFactory $productCollectionFactory,
        private ProductLabels $productLabels,
        private DataFormatter $formatter
    ) {
    }

    public function getName(): string
    {
        return 'search_products';
    }

    public function getLabel(): string
    {
        return 'Search products';
    }

    public function getDescription(): string
    {
        return 'Find products by SKU or name text, category (name or ID, includes subcategories), status, type, '
            . 'price range or stock status. Returns SKU, name, status, visibility, price and stock qty '
            . '(default stock). Use get_product_details for one product in full.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Text to match inside the SKU or product name.',
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Category name (partial match) or numeric category ID.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['enabled', 'disabled'],
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => ['simple', 'configurable', 'virtual', 'bundle', 'grouped', 'downloadable'],
                ],
                'price_min' => ['type' => 'number'],
                'price_max' => ['type' => 'number'],
                'stock_status' => [
                    'type' => 'string',
                    'enum' => ['in_stock', 'out_of_stock'],
                ],
                'store_code' => [
                    'type' => 'string',
                    'description' => 'Store view code: only products in that store, with store-level names.',
                ],
                'sort' => [
                    'type' => 'string',
                    'enum' => ['name', 'price_low', 'price_high', 'newest', 'qty_low'],
                    'description' => 'Default newest.',
                ],
                'limit' => ['type' => 'integer', 'description' => 'How many products to return, default 10.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Catalog::products';
    }

    public function execute(array $args): array
    {
        $limit = $this->formatter->limit($args['limit'] ?? null, 10);
        $collection = $this->productCollectionFactory->create();

        $storeId = $this->formatter->resolveStoreId($args['store_code'] ?? null);
        if ($storeId !== null) {
            $collection->setStoreId($storeId)->addStoreFilter($storeId);
        }

        $collection->addAttributeToSelect(['name', 'price', 'special_price', 'status', 'visibility']);
        $this->productLabels->joinStock($collection);

        $query = trim((string)($args['query'] ?? ''));
        if ($query !== '') {
            $like = $this->formatter->likeValue($query);
            $collection->addAttributeToFilter([
                ['attribute' => 'sku', 'like' => $like],
                ['attribute' => 'name', 'like' => $like],
            ]);
        }

        $category = trim((string)($args['category'] ?? ''));
        if ($category !== '') {
            $categoryIds = $this->productLabels->resolveCategoryIds($category);
            if (!$categoryIds) {
                return ['total_found' => 0, 'showing' => 0, 'products' => [],
                    'note' => sprintf('No category matches "%s".', $category)];
            }
            $collection->addCategoriesFilter(['in' => $categoryIds]);
        }

        $status = $args['status'] ?? null;
        if ($status === 'enabled') {
            $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        } elseif ($status === 'disabled') {
            $collection->addAttributeToFilter('status', Status::STATUS_DISABLED);
        }

        if (!empty($args['type'])) {
            $collection->addAttributeToFilter('type_id', (string)$args['type']);
        }
        if (isset($args['price_min']) && is_numeric($args['price_min'])) {
            $collection->addAttributeToFilter('price', ['gteq' => (float)$args['price_min']]);
        }
        if (isset($args['price_max']) && is_numeric($args['price_max'])) {
            $collection->addAttributeToFilter('price', ['lteq' => (float)$args['price_max']]);
        }
        if (($args['stock_status'] ?? null) === 'in_stock') {
            $collection->addFieldToFilter('is_in_stock', 1);
        } elseif (($args['stock_status'] ?? null) === 'out_of_stock') {
            $collection->addFieldToFilter('is_in_stock', 0);
        }

        switch ($args['sort'] ?? 'newest') {
            case 'name':
                $collection->addAttributeToSort('name', 'ASC');
                break;
            case 'price_low':
                $collection->addAttributeToSort('price', 'ASC');
                break;
            case 'price_high':
                $collection->addAttributeToSort('price', 'DESC');
                break;
            case 'qty_low':
                $collection->getSelect()->order('qty ASC');
                break;
            default:
                $collection->addAttributeToSort('created_at', 'DESC');
        }
        $collection->setPageSize($limit)->setCurPage(1);

        $products = [];
        foreach ($collection as $product) {
            $products[] = [
                'sku' => $product->getSku(),
                'name' => $product->getName(),
                'type' => $product->getTypeId(),
                'status' => $this->productLabels->status($product->getStatus()),
                'visibility' => $this->productLabels->visibility($product->getVisibility()),
                'price' => $this->formatter->money($product->getPrice()),
                'special_price' => $this->formatter->money($product->getSpecialPrice()),
                'qty' => $this->formatter->qty($product->getData('qty')),
                'in_stock' => $product->getData('is_in_stock') === null ? null : (bool)$product->getData('is_in_stock'),
                'admin_url' => $this->formatter->adminUrl('catalog/product/edit', ['id' => $product->getId()]),
            ];
        }

        return [
            'total_found' => (int)$collection->getSize(),
            'showing' => count($products),
            'products' => $products,
        ];
    }
}
