<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model\Tool;

use IceCube\AiAgent\Model\DataFormatter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Invoices, shipments (with tracking) and credit memos across all orders, from the sales grid tables.
 */
class SearchSalesDocuments implements ToolInterface
{
    private const TYPES = [
        'invoice' => [
            'table' => 'sales_invoice_grid',
            'acl' => 'Magento_Sales::sales_invoice',
            'route' => 'sales/invoice/view',
            'param' => 'invoice_id',
            'states' => ['1' => 'open', '2' => 'paid', '3' => 'canceled'],
        ],
        'shipment' => [
            'table' => 'sales_shipment_grid',
            'acl' => 'Magento_Sales::shipment',
            'route' => 'adminhtml/order_shipment/view',
            'param' => 'shipment_id',
            'states' => [],
        ],
        'creditmemo' => [
            'table' => 'sales_creditmemo_grid',
            'acl' => 'Magento_Sales::sales_creditmemo',
            'route' => 'sales/creditmemo/view',
            'param' => 'creditmemo_id',
            'states' => ['1' => 'open', '2' => 'refunded', '3' => 'canceled'],
        ],
    ];

    public function __construct(
        private ResourceConnection $resource,
        private AuthorizationInterface $authorization,
        private DataFormatter $formatter
    ) {
    }

    public function getName(): string
    {
        return 'search_sales_documents';
    }

    public function getLabel(): string
    {
        return 'Invoices, shipments and refunds';
    }

