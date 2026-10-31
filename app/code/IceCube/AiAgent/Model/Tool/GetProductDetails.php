<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model\Tool;

use IceCube\AiAgent\Model\DataFormatter;
use IceCube\AiAgent\Model\ProductLabels;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

class GetProductDetails implements ToolInterface
{
    private const MAX_CHILDREN = 50;

    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private StockRegistryInterface $stockRegistry,
        private StoreManagerInterface $storeManager,
        private ProductLabels $productLabels,
        private DataFormatter $formatter
    ) {
    }

    public function getName(): string
    {
        return 'get_product_details';
    }

    public function getLabel(): string
    {
        return 'Product details';
    }

    public function getDescription(): string
    {
        return 'Full details of one product by exact SKU: status, visibility, prices and special price dates, '
            . 'stock (default stock: qty, in stock, backorders), categories, websites, SEO meta, image count, '
            . 'a short description excerpt, and for configurable products the child SKUs with their stock.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string', 'description' => 'Exact product SKU.'],
                'store_code' => [
                    'type' => 'string',
                    'description' => 'Store view code for store-level values (names, prices). Optional.',
                ],
            ],
            'required' => ['sku'],
            'additionalProperties' => false,
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Catalog::products';
    }

    public function execute(array $args): array
    {
        $sku = trim((string)($args['sku'] ?? ''));
        if ($sku === '') {
            throw new LocalizedException(__('A SKU is required.'));
        }
        $storeId = $this->formatter->resolveStoreId($args['store_code'] ?? null);

        try {
            /** @var Product $product */
            $product = $this->productRepository->get($sku, false, $storeId);
        } catch (NoSuchEntityException $e) {
            throw new NoSuchEntityException(
                __('No product with SKU "%1". Try search_products with part of the SKU or name.', $sku)
            );
        }

        $result = [
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'type' => $product->getTypeId(),
            'status' => $this->productLabels->status($product->getStatus()),
            'visibility' => $this->productLabels->visibility($product->getVisibility()),
            'price' => $this->formatter->money($product->getPrice()),
            'special_price' => $this->formatter->money($product->getSpecialPrice()),
            'special_price_from' => $product->getSpecialFromDate() ?: null,
            'special_price_to' => $product->getSpecialToDate() ?: null,
            'weight' => $product->getWeight() !== null ? (float)$product->getWeight() : null,
            'url_key' => $product->getUrlKey(),
            'created_at' => $this->formatter->toLocalDate($product->getCreatedAt()),
            'updated_at' => $this->formatter->toLocalDate($product->getUpdatedAt()),
            'categories' => $this->productLabels->categoryNames(
                array_map('intval', (array)$product->getCategoryIds()),
                $storeId
            ),
            'websites' => $this->websiteNames((array)$product->getWebsiteIds()),
            'stock' => $this->stock($product),
            'meta_title' => $product->getData('meta_title') ?: null,
            'meta_description' => $product->getData('meta_description') ?: null,
            'image_count' => count((array)$product->getMediaGalleryEntries()),
            'short_description' => $this->formatter->excerpt((string)$product->getData('short_description')),
            'description_excerpt' => $this->formatter->excerpt((string)$product->getData('description')),
            'admin_url' => $this->formatter->adminUrl('catalog/product/edit', ['id' => $product->getId()]),
        ];

        if ($product->getTypeId() === 'configurable') {
            $result['configurable'] = $this->configurableChildren($product);
        }

        return $result;
    }

    private function stock(Product $product): ?array
    {
        try {
            $item = $this->stockRegistry->getStockItem((int)$product->getId());
        } catch (\Exception $e) {
            return null;
        }
        if (!$item || !$item->getItemId()) {
            return null;
        }
        return [
            'qty' => $this->formatter->qty($item->getQty()),
            'in_stock' => (bool)$item->getIsInStock(),
            'manage_stock' => (bool)$item->getManageStock(),
            'backorders' => (int)$item->getBackorders() > 0,
            'notify_below_qty' => $this->formatter->qty($item->getNotifyStockQty()),
            'source' => 'default stock',
        ];
    }

    private function websiteNames(array $websiteIds): array
    {
        $names = [];
        foreach ($websiteIds as $websiteId) {
            try {
                $names[] = (string)$this->storeManager->getWebsite((int)$websiteId)->getName();
            } catch (\Exception $e) {
                $names[] = 'Website #' . $websiteId;
            }
        }
        return $names;
    }

    private function configurableChildren(Product $product): array
    {
        $typeInstance = $product->getTypeInstance();
        $attributes = [];
        if (method_exists($typeInstance, 'getConfigurableAttributesAsArray')) {
            foreach ($typeInstance->getConfigurableAttributesAsArray($product) as $attribute) {
                $attributes[] = (string)($attribute['label'] ?? $attribute['attribute_code'] ?? '');
            }
        }

        $children = [];
        $total = 0;
        if (method_exists($typeInstance, 'getUsedProducts')) {
            foreach ($typeInstance->getUsedProducts($product) as $child) {
                $total++;
                if (count($children) >= self::MAX_CHILDREN) {
                    continue;
                }
                $stock = $this->stock($child);
                $children[] = [
                    'sku' => $child->getSku(),
                    'name' => $child->getName(),
                    'status' => $this->productLabels->status($child->getStatus()),
                    'qty' => $stock['qty'] ?? null,
                    'in_stock' => $stock['in_stock'] ?? null,
                ];
            }
        }

        return [
            'options' => $attributes,
            'children_total' => $total,
            'children' => $children,
        ];
    }
}
