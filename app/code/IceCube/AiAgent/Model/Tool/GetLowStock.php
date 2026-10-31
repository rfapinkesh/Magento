<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model\Tool;

use IceCube\AiAgent\Model\DataFormatter;
use IceCube\AiAgent\Model\ProductLabels;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;

class GetLowStock implements ToolInterface
{
    private const STOCK_TYPES = ['simple', 'virtual', 'downloadable'];

    public function __construct(
        private ProductCollectionFactory $productCollectionFactory,
        private ProductLabels $productLabels,
        private DataFormatter $formatter
    ) {
    }

    public function getName(): string
    {
        return 'get_low_stock';
    }

    public function getLabel(): string
    {
        return 'Low stock report';
    }

    public function getDescription(): string
    {
        return 'Products whose stock qty (default stock) is at or below a threshold, lowest first. Only stock-'
            . 'tracked product types (simple, virtual, downloadable). Can be limited to out-of-stock products '
            . 'or to a category. Disabled products are excluded unless include_disabled is true.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'threshold' => [
                    'type' => 'number',
                    'description' => 'Return products with qty at or below this number. Default 5.',
                ],
                'only_out_of_stock' => [
                    'type' => 'boolean',
                    'description' => 'Only products marked out of stock.',
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Category name (partial match) or numeric ID, includes subcategories.',
                ],
                'include_disabled' => ['type' => 'boolean', 'description' => 'Default false.'],
                'limit' => ['type' => 'integer', 'description' => 'Default 20.'],
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
        $limit = $this->formatter->limit($args['limit'] ?? null, 20);
        $threshold = isset($args['threshold']) && is_numeric($args['threshold']) ? (float)$args['threshold'] : 5.0;

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'status']);
        $this->productLabels->joinStock($collection);
        $collection->addAttributeToFilter('type_id', ['in' => self::STOCK_TYPES]);

        if (!empty($args['only_out_of_stock'])) {
            $collection->addFieldToFilter('is_in_stock', 0);
        } else {
            $collection->addFieldToFilter('qty', ['lteq' => $threshold]);
        }

        if (empty($args['include_disabled'])) {
            $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
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

        $collection->getSelect()->order('qty ASC');
        $collection->setPageSize($limit)->setCurPage(1);

        $products = [];
        foreach ($collection as $product) {
            $products[] = [
                'sku' => $product->getSku(),
                'name' => $product->getName(),
                'type' => $product->getTypeId(),
                'qty' => $this->formatter->qty($product->getData('qty')),
                'in_stock' => $product->getData('is_in_stock') === null ? null : (bool)$product->getData('is_in_stock'),
                'status' => $this->productLabels->status($product->getStatus()),
                'admin_url' => $this->formatter->adminUrl('catalog/product/edit', ['id' => $product->getId()]),
            ];
        }

        return [
            'threshold' => $threshold,
            'total_found' => (int)$collection->getSize(),
            'showing' => count($products),
            'products' => $products,
            'note' => 'Quantities come from the default stock. Stores using several inventory sources should '
                . 'confirm salable qty in the product page.',
        ];
    }
}
