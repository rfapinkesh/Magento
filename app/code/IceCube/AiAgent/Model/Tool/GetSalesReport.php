<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model\Tool;

use IceCube\AiAgent\Model\DataFormatter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Sales\Model\Order;

/**
 * Revenue and order KPIs for a period, optionally grouped and compared with the previous period.
 *
 * Figures are in the base currency and exclude canceled orders unless asked otherwise.
 */
class GetSalesReport implements ToolInterface
{
    private const GROUPS = ['none', 'day', 'week', 'month', 'store', 'payment_method', 'status'];
    private const MAX_GROUP_ROWS = 400;

    public function __construct(
        private ResourceConnection $resource,
        private DataFormatter $formatter
    ) {
    }

    public function getName(): string
    {
        return 'get_sales_report';
    }

    public function getLabel(): string
    {
        return 'Sales report';
    }

    public function getDescription(): string
    {
        return 'Sales KPIs for a date range: order count, revenue, average order value, items sold, discounts, '
            . 'shipping, tax and refunds, in the base currency. Can group by day, week, month, store view, '
            . 'payment method or status, and compare with the previous period of the same length. '
            . 'Use for questions like "How were sales last week?" or "Revenue by month this year". '
            . 'Defaults to the last 30 days.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date_from' => [
                    'type' => 'string',
                    'description' => 'Start date, YYYY-MM-DD, store timezone. Default 30 days ago.',
                ],
                'date_to' => [
                    'type' => 'string',
                    'description' => 'End date (inclusive), YYYY-MM-DD, store timezone. Default today.',
                ],
                'group_by' => [
                    'type' => 'string',
                    'enum' => self::GROUPS,
                    'description' => 'How to break the figures down, default none (totals only).',
                ],
                'store_code' => ['type' => 'string', 'description' => 'Store view code to limit results to.'],
                'compare_previous' => [
                    'type' => 'boolean',
                    'description' => 'Also return totals for the previous period of the same length, with % change.',
                ],
                'include_canceled' => [
                    'type' => 'boolean',
                    'description' => 'Count canceled orders too. Default false.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Reports::salesroot_sales';
    }

    public function execute(array $args): array
    {
        [$from, $to] = $this->formatter->dateRange((string)($args['date_from'] ?? ''), (string)($args['date_to'] ?? ''));
        $groupBy = in_array($args['group_by'] ?? 'none', self::GROUPS, true) ? ($args['group_by'] ?? 'none') : 'none';
        $storeId = $this->formatter->resolveStoreId($args['store_code'] ?? null);
        $includeCanceled = !empty($args['include_canceled']);

        $totals = $this->fetchTotals($from, $to, $storeId, $includeCanceled);
        $result = [
            'period' => [
                'from' => $this->formatter->toLocalDate($from),
                'to' => $this->formatter->toLocalDate($to),
                'timezone' => $this->formatter->getTimezoneName(),
            ],
            'currency' => $this->baseCurrency(),
            'canceled_orders_included' => $includeCanceled,
            'totals' => $totals,
        ];

        if (!empty($args['compare_previous'])) {
            $length = strtotime($to) - strtotime($from) + 1;
            $prevTo = gmdate('Y-m-d H:i:s', strtotime($from) - 1);
            $prevFrom = gmdate('Y-m-d H:i:s', strtotime($from) - $length);
            $previous = $this->fetchTotals($prevFrom, $prevTo, $storeId, $includeCanceled);
            $result['previous_period'] = [
                'from' => $this->formatter->toLocalDate($prevFrom),
                'to' => $this->formatter->toLocalDate($prevTo),
                'totals' => $previous,
                'change_percent' => [
                    'orders' => $this->change($totals['orders'], $previous['orders']),
                    'revenue' => $this->change($totals['revenue'], $previous['revenue']),
                    'average_order_value' => $this->change(
                        $totals['average_order_value'],
                        $previous['average_order_value']
                    ),
                ],
            ];
        }

        if ($groupBy !== 'none') {
            $result['group_by'] = $groupBy;
            $result['groups'] = $this->fetchGroups($groupBy, $from, $to, $storeId, $includeCanceled);
        }

        return $result;
    }

    private function baseSelect(string $from, string $to, ?int $storeId, bool $includeCanceled): Select
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['o' => $this->resource->getTableName('sales_order')], [])
            ->where('o.created_at >= ?', $from)
            ->where('o.created_at <= ?', $to);
        if (!$includeCanceled) {
            $select->where('o.state <> ?', Order::STATE_CANCELED);
        }
        if ($storeId !== null) {
            $select->where('o.store_id = ?', $storeId);
        }
        return $select;
    }

    private function metricColumns(): array
    {
        return [
            'orders' => new \Zend_Db_Expr('COUNT(*)'),
            'revenue' => new \Zend_Db_Expr('SUM(o.base_grand_total)'),
            'items_sold' => new \Zend_Db_Expr('SUM(o.total_qty_ordered)'),
            'discounts' => new \Zend_Db_Expr('SUM(o.base_discount_amount)'),
            'shipping' => new \Zend_Db_Expr('SUM(o.base_shipping_amount)'),
            'tax' => new \Zend_Db_Expr('SUM(o.base_tax_amount)'),
            'refunded' => new \Zend_Db_Expr('SUM(COALESCE(o.base_total_refunded, 0))'),
        ];
    }

    private function fetchTotals(string $from, string $to, ?int $storeId, bool $includeCanceled): array
    {
        $select = $this->baseSelect($from, $to, $storeId, $includeCanceled)->columns($this->metricColumns());
        return $this->formatRow((array)$this->resource->getConnection()->fetchRow($select));
    }

    private function fetchGroups(
        string $groupBy,
        string $from,
        string $to,
        ?int $storeId,
        bool $includeCanceled
    ): array {
        $select = $this->baseSelect($from, $to, $storeId, $includeCanceled);
        // Shift UTC to the store timezone so days and months match what admins see.
        $local = sprintf("DATE_ADD(o.created_at, INTERVAL %d SECOND)", $this->utcOffsetSeconds($from));
        switch ($groupBy) {
            case 'day':
                $key = new \Zend_Db_Expr("DATE_FORMAT($local, '%Y-%m-%d')");
                break;
            case 'week':
                $key = new \Zend_Db_Expr("DATE_FORMAT($local, '%x-W%v')");
                break;
            case 'month':
                $key = new \Zend_Db_Expr("DATE_FORMAT($local, '%Y-%m')");
                break;
            case 'store':
                $key = 'o.store_id';
                break;
            case 'payment_method':
                $select->joinLeft(
                    ['p' => $this->resource->getTableName('sales_order_payment')],
                    'p.parent_id = o.entity_id',
                    []
                );
                $key = 'p.method';
                break;
            default:
                $key = 'o.status';
        }
        $select->columns(['group_key' => $key] + $this->metricColumns())
            ->group('group_key')
            ->order('group_key ASC')
            ->limit(self::MAX_GROUP_ROWS);

        $groups = [];
        foreach ($this->resource->getConnection()->fetchAll($select) as $row) {
            $label = (string)$row['group_key'];
            if ($groupBy === 'store') {
                $label = (string)$this->formatter->storeName($row['group_key']);
            }
            $groups[] = ['group' => $label] + $this->formatRow($row);
        }
        return $groups;
    }

    private function formatRow(array $row): array
    {
        $orders = (int)($row['orders'] ?? 0);
        $revenue = (float)($row['revenue'] ?? 0);
        return [
            'orders' => $orders,
            'revenue' => round($revenue, 2),
            'average_order_value' => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
            'items_sold' => $this->formatter->qty($row['items_sold'] ?? 0) ?? 0.0,
            'discounts' => round(abs((float)($row['discounts'] ?? 0)), 2),
            'shipping' => round((float)($row['shipping'] ?? 0), 2),
            'tax' => round((float)($row['tax'] ?? 0), 2),
            'refunded' => round((float)($row['refunded'] ?? 0), 2),
        ];
    }

    private function change(float|int $current, float|int $previous): ?float
    {
        if ((float)$previous === 0.0) {
            return null;
        }
        return round(((float)$current - (float)$previous) / (float)$previous * 100, 1);
    }

    private function utcOffsetSeconds(string $utcDate): int
    {
        $zone = new \DateTimeZone($this->formatter->getTimezoneName());
        return $zone->getOffset(new \DateTime($utcDate, new \DateTimeZone('UTC')));
    }

    private function baseCurrency(): ?string
    {
        $connection = $this->resource->getConnection();
        $value = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('core_config_data'), ['value'])
                ->where('path = ?', 'currency/options/base')
                ->where('scope = ?', 'default')
        );
        return $value !== false ? (string)$value : null;
    }
}
