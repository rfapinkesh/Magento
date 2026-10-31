<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Persists conversations and messages (chat history and audit log).
 *
 * All datetimes are written and compared in UTC from PHP so results do not depend
 * on the database server's time zone.
 */
class ConversationStore
{
    public const TABLE_CONVERSATION = 'icecube_aiagent_conversation';
    public const TABLE_MESSAGE = 'icecube_aiagent_message';

    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_TOOL = 'tool';

    public function __construct(
        private ResourceConnection $resource
    ) {
    }

    public function createConversation(int $adminUserId, string $title): int
    {
        $now = $this->now();
        $connection = $this->getConnection();
        $connection->insert($this->table(self::TABLE_CONVERSATION), [
            'admin_user_id' => $adminUserId,
            'title' => $this->truncate($title, 255),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int)$connection->lastInsertId($this->table(self::TABLE_CONVERSATION));
    }

    /**
     * Loads a conversation that belongs to the given admin user.
     *
     * @throws NoSuchEntityException
     */
    public function getConversation(int $conversationId, int $adminUserId): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->table(self::TABLE_CONVERSATION))
            ->where('conversation_id = ?', $conversationId)
            ->where('admin_user_id = ?', $adminUserId);
        $row = $connection->fetchRow($select);
        if (!$row) {
            throw new NoSuchEntityException(__('This conversation does not exist or belongs to another admin user.'));
        }
        return $row;
    }

    public function touchConversation(int $conversationId): void
    {
        $this->getConnection()->update(
            $this->table(self::TABLE_CONVERSATION),
            ['updated_at' => $this->now()],
            ['conversation_id = ?' => $conversationId]
        );
    }

    /**
     * @return array[] newest first
     */
    public function listConversations(int $adminUserId, int $limit = 30): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->table(self::TABLE_CONVERSATION), ['conversation_id', 'title', 'updated_at'])
            ->where('admin_user_id = ?', $adminUserId)
            ->order('updated_at DESC')
            ->limit($limit);
        return $connection->fetchAll($select);
    }

    public function deleteConversation(int $conversationId, int $adminUserId): void
    {
        $this->getConnection()->delete($this->table(self::TABLE_CONVERSATION), [
            'conversation_id = ?' => $conversationId,
            'admin_user_id = ?' => $adminUserId,
        ]);
    }

    public function addMessage(
        int $conversationId,
        string $role,
        string $content,
        ?string $toolName = null,
        ?string $toolArgs = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?string $provider = null,
        ?string $model = null
    ): int {
        $connection = $this->getConnection();
        $connection->insert($this->table(self::TABLE_MESSAGE), [
            'conversation_id' => $conversationId,
            'role' => $role,
            'content' => $content,
            'tool_name' => $toolName,
            'tool_args' => $toolArgs,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'provider' => $provider,
            'model' => $model,
            'created_at' => $this->now(),
        ]);
        return (int)$connection->lastInsertId($this->table(self::TABLE_MESSAGE));
    }

    /**
     * Chat messages (user and assistant only) in chronological order.
     *
     * @param int $limit 0 = all
     * @param int|null $beforeMessageId only messages older than this one
     * @return array[]
     */
    public function getChatMessages(int $conversationId, int $limit = 0, ?int $beforeMessageId = null): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->table(self::TABLE_MESSAGE), ['message_id', 'role', 'content', 'created_at'])
            ->where('conversation_id = ?', $conversationId)
            ->where('role IN (?)', [self::ROLE_USER, self::ROLE_ASSISTANT])
            ->order('message_id DESC');
        if ($beforeMessageId !== null) {
            $select->where('message_id < ?', $beforeMessageId);
        }
        if ($limit > 0) {
            $select->limit($limit);
        }
        return array_reverse($connection->fetchAll($select));
    }

    /**
     * Tool names used per assistant reply, keyed by assistant message ID.
     *
     * Tool rows are written before their assistant reply, so each tool row belongs to
     * the next assistant message after it.
     *
     * @return array<int, string[]>
     */
    public function getToolsUsedByReply(int $conversationId): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->table(self::TABLE_MESSAGE), ['message_id', 'role', 'tool_name'])
            ->where('conversation_id = ?', $conversationId)
            ->where('role IN (?)', [self::ROLE_TOOL, self::ROLE_ASSISTANT])
            ->order('message_id ASC');
        $result = [];
        $pending = [];
        foreach ($connection->fetchAll($select) as $row) {
            if ($row['role'] === self::ROLE_TOOL) {
                $pending[] = (string)$row['tool_name'];
                continue;
            }
            $result[(int)$row['message_id']] = array_values(array_unique($pending));
            $pending = [];
        }
        return $result;
    }

    public function countUserMessagesSince(int $adminUserId, string $sinceUtc): int
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(['m' => $this->table(self::TABLE_MESSAGE)], ['cnt' => new \Magento\Framework\DB\Sql\Expression('COUNT(*)')])
            ->join(
                ['c' => $this->table(self::TABLE_CONVERSATION)],
                'c.conversation_id = m.conversation_id',
                []
            )
            ->where('c.admin_user_id = ?', $adminUserId)
            ->where('m.role = ?', self::ROLE_USER)
            ->where('m.created_at >= ?', $sinceUtc);
        return (int)$connection->fetchOne($select);
    }

    /**
     * Deletes conversations (and their messages, via FK cascade) not updated since the given UTC date.
     */
    public function deleteConversationsOlderThan(string $beforeUtc): int
    {
        return (int)$this->getConnection()->delete(
            $this->table(self::TABLE_CONVERSATION),
            ['updated_at < ?' => $beforeUtc]
        );
    }

    public function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function truncate(string $text, int $length): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (function_exists('mb_strlen') && mb_strlen($text) > $length) {
            return rtrim(mb_substr($text, 0, $length - 3)) . '...';
        }
        if (strlen($text) > $length) {
            return rtrim(substr($text, 0, $length - 3)) . '...';
        }
        return $text;
    }

    private function getConnection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }
}
