<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model\Tool;

use IceCube\AiAgent\Model\DataFormatter;
use Magento\Framework\App\ResourceConnection;

/**
 * Active carts with items that have not been ordered, with a value summary.
 */
class GetAbandonedCarts implements ToolInterface
{
    private const ITEMS_PER_CART = 5;

    public function __construct(
        private ResourceConnection $resource,
        private DataFormatter $formatter
    ) {
    }

    public function getName(): string
    {
        return 'get_abandoned_carts';
    }

    public function getLabel(): string
    {
        return 'Abandoned carts';
    }

    public function getDescription(): string
    {
        return 'Carts that still hold items but were never ordered, last changed in a date range and idle for '
            . 'at least a number of hours (default 1). Returns how many there are and their total value '
            . '(base currency), plus the carts themselves with customer, value and top items, highest value '
            . 'first by default. Defaults to the last 7 days.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date_from' => [
                    'type' => 'string',
                    'description' => 'Cart last updated on or after, YYYY-MM-DD, store timezone. Default 7 days ago.',
                ],
                'date_to' => [
                    'type' => 'string',
                    'description' => 'Cart last updated on or before, YYYY-MM-DD, store timezone. Default today.',
                ],
                'idle_hours' => [
                    'type' => 'integer',
                    'description' => 'Only carts not touched for at least this many hours. Default 1.',
                ],
                'customer_type' => [
                    'type' => 'string',
                    'enum' => ['all', 'registered', 'guest'],
                    'description' => 'Registered customers, guests (with a known email) or all. Default all.',
                ],
                'min_value' => ['type' => 'number', 'description' => 'Minimum cart grand total (base currency).'],
                'store_code' => ['type' => 'string', 'description' => 'Store view code to limit results to.'],
                'sort' => [
                    'type' => 'string',
                    'enum' => ['highest_value', 'newest', 'oldest'],
                    'description' => 'Default highest_value.',
                ],
                'limit' => ['type' => 'integer', 'description' => 'How many carts to return, default 10.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Reports::abandoned';
    }

    public function execute(array $args): array
    {
        [$from, $to] = $this->formatter->dateRange(
            (string)($args['date_from'] ?? ''),
            (string)($args['date_to'] ?? ''),
            7
        );
        $idleHours = max(0, (int)($args['idle_hours'] ?? 1));
        $idleBefore = gmdate('Y-m-d H:i:s', time() - $idleHours * 3600);
        if ($idleBefore < $to) {
            $to = $idleBefore;
        }
        $limit = $this->formatter->limit($args['limit'] ?? null, 10);
        $storeId = $this->formatter->resolveStoreId($args['store_code'] ?? null);

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['q' => $this->resource->getTableName('quote')], [])
            ->where('q.is_active = 1')
            ->where('q.items_count > 0')
            // A reserved order ID alone is not an order: failed payments leave one behind.
            ->where(sprintf(
                'NOT EXISTS (SELECT 1 FROM %s so WHERE so.quote_id = q.entity_id)',
                $connection->quoteIdentifier($this->resource->getTableName('sales_order'))
            ))
            ->where('q.updated_at >= ?', $from)
            ->where('q.updated_at <= ?', $to);
        if ($storeId !== null) {
            $select->where('q.store_id = ?', $storeId);
        }
        switch ($args['customer_type'] ?? 'all') {
            case 'registered':
                $select->where('q.customer_id IS NOT NULL');
                break;
            case 'guest':
                $select->where('q.customer_id IS NULL')->where('q.customer_email IS NOT NULL');
                break;
        }
        if (isset($args['min_value']) && is_numeric($args['min_value'])) {
            $select->where('q.base_grand_total >= ?', (float)$args['min_value']);
        }

        $summarySelect = clone $select;
        $summarySelect->columns([
            'carts' => new \Zend_Db_Expr('COUNT(*)'),
            'value' => new \Zend_Db_Expr('SUM(q.base_grand_total)'),
            'items' => new \Zend_Db_Expr('SUM(q.items_qty)'),
            'registered' => new \Zend_Db_Expr('SUM(q.customer_id IS NOT NULL)'),
        ]);
        $summary = (array)$connection->fetchRow($summarySelect);

        $select->columns([
            'entity_id', 'store_id', 'created_at', 'updated_at', 'customer_id', 'customer_email',
            'customer_firstname', 'customer_lastname', 'items_count', 'items_qty', 'base_grand_total',
            'base_currency_code', 'coupon_code',
        ]);
        switch ($args['sort'] ?? 'highest_value') {
            case 'newest':
                $select->order('q.updated_at DESC');
                break;
            case 'oldest':
                $select->order('q.updated_at ASC');
                break;
            default:
                $select->order('q.base_grand_total DESC');
        }
        $rows = $connection->fetchAll($select->limit($limit));
        $items = $this->fetchItems(array_column($rows, 'entity_id'));

        $carts = [];
        foreach ($rows as $row) {
            $name = trim($row['customer_firstname'] . ' ' . $row['customer_lastname']);
            $carts[] = [
                'cart_id' => (int)$row['entity_id'],
                'customer_name' => $name !== '' ? $name : null,
                'customer_email' => $row['customer_email'],
                'is_guest' => !$row['customer_id'],
                'store' => $this->formatter->storeName($row['store_id']),
                'created_at' => $this->formatter->toLocalDate($row['created_at']),
                'last_activity' => $this->formatter->toLocalDate($row['updated_at']),
                'items_count' => (int)$row['items_count'],
                'items_qty' => $this->formatter->qty($row['items_qty']),
                'value' => $this->formatter->money($row['base_grand_total']),
                'currency' => $row['base_currency_code'],
                'coupon_code' => $row['coupon_code'] ?: null,
                'top_items' => $items[(int)$row['entity_id']] ?? [],
                'customer_admin_url' => $row['customer_id']
                    ? $this->formatter->adminUrl('customer/index/edit', ['id' => $row['customer_id']])
                    : null,
            ];
        }

        return [
            'period' => [
                'last_activity_from' => $this->formatter->toLocalDate($from),
                'last_activity_to' => $this->formatter->toLocalDate($to),
                'idle_hours' => $idleHours,
                'timezone' => $this->formatter->getTimezoneName(),
            ],
            'summary' => [
                'carts' => (int)($summary['carts'] ?? 0),
                'total_value' => round((float)($summary['value'] ?? 0), 2),
                'total_items' => $this->formatter->qty($summary['items'] ?? 0) ?? 0.0,
                'registered_customers' => (int)($summary['registered'] ?? 0),
            ],
            'showing' => count($carts),
            'carts' => $carts,
        ];
    }

    /**
     * @param int[] $quoteIds
     * @return array<int, array[]> keyed by quote ID
     */
    private function fetchItems(array $quoteIds): array
    {
        if (!$quoteIds) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('quote_item'), ['quote_id', 'sku', 'name', 'qty', 'base_row_total'])
            ->where('quote_id IN (?)', $quoteIds)
            ->where('parent_item_id IS NULL')
            ->order('base_row_total DESC');

        $items = [];
        foreach ($connection->fetchAll($select) as $row) {
            $quoteId = (int)$row['quote_id'];
            if (count($items[$quoteId] ?? []) >= self::ITEMS_PER_CART) {
                continue;
            }
            $items[$quoteId][] = [
                'sku' => $row['sku'],
                'name' => $row['name'],
                'qty' => $this->formatter->qty($row['qty']),
                'row_total' => $this->formatter->money($row['base_row_total']),
            ];
        }
        return $items;
    }
}
