<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model\Tool;

use IceCube\AiAgent\Model\DataFormatter;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;

/**
 * Top products by quantity or revenue in a period, or enabled products that did not sell at all.
 */
class GetBestSellers implements ToolInterface
{
    public function __construct(
        private ResourceConnection $resource,
        private EavConfig $eavConfig,
        private DataFormatter $formatter
    ) {
    }

    public function getName(): string
    {
        return 'get_best_sellers';
    }

    public function getLabel(): string
    {
        return 'Best sellers and slow movers';
    }

    public function getDescription(): string
    {
        return 'Ranks products by units sold or revenue (base currency, after discounts) for a date range, '
            . 'from order items of non-canceled orders. With mode "slow_movers" it lists enabled products '
            . 'that had no sales in the range instead. Defaults to the last 30 days, sorted by units sold.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'mode' => [
                    'type' => 'string',
                    'enum' => ['best_sellers', 'slow_movers'],
                    'description' => 'best_sellers (default) or slow_movers (enabled products with no sales).',
                ],
                'date_from' => [
                    'type' => 'string',
                    'description' => 'Start date, YYYY-MM-DD, store timezone. Default 30 days ago.',
                ],
                'date_to' => [
                    'type' => 'string',
                    'description' => 'End date (inclusive), YYYY-MM-DD, store timezone. Default today.',
                ],
                'sort_by' => [
                    'type' => 'string',
                    'enum' => ['qty', 'revenue'],
                    'description' => 'Rank best sellers by units sold (default) or revenue.',
                ],
                'store_code' => ['type' => 'string', 'description' => 'Store view code to limit results to.'],
                'limit' => ['type' => 'integer', 'description' => 'How many products to return, default 10.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Reports::bestsellers';
    }

    public function execute(array $args): array
    {
        [$from, $to] = $this->formatter->dateRange((string)($args['date_from'] ?? ''), (string)($args['date_to'] ?? ''));
        $storeId = $this->formatter->resolveStoreId($args['store_code'] ?? null);
        $limit = $this->formatter->limit($args['limit'] ?? null, 10);
        $period = [
            'from' => $this->formatter->toLocalDate($from),
            'to' => $this->formatter->toLocalDate($to),
            'timezone' => $this->formatter->getTimezoneName(),
        ];

        if (($args['mode'] ?? 'best_sellers') === 'slow_movers') {
            return ['mode' => 'slow_movers', 'period' => $period]
                + $this->slowMovers($from, $to, $storeId, $limit);
        }

        $sortBy = ($args['sort_by'] ?? 'qty') === 'revenue' ? 'revenue' : 'qty';
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['i' => $this->resource->getTableName('sales_order_item')], [
                'product_id' => new \Zend_Db_Expr('MAX(i.product_id)'),
                'sku' => 'i.sku',
                'name' => new \Zend_Db_Expr('MAX(i.name)'),
                'qty' => new \Zend_Db_Expr('SUM(i.qty_ordered)'),
                'qty_refunded' => new \Zend_Db_Expr('SUM(i.qty_refunded)'),
                'revenue' => new \Zend_Db_Expr('SUM(i.base_row_total - COALESCE(i.base_discount_amount, 0))'),
                'orders' => new \Zend_Db_Expr('COUNT(DISTINCT i.order_id)'),
            ])
            ->join(['o' => $this->resource->getTableName('sales_order')], 'o.entity_id = i.order_id', [])
            // Top-level rows only: a configurable's child row would count the same sale twice.
            ->where('i.parent_item_id IS NULL')
            ->where('o.state <> ?', Order::STATE_CANCELED)
            ->where('o.created_at >= ?', $from)
            ->where('o.created_at <= ?', $to)
            ->group('i.sku')
            ->order(($sortBy === 'revenue' ? 'revenue' : 'qty') . ' DESC')
            ->limit($limit);
        if ($storeId !== null) {
            $select->where('o.store_id = ?', $storeId);
        }

        $products = [];
        $rank = 0;
        foreach ($connection->fetchAll($select) as $row) {
            $products[] = [
                'rank' => ++$rank,
                'sku' => $row['sku'],
                'name' => $row['name'],
                'units_sold' => $this->formatter->qty($row['qty']),
                'units_refunded' => $this->formatter->qty($row['qty_refunded']),
                'revenue' => $this->formatter->money($row['revenue']),
                'orders' => (int)$row['orders'],
                'admin_url' => $row['product_id']
                    ? $this->formatter->adminUrl('catalog/product/edit', ['id' => $row['product_id']])
                    : null,
            ];
        }

        return [
            'mode' => 'best_sellers',
            'period' => $period,
            'sorted_by' => $sortBy,
            'showing' => count($products),
            'products' => $products,
        ];
    }

    private function slowMovers(string $from, string $to, ?int $storeId, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $statusAttribute = $this->eavConfig->getAttribute('catalog_product', 'status');
        $nameAttribute = $this->eavConfig->getAttribute('catalog_product', 'name');
        $linkField = $statusAttribute->getEntity()->getLinkField();

        $sold = $connection->select()
            ->from(['i' => $this->resource->getTableName('sales_order_item')], ['i.product_id'])
            ->join(['o' => $this->resource->getTableName('sales_order')], 'o.entity_id = i.order_id', [])
            ->where('o.state <> ?', Order::STATE_CANCELED)
            ->where('o.created_at >= ?', $from)
            ->where('o.created_at <= ?', $to)
            ->where('i.product_id IS NOT NULL');
        if ($storeId !== null) {
            $sold->where('o.store_id = ?', $storeId);
        }

        $select = $connection->select()
            ->from(['e' => $this->resource->getTableName('catalog_product_entity')], [
                'entity_id', 'sku', 'type_id', 'created_at',
            ])
            ->join(
                ['s' => $statusAttribute->getBackendTable()],
                "s.$linkField = e.$linkField AND s.attribute_id = " . (int)$statusAttribute->getId()
                . ' AND s.store_id = 0',
                []
            )
            ->joinLeft(
                ['n' => $nameAttribute->getBackendTable()],
                "n.$linkField = e.$linkField AND n.attribute_id = " . (int)$nameAttribute->getId()
                . ' AND n.store_id = 0',
                ['name' => 'n.value']
            )
            ->where('s.value = ?', ProductStatus::STATUS_ENABLED)
            ->where('e.entity_id NOT IN (?)', new \Zend_Db_Expr($sold->assemble()))
            ->order('e.created_at ASC');

        $countSelect = clone $select;
        $countSelect->reset(\Magento\Framework\DB\Select::COLUMNS)
            ->reset(\Magento\Framework\DB\Select::ORDER)
            ->columns(['cnt' => new \Zend_Db_Expr('COUNT(*)')]);
        $total = (int)$connection->fetchOne($countSelect);

        $products = [];
        foreach ($connection->fetchAll($select->limit($limit)) as $row) {
            $products[] = [
                'sku' => $row['sku'],
                'name' => $row['name'],
                'type' => $row['type_id'],
                'created_at' => $this->formatter->toLocalDate($row['created_at']),
                'admin_url' => $this->formatter->adminUrl('catalog/product/edit', ['id' => $row['entity_id']]),
            ];
        }

        return [
            'total_without_sales' => $total,
            'showing' => count($products),
            'note' => 'Enabled products (any type) with no order items in the period, oldest first.',
            'products' => $products,
        ];
    }
}
