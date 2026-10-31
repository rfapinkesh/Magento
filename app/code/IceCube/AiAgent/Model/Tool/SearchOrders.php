<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model\Tool;

use IceCube\AiAgent\Model\DataFormatter;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

class SearchOrders implements ToolInterface
{
    public function __construct(
        private OrderCollectionFactory $orderCollectionFactory,
        private OrderConfig $orderConfig,
        private DataFormatter $formatter
    ) {
    }

    public function getName(): string
    {
        return 'search_orders';
    }

    public function getLabel(): string
    {
        return 'Search orders';
    }

    public function getDescription(): string
    {
        return 'Find orders by order number, customer email or name, status, date range, store view or order total. '
            . 'Returns a summary row per order, newest first by default. Use get_order_details for items, '
            . 'addresses, invoices and shipments of one order. Omit all filters to list the most recent orders.';
    }

    public function getParameters(): array
    {
        $statuses = [];
        foreach ($this->orderConfig->getStatuses() as $code => $label) {
            $statuses[] = $code . ' (' . $label . ')';
        }

        return [
            'type' => 'object',
            'properties' => [
                'order_number' => [
                    'type' => 'string',
                    'description' => 'Full or partial order increment ID, e.g. 000000123.',
                ],
                'customer_email' => [
                    'type' => 'string',
                    'description' => 'Full or partial customer email.',
                ],
                'customer_name' => [
                    'type' => 'string',
                    'description' => 'Full or partial customer name.',
                ],
                'status' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Order status codes. Available: ' . implode(', ', $statuses) . '.',
                ],
                'date_from' => [
                    'type' => 'string',
                    'description' => 'Placed on or after this date, YYYY-MM-DD or YYYY-MM-DD HH:MM, store timezone.',
                ],
                'date_to' => [
                    'type' => 'string',
                    'description' => 'Placed on or before this date, YYYY-MM-DD or YYYY-MM-DD HH:MM, store timezone.',
                ],
                'store_code' => [
                    'type' => 'string',
                    'description' => 'Store view code to limit results to.',
                ],
                'min_total' => ['type' => 'number', 'description' => 'Minimum grand total (order currency).'],
                'max_total' => ['type' => 'number', 'description' => 'Maximum grand total (order currency).'],
                'sort' => [
                    'type' => 'string',
                    'enum' => ['newest', 'oldest', 'highest_total', 'lowest_total'],
                    'description' => 'Sort order, default newest.',
                ],
                'limit' => ['type' => 'integer', 'description' => 'How many orders to return, default 10.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Sales::sales_order';
    }

    public function execute(array $args): array
    {
        $limit = $this->formatter->limit($args['limit'] ?? null, 10);
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToSelect([
            'entity_id', 'increment_id', 'status', 'state', 'created_at', 'grand_total', 'order_currency_code',
            'customer_email', 'customer_firstname', 'customer_lastname', 'customer_is_guest', 'store_id',
            'total_qty_ordered',
        ]);

        $orderNumber = trim((string)($args['order_number'] ?? ''));
        if ($orderNumber !== '') {
            $collection->addFieldToFilter('increment_id', ['like' => $this->formatter->likeValue($orderNumber)]);
        }

        $email = trim((string)($args['customer_email'] ?? ''));
        if ($email !== '') {
            $condition = filter_var($email, FILTER_VALIDATE_EMAIL)
                ? ['eq' => $email]
                : ['like' => $this->formatter->likeValue($email)];
            $collection->addFieldToFilter('customer_email', $condition);
        }

        $name = trim((string)($args['customer_name'] ?? ''));
        if ($name !== '') {
            $collection->getSelect()->where(
                "CONCAT_WS(' ', main_table.customer_firstname, main_table.customer_lastname) LIKE ?",
                $this->formatter->likeValue($name)
            );
        }

        $statuses = $this->formatter->stringList($args['status'] ?? null);
        if ($statuses) {
            $collection->addFieldToFilter('status', ['in' => $statuses]);
        }

        $from = $this->formatter->toUtcDate((string)($args['date_from'] ?? ''));
        if ($from) {
            $collection->addFieldToFilter('created_at', ['gteq' => $from]);
        }
        $to = $this->formatter->toUtcDate((string)($args['date_to'] ?? ''), true);
        if ($to) {
            $collection->addFieldToFilter('created_at', ['lteq' => $to]);
        }

        $storeId = $this->formatter->resolveStoreId($args['store_code'] ?? null);
        if ($storeId !== null) {
            $collection->addFieldToFilter('store_id', $storeId);
        }

        if (isset($args['min_total']) && is_numeric($args['min_total'])) {
            $collection->addFieldToFilter('grand_total', ['gteq' => (float)$args['min_total']]);
        }
        if (isset($args['max_total']) && is_numeric($args['max_total'])) {
            $collection->addFieldToFilter('grand_total', ['lteq' => (float)$args['max_total']]);
        }

        switch ($args['sort'] ?? 'newest') {
            case 'oldest':
                $collection->setOrder('created_at', 'ASC');
                break;
            case 'highest_total':
                $collection->setOrder('grand_total', 'DESC');
                break;
            case 'lowest_total':
                $collection->setOrder('grand_total', 'ASC');
                break;
            default:
                $collection->setOrder('created_at', 'DESC');
        }
        $collection->setPageSize($limit)->setCurPage(1);

        $orders = [];
        foreach ($collection as $order) {
            $orders[] = [
                'order_number' => $order->getIncrementId(),
                'placed_at' => $this->formatter->toLocalDate($order->getCreatedAt()),
                'status' => $order->getStatus(),
                'status_label' => (string)$this->orderConfig->getStatusLabel($order->getStatus()),
                'customer_name' => trim($order->getCustomerFirstname() . ' ' . $order->getCustomerLastname()),
                'customer_email' => $order->getCustomerEmail(),
                'is_guest' => (bool)$order->getCustomerIsGuest(),
                'store' => $this->formatter->storeName($order->getStoreId()),
                'grand_total' => $this->formatter->money($order->getGrandTotal()),
                'currency' => $order->getOrderCurrencyCode(),
                'items_qty' => $this->formatter->qty($order->getTotalQtyOrdered()),
                'admin_url' => $this->formatter->adminUrl('sales/order/view', ['order_id' => $order->getId()]),
            ];
        }

        return [
            'total_found' => (int)$collection->getSize(),
            'showing' => count($orders),
            'orders' => $orders,
        ];
    }
}