    public function getDescription(): string
    {
        return 'Search invoices, shipments (with tracking numbers) or credit memos (refunds) across all orders, '
            . 'by order number, customer, state or date created. Returns a count and, for invoices and credit '
            . 'memos, the total amount (base currency), newest first. Use for questions like "Which orders were '
            . 'refunded this week?" or "Shipments sent yesterday". Defaults to the last 30 days.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'enum' => array_keys(self::TYPES),
                    'description' => 'invoice, shipment or creditmemo (refund).',
                ],
                'order_number' => ['type' => 'string', 'description' => 'Full or partial order number.'],
                'customer' => [
                    'type' => 'string',
                    'description' => 'Full or partial customer name or email.',
                ],
                'state' => [
                    'type' => 'string',
                    'enum' => ['open', 'paid', 'refunded', 'canceled'],
                    'description' => 'Invoices: open, paid, canceled. Credit memos: open, refunded, canceled.',
                ],
                'date_from' => [
                    'type' => 'string',
                    'description' => 'Created on or after, YYYY-MM-DD, store timezone. Default 30 days ago.',
                ],
                'date_to' => [
                    'type' => 'string',
                    'description' => 'Created on or before, YYYY-MM-DD, store timezone. Default today.',
                ],
                'store_code' => ['type' => 'string', 'description' => 'Store view code to limit results to.'],
                'limit' => ['type' => 'integer', 'description' => 'How many documents to return, default 10.'],
            ],
            'required' => ['type'],
            'additionalProperties' => false,
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Sales::sales_order';
    }

    public function execute(array $args): array
    {
        $type = (string)($args['type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            throw new LocalizedException(__('type must be invoice, shipment or creditmemo.'));
        }
        $meta = self::TYPES[$type];
        if (!$this->authorization->isAllowed($meta['acl'])) {
            throw new LocalizedException(__('Your admin role is not allowed to view %1s.', $type));
        }

        $hasOrderNumber = trim((string)($args['order_number'] ?? '')) !== '';
        // An order number alone should find its documents whatever their age.
        [$from, $to] = $hasOrderNumber && empty($args['date_from']) && empty($args['date_to'])
            ? [null, null]
            : $this->formatter->dateRange((string)($args['date_from'] ?? ''), (string)($args['date_to'] ?? ''));
        $limit = $this->formatter->limit($args['limit'] ?? null, 10);
        $storeId = $this->formatter->resolveStoreId($args['store_code'] ?? null);

        $connection = $this->resource->getConnection();
        $select = $connection->select()->from(['g' => $this->resource->getTableName($meta['table'])], []);
        if ($from !== null) {
            $select->where('g.created_at >= ?', $from)->where('g.created_at <= ?', $to);
        }
        if ($hasOrderNumber) {
            $select->where('g.order_increment_id LIKE ?', $this->formatter->likeValue((string)$args['order_number']));
        }
        $customer = trim((string)($args['customer'] ?? ''));
        if ($customer !== '') {
            $like = $this->formatter->likeValue($customer);
            $select->where('g.customer_name LIKE ? OR g.customer_email LIKE ? OR g.billing_name LIKE ?', $like);
        }
        if ($storeId !== null) {
            $select->where('g.store_id = ?', $storeId);
        }
        $state = (string)($args['state'] ?? '');
        if ($state !== '' && $meta['states']) {
            $code = array_search($state, $meta['states'], true);
            if ($code === false) {
                throw new LocalizedException(__('State "%1" does not apply to %2s.', $state, $type));
            }
            $select->where('g.state = ?', $code);
        }

        $summarySelect = clone $select;
        $summaryColumns = ['documents' => new \Zend_Db_Expr('COUNT(*)')];
        if ($type !== 'shipment') {
            $summaryColumns['total_amount'] = new \Zend_Db_Expr('SUM(g.base_grand_total)');
        } else {
            $summaryColumns['total_qty'] = new \Zend_Db_Expr('SUM(g.total_qty)');
        }
        $summary = (array)$connection->fetchRow($summarySelect->columns($summaryColumns));

        $select->columns(['g.*'])->order('g.created_at DESC')->limit($limit);
        $rows = $connection->fetchAll($select);
        $tracks = $type === 'shipment' ? $this->fetchTracks(array_column($rows, 'entity_id')) : [];

        $documents = [];
        foreach ($rows as $row) {
            $document = [
                'number' => $row['increment_id'],
                'order_number' => $row['order_increment_id'],
                'created_at' => $this->formatter->toLocalDate($row['created_at']),
                'customer_name' => $row['customer_name'] ?: ($row['billing_name'] ?? null),
                'customer_email' => $row['customer_email'] ?? null,
                'store' => $this->formatter->storeName($row['store_id']),
            ];
            if ($meta['states']) {
                $document['state'] = $meta['states'][(string)$row['state']] ?? (string)$row['state'];
            }
            if ($type === 'invoice') {
                $document['amount'] = $this->formatter->money($row['base_grand_total']);
                $document['payment_method'] = $row['payment_method'];
            } elseif ($type === 'creditmemo') {
                $document['amount'] = $this->formatter->money($row['base_grand_total']);
                $document['adjustment_refund'] = $this->formatter->money($row['adjustment_positive']);
                $document['adjustment_fee'] = $this->formatter->money($row['adjustment_negative']);
            } else {
                $document['qty'] = $this->formatter->qty($row['total_qty']);
                $document['shipping_method'] = $row['shipping_information'];
                $document['tracking'] = $tracks[(int)$row['entity_id']] ?? [];
            }
            $document['admin_url'] = $this->formatter->adminUrl($meta['route'], [$meta['param'] => $row['entity_id']]);
            $documents[] = $document;
        }

        return [
            'type' => $type,
            'period' => $from === null ? 'all time' : [
                'from' => $this->formatter->toLocalDate($from),
                'to' => $this->formatter->toLocalDate($to),
                'timezone' => $this->formatter->getTimezoneName(),
            ],
            'summary' => [
                'documents' => (int)($summary['documents'] ?? 0),
            ] + ($type !== 'shipment'
                ? ['total_amount' => round((float)($summary['total_amount'] ?? 0), 2)]
                : ['total_qty' => $this->formatter->qty($summary['total_qty'] ?? 0) ?? 0.0]),
            'showing' => count($documents),
            'documents' => $documents,
        ];
    }

    /**
     * @param int[] $shipmentIds
     * @return array<int, array[]>
     */
    private function fetchTracks(array $shipmentIds): array
    {
        if (!$shipmentIds) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('sales_shipment_track'), [
                'parent_id', 'title', 'carrier_code', 'track_number',
            ])
            ->where('parent_id IN (?)', $shipmentIds);
        $tracks = [];
        foreach ($connection->fetchAll($select) as $row) {
            $tracks[(int)$row['parent_id']][] = [
                'carrier' => $row['title'] ?: $row['carrier_code'],
                'number' => $row['track_number'],
            ];
        }
        return $tracks;
    }
}
