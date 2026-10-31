<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

/**
 * Read access to the tool-call audit trail (role = tool rows), with the question that triggered each call.
 */
class AuditLog
{
    public const PAGE_SIZE = 50;

    public function __construct(
        private ResourceConnection $resource
    ) {
    }

    /**
     * @param array $filters admin_user_id, tool_name, from (UTC), to (UTC), search
     * @return array{total:int, rows:array[]}
     */
    public function search(array $filters, int $page = 1): array
    {
        $connection = $this->resource->getConnection();
        $select = $this->select($filters);

        $count = clone $select;
        $count->reset(Select::COLUMNS)->reset(Select::ORDER)->columns(['cnt' => new \Zend_Db_Expr('COUNT(*)')]);
        $total = (int)$connection->fetchOne($count);

        $select->limitPage(max(1, $page), self::PAGE_SIZE);
        return ['total' => $total, 'rows' => $connection->fetchAll($select)];
    }

    /**
     * Yields every matching row, for CSV export, without loading them all at once.
     *
     * @return \Generator<array>
     */
    public function iterate(array $filters, int $batchSize = 500): \Generator
    {
        $connection = $this->resource->getConnection();
        $lastId = PHP_INT_MAX;
        do {
            $select = $this->select($filters)->where('m.message_id < ?', $lastId)->limit($batchSize);
            $rows = $connection->fetchAll($select);
            foreach ($rows as $row) {
                $lastId = (int)$row['message_id'];
                yield $row;
            }
        } while (count($rows) === $batchSize);
    }

    /**
     * @return string[] tool names that appear in the log
     */
    public function getToolNames(): array
    {
        $connection = $this->resource->getConnection();
        return $connection->fetchCol(
            $connection->select()
                ->distinct()
                ->from($this->resource->getTableName(ConversationStore::TABLE_MESSAGE), ['tool_name'])
                ->where('role = ?', ConversationStore::ROLE_TOOL)
                ->order('tool_name ASC')
        );
    }

    /**
     * @return array<int, string> admin user ID => username, for users with chats
     */
    public function getAdminUsers(): array
    {
        $connection = $this->resource->getConnection();
        return $connection->fetchPairs(
            $connection->select()
                ->distinct()
                ->from(['c' => $this->resource->getTableName(ConversationStore::TABLE_CONVERSATION)], [])
                ->join(
                    ['u' => $this->resource->getTableName('admin_user')],
                    'u.user_id = c.admin_user_id',
                    ['u.user_id', 'u.username']
                )
                ->order('u.username ASC')
        );
    }

    private function select(array $filters): Select
    {
        $connection = $this->resource->getConnection();
        $messageTable = $this->resource->getTableName(ConversationStore::TABLE_MESSAGE);

        // The question = the latest user message before this tool row in the same chat.
        $question = $connection->select()
            ->from(['q' => $messageTable], ['content'])
            ->where('q.conversation_id = m.conversation_id')
            ->where('q.role = ?', ConversationStore::ROLE_USER)
            ->where('q.message_id < m.message_id')
            ->order('q.message_id DESC')
            ->limit(1);

        $select = $connection->select()
            ->from(['m' => $messageTable], [
                'message_id', 'conversation_id', 'tool_name', 'tool_args', 'content', 'created_at',
                'question' => new \Zend_Db_Expr('(' . $question->assemble() . ')'),
            ])
            ->join(
                ['c' => $this->resource->getTableName(ConversationStore::TABLE_CONVERSATION)],
                'c.conversation_id = m.conversation_id',
                ['admin_user_id']
            )
            ->joinLeft(
                ['u' => $this->resource->getTableName('admin_user')],
                'u.user_id = c.admin_user_id',
                ['username']
            )
            ->where('m.role = ?', ConversationStore::ROLE_TOOL)
            ->order('m.message_id DESC');

        if (!empty($filters['admin_user_id'])) {
            $select->where('c.admin_user_id = ?', (int)$filters['admin_user_id']);
        }
        if (!empty($filters['tool_name'])) {
            $select->where('m.tool_name = ?', (string)$filters['tool_name']);
        }
        if (!empty($filters['from'])) {
            $select->where('m.created_at >= ?', (string)$filters['from']);
        }
        if (!empty($filters['to'])) {
            $select->where('m.created_at <= ?', (string)$filters['to']);
        }
        if (!empty($filters['search'])) {
            $like = '%' . addcslashes(trim((string)$filters['search']), '%_\\') . '%';
            $select->where('m.tool_args LIKE ? OR m.content LIKE ?', $like);
        }
        return $select;
    }
}
