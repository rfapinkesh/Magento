<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model\Tool;

use IceCube\AiAgent\Model\DataFormatter;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;

class GetCustomer implements ToolInterface
{
    private array $groupNames = [];

    public function __construct(
        private CustomerCollectionFactory $customerCollectionFactory,
        private GroupRepositoryInterface $groupRepository,
        private StoreManagerInterface $storeManager,
        private ResourceConnection $resource,
        private DataFormatter $formatter
    ) {
    }

    public function getName(): string
    {
        return 'get_customer';
    }

    public function getLabel(): string
    {
        return 'Customer lookup';
    }

    public function getDescription(): string
    {
        return 'Look up registered customer accounts by email or name. Returns group, website, account date, '
            . 'order count, lifetime sales (base currency, excluding canceled orders) and last order. '
            . 'Guest buyers have no account: use search_orders with customer_email for them.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'email' => ['type' => 'string', 'description' => 'Full or partial email.'],
                'name' => ['type' => 'string', 'description' => 'Full or partial customer name.'],
                'limit' => ['type' => 'integer', 'description' => 'Default 5.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Customer::manage';
    }

    public function execute(array $args): array
    {
        $email = trim((string)($args['email'] ?? ''));
        $name = trim((string)($args['name'] ?? ''));
        if ($email === '' && $name === '') {
            throw new LocalizedException(__('Give an email or a name to look up.'));
        }
        $limit = $this->formatter->limit($args['limit'] ?? null, 5);

        $collection = $this->customerCollectionFactory->create();
        $collection->addAttributeToSelect(['firstname', 'lastname', 'email', 'group_id', 'website_id', 'created_at']);

        if ($email !== '') {
            $condition = filter_var($email, FILTER_VALIDATE_EMAIL)
                ? ['eq' => $email]
                : ['like' => $this->formatter->likeValue($email)];
            $collection->addAttributeToFilter('email', $condition);
        }
        if ($name !== '') {
            $parts = preg_split('/\s+/', $name, 2) ?: [$name];
            if (count($parts) === 2) {
                $collection->addAttributeToFilter('firstname', ['like' => $this->formatter->likeValue($parts[0])]);
                $collection->addAttributeToFilter('lastname', ['like' => $this->formatter->likeValue($parts[1])]);
            } else {
                $like = $this->formatter->likeValue($name);
                $collection->addAttributeToFilter([
                    ['attribute' => 'firstname', 'like' => $like],
                    ['attribute' => 'lastname', 'like' => $like],
                ]);
            }
        }
        $collection->setOrder('created_at', 'DESC');
        $collection->setPageSize($limit)->setCurPage(1);

        $customers = [];
        foreach ($collection as $customer) {
            $customerId = (int)$customer->getId();
            $customers[] = [
                'customer_id' => $customerId,
                'name' => trim($customer->getFirstname() . ' ' . $customer->getLastname()),
                'email' => $customer->getEmail(),
                'group' => $this->groupName($customer->getGroupId()),
                'website' => $this->websiteName($customer->getWebsiteId()),
                'account_created' => $this->formatter->toLocalDate($customer->getCreatedAt()),
                'orders' => $this->orderStats($customerId),
                'admin_url' => $this->formatter->adminUrl('customer/index/edit', ['id' => $customerId]),
            ];
        }

        return [
            'total_found' => (int)$collection->getSize(),
            'showing' => count($customers),
            'customers' => $customers,
        ];
    }

    private function orderStats(int $customerId): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('sales_order');

        $stats = $connection->fetchRow(
            $connection->select()
                ->from($table, [
                    'order_count' => new \Magento\Framework\DB\Sql\Expression('COUNT(*)'),
                    'lifetime_sales' => new \Magento\Framework\DB\Sql\Expression(
                        'SUM(IF(state <> ' . $connection->quote(Order::STATE_CANCELED)
                        . ', base_grand_total - IFNULL(base_total_refunded, 0), 0))'
                    ),
                ])
                ->where('customer_id = ?', $customerId)
        ) ?: [];

        $last = $connection->fetchRow(
            $connection->select()
                ->from($table, ['increment_id', 'created_at', 'status'])
                ->where('customer_id = ?', $customerId)
                ->order('created_at DESC')
                ->limit(1)
        ) ?: null;

        return [
            'count' => (int)($stats['order_count'] ?? 0),
            'lifetime_sales_base' => $this->formatter->money($stats['lifetime_sales'] ?? 0),
            'last_order' => $last ? [
                'order_number' => $last['increment_id'],
                'placed_at' => $this->formatter->toLocalDate($last['created_at']),
                'status' => $last['status'],
            ] : null,
        ];
    }

    private function groupName($groupId): ?string
    {
        if ($groupId === null || $groupId === '') {
            return null;
        }
        $groupId = (int)$groupId;
        if (!isset($this->groupNames[$groupId])) {
            try {
                $this->groupNames[$groupId] = (string)$this->groupRepository->getById($groupId)->getCode();
            } catch (\Exception $e) {
                $this->groupNames[$groupId] = 'Group #' . $groupId;
            }
        }
        return $this->groupNames[$groupId];
    }

    private function websiteName($websiteId): ?string
    {
        if ($websiteId === null || $websiteId === '') {
            return null;
        }
        try {
            return (string)$this->storeManager->getWebsite((int)$websiteId)->getName();
        } catch (\Exception $e) {
            return 'Website #' . $websiteId;
        }
    }
}
