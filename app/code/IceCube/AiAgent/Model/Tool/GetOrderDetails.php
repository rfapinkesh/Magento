<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model\Tool;

use IceCube\AiAgent\Model\DataFormatter;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

class GetOrderDetails implements ToolInterface
{
    private const MAX_ITEMS = 100;
    private const MAX_COMMENTS = 10;

    public function __construct(
        private OrderCollectionFactory $orderCollectionFactory,
        private OrderConfig $orderConfig,
        private GroupRepositoryInterface $groupRepository,
        private DataFormatter $formatter
    ) {
    }

    public function getName(): string
    {
        return 'get_order_details';
    }

    public function getLabel(): string
    {
        return 'Order details';
    }

    public function getDescription(): string
    {
        return 'Full details of one order by its exact order number: status, customer, addresses, payment and '
            . 'shipping method, items with ordered/invoiced/shipped/refunded quantities, totals, invoices, '
            . 'shipments with tracking numbers, credit memos and recent status comments.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_number' => [
                    'type' => 'string',
                    'description' => 'Exact order increment ID, e.g. 000000123.',
                ],
            ],
            'required' => ['order_number'],
            'additionalProperties' => false,
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Sales::sales_order';
    }

    public function execute(array $args): array
    {
        $number = trim((string)($args['order_number'] ?? ''));
        if ($number === '') {
            throw new LocalizedException(__('An order number is required.'));
        }

        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('increment_id', $number)->setPageSize(1);
        /** @var Order $order */
        $order = $collection->getFirstItem();
        if (!$order->getId()) {
            throw new NoSuchEntityException(__('No order with number %1. Try search_orders with a partial number.', $number));
        }

        $currency = $order->getOrderCurrencyCode();
        $payment = $order->getPayment();

        return [
            'order_number' => $order->getIncrementId(),
            'placed_at' => $this->formatter->toLocalDate($order->getCreatedAt()),
            'updated_at' => $this->formatter->toLocalDate($order->getUpdatedAt()),
            'status' => $order->getStatus(),
            'status_label' => (string)$this->orderConfig->getStatusLabel($order->getStatus()),
            'state' => $order->getState(),
            'store' => $this->formatter->storeName($order->getStoreId()),
            'currency' => $currency,
            'coupon_code' => $order->getCouponCode(),
            'customer' => [
                'name' => $order->getCustomerName(),
                'email' => $order->getCustomerEmail(),
                'is_guest' => (bool)$order->getCustomerIsGuest(),
                'group' => $this->groupName($order->getCustomerGroupId()),
                'customer_admin_url' => $order->getCustomerId()
                    ? $this->formatter->adminUrl('customer/index/edit', ['id' => $order->getCustomerId()])
                    : null,
            ],
            'billing_address' => $this->address($order->getBillingAddress()),
            'shipping_address' => $order->getIsVirtual() ? null : $this->address($order->getShippingAddress()),
            'payment' => $payment ? [
                'method' => $payment->getMethod(),
                'title' => $this->paymentTitle($payment),
            ] : null,
            'shipping' => $order->getIsVirtual() ? null : [
                'method' => $order->getShippingMethod(),
                'description' => $order->getShippingDescription(),
            ],
            'items' => $this->items($order),
            'totals' => [
                'subtotal' => $this->formatter->money($order->getSubtotal()),
                'discount' => $this->formatter->money($order->getDiscountAmount()),
                'shipping' => $this->formatter->money($order->getShippingAmount()),
                'tax' => $this->formatter->money($order->getTaxAmount()),
                'grand_total' => $this->formatter->money($order->getGrandTotal()),
                'total_paid' => $this->formatter->money($order->getTotalPaid()),
                'total_refunded' => $this->formatter->money($order->getTotalRefunded()),
                'total_due' => $this->formatter->money($order->getTotalDue()),
            ],
            'invoices' => $this->invoices($order),
            'shipments' => $this->shipments($order),
            'credit_memos' => $this->creditMemos($order),
            'recent_comments' => $this->comments($order),
            'admin_url' => $this->formatter->adminUrl('sales/order/view', ['order_id' => $order->getId()]),
        ];
    }

    private function address($address): ?array
    {
        if (!$address instanceof Address) {
            return null;
        }
        $street = $address->getStreet();
        return [
            'name' => trim($address->getFirstname() . ' ' . $address->getLastname()),
            'company' => $address->getCompany(),
            'street' => is_array($street) ? implode(', ', $street) : (string)$street,
            'city' => $address->getCity(),
            'region' => $address->getRegion(),
            'postcode' => $address->getPostcode(),
            'country_id' => $address->getCountryId(),
            'telephone' => $address->getTelephone(),
        ];
    }

    private function paymentTitle($payment): ?string
    {
        $info = $payment->getAdditionalInformation();
        if (is_array($info) && !empty($info['method_title'])) {
            return (string)$info['method_title'];
        }
        try {
            return (string)$payment->getMethodInstance()->getTitle();
        } catch (\Exception $e) {
            return null;
        }
    }

    private function items(Order $order): array
    {
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
            $items[] = [
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'type' => $item->getProductType(),
                'qty_ordered' => $this->formatter->qty($item->getQtyOrdered()),
                'qty_invoiced' => $this->formatter->qty($item->getQtyInvoiced()),
                'qty_shipped' => $this->formatter->qty($item->getQtyShipped()),
                'qty_refunded' => $this->formatter->qty($item->getQtyRefunded()),
                'qty_canceled' => $this->formatter->qty($item->getQtyCanceled()),
                'price' => $this->formatter->money($item->getPrice()),
                'discount' => $this->formatter->money($item->getDiscountAmount()),
                'row_total' => $this->formatter->money($item->getRowTotal()),
            ];
        }
        return $items;
    }

    private function invoices(Order $order): array
    {
        $states = Invoice::getStates();
        $result = [];
        foreach ($order->getInvoiceCollection() as $invoice) {
            $state = $invoice->getState();
            $result[] = [
                'invoice_number' => $invoice->getIncrementId(),
                'created_at' => $this->formatter->toLocalDate($invoice->getCreatedAt()),
                'state' => isset($states[$state]) ? (string)$states[$state] : (string)$state,
                'grand_total' => $this->formatter->money($invoice->getGrandTotal()),
            ];
        }
        return $result;
    }

    private function shipments(Order $order): array
    {
        $result = [];
        foreach ($order->getShipmentsCollection() as $shipment) {
            $tracks = [];
            foreach ($shipment->getAllTracks() as $track) {
                $tracks[] = [
                    'carrier' => $track->getTitle() ?: $track->getCarrierCode(),
                    'tracking_number' => $track->getTrackNumber(),
                ];
            }
            $result[] = [
                'shipment_number' => $shipment->getIncrementId(),
                'created_at' => $this->formatter->toLocalDate($shipment->getCreatedAt()),
                'total_qty' => $this->formatter->qty($shipment->getTotalQty()),
                'tracking' => $tracks,
            ];
        }
        return $result;
    }

    private function creditMemos(Order $order): array
    {
        $result = [];
        foreach ($order->getCreditmemosCollection() as $memo) {
            $result[] = [
                'credit_memo_number' => $memo->getIncrementId(),
                'created_at' => $this->formatter->toLocalDate($memo->getCreatedAt()),
                'grand_total' => $this->formatter->money($memo->getGrandTotal()),
            ];
        }
        return $result;
    }

    private function comments(Order $order): array
    {
        $result = [];
        foreach ($order->getStatusHistoryCollection() as $history) {
            $comment = $this->formatter->excerpt((string)$history->getComment(), 500);
            if ($comment === null) {
                continue;
            }
            $result[] = [
                'created_at' => $this->formatter->toLocalDate($history->getCreatedAt()),
                'status' => $history->getStatus(),
                'comment' => $comment,
                'customer_notified' => (bool)$history->getIsCustomerNotified(),
                'visible_on_storefront' => (bool)$history->getIsVisibleOnFront(),
            ];
            if (count($result) >= self::MAX_COMMENTS) {
                break;
            }
        }
        return $result;
    }

    private function groupName($groupId): ?string
    {
        if ($groupId === null || $groupId === '') {
            return null;
        }
        try {
            return (string)$this->groupRepository->getById((int)$groupId)->getCode();
        } catch (\Exception $e) {
            return 'Group #' . $groupId;
        }
    }
}
