<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

/**
 * Usage figures for the dashboard, read from the chat and audit tables.
 *
 * Periods are passed as UTC datetimes. Cost is an estimate from the per-provider prices in config
 * and is null where no price is set.
 */
class UsageReport
{
    public function __construct(
        private ResourceConnection $resource,
        private Config $config
    ) {
    }

    public function getSummary(string $from, string $to): array
    {
        $connection = $this->resource->getConnection();
        $select = $this->messages($from, $to)->columns([
            'questions' => new \Zend_Db_Expr("SUM(m.role = 'user')"),
            'answers' => new \Zend_Db_Expr("SUM(m.role = 'assistant')"),
            'tool_calls' => new \Zend_Db_Expr("SUM(m.role = 'tool')"),
            'input_tokens' => new \Zend_Db_Expr('SUM(COALESCE(m.input_tokens, 0))'),
            'output_tokens' => new \Zend_Db_Expr('SUM(COALESCE(m.output_tokens, 0))'),
            'admins' => new \Zend_Db_Expr("COUNT(DISTINCT IF(m.role = 'user', c.admin_user_id, NULL))"),
            'chats' => new \Zend_Db_Expr('COUNT(DISTINCT m.conversation_id)'),
        ]);
        $row = (array)$connection->fetchRow($select);
        $cost = $this->estimateCost($this->getByModel($from, $to));

        return [
            'questions' => (int)($row['questions'] ?? 0),
            'answers' => (int)($row['answers'] ?? 0),
            'tool_calls' => (int)($row['tool_calls'] ?? 0),
            'input_tokens' => (int)($row['input_tokens'] ?? 0),
            'output_tokens' => (int)($row['output_tokens'] ?? 0),
            'admins' => (int)($row['admins'] ?? 0),
            'chats' => (int)($row['chats'] ?? 0),
            'cost' => $cost['cost'],
            'cost_complete' => $cost['complete'],
        ];
    }

    /**
     * @return array[] one row per day (store timezone), oldest first
     */
    public function getByDay(string $from, string $to, int $utcOffsetSeconds): array
    {
        $day = new \Zend_Db_Expr(sprintf(
            "DATE_FORMAT(DATE_ADD(m.created_at, INTERVAL %d SECOND), '%%Y-%%m-%%d')",
            $utcOffsetSeconds
        ));
        $select = $this->messages($from, $to)
            ->columns([
                'day' => $day,
                'questions' => new \Zend_Db_Expr("SUM(m.role = 'user')"),
                'tool_calls' => new \Zend_Db_Expr("SUM(m.role = 'tool')"),
                'input_tokens' => new \Zend_Db_Expr('SUM(COALESCE(m.input_tokens, 0))'),
                'output_tokens' => new \Zend_Db_Expr('SUM(COALESCE(m.output_tokens, 0))'),
            ])
            ->group('day')
            ->order('day ASC');
        return $this->resource->getConnection()->fetchAll($select);
    }

    /**
     * @return array[] one row per admin user, most questions first
     */
    public function getByAdmin(string $from, string $to): array
    {
        $select = $this->messages($from, $to)
            ->joinLeft(
                ['u' => $this->resource->getTableName('admin_user')],
                'u.user_id = c.admin_user_id',
                ['username' => 'u.username', 'firstname' => 'u.firstname', 'lastname' => 'u.lastname']
            )
            ->columns([
                'admin_user_id' => 'c.admin_user_id',
                'questions' => new \Zend_Db_Expr("SUM(m.role = 'user')"),
                'chats' => new \Zend_Db_Expr('COUNT(DISTINCT m.conversation_id)'),
                'tool_calls' => new \Zend_Db_Expr("SUM(m.role = 'tool')"),
                'input_tokens' => new \Zend_Db_Expr('SUM(COALESCE(m.input_tokens, 0))'),
                'output_tokens' => new \Zend_Db_Expr('SUM(COALESCE(m.output_tokens, 0))'),
                'last_question' => new \Zend_Db_Expr("MAX(IF(m.role = 'user', m.created_at, NULL))"),
            ])
            ->group('c.admin_user_id')
            ->order('questions DESC');
        return $this->resource->getConnection()->fetchAll($select);
    }

    /**
     * @return array[] one row per tool, most used first
     */
    public function getByTool(string $from, string $to): array
    {
        $select = $this->messages($from, $to)
            ->where('m.role = ?', ConversationStore::ROLE_TOOL)
            ->columns([
                'tool_name' => 'm.tool_name',
                'calls' => new \Zend_Db_Expr('COUNT(*)'),
                'errors' => new \Zend_Db_Expr("SUM(m.content LIKE '{\"error\"%')"),
            ])
            ->group('m.tool_name')
            ->order('calls DESC');
        return $this->resource->getConnection()->fetchAll($select);
    }

    /**
     * @return array[] one row per provider + model, with an estimated cost
     */
    public function getByModel(string $from, string $to): array
    {
        $select = $this->messages($from, $to)
            ->where('m.role = ?', ConversationStore::ROLE_ASSISTANT)
            ->columns([
                'provider' => 'm.provider',
                'model' => 'm.model',
                'answers' => new \Zend_Db_Expr('COUNT(*)'),
                'input_tokens' => new \Zend_Db_Expr('SUM(COALESCE(m.input_tokens, 0))'),
                'output_tokens' => new \Zend_Db_Expr('SUM(COALESCE(m.output_tokens, 0))'),
            ])
            ->group(['m.provider', 'm.model'])
            ->order('answers DESC');

        $rows = [];
        foreach ($this->resource->getConnection()->fetchAll($select) as $row) {
            $prices = $row['provider'] ? $this->config->getPrices((string)$row['provider']) : null;
            $row['cost'] = $prices === null ? null : round(
                (int)$row['input_tokens'] / 1000000 * $prices['input']
                + (int)$row['output_tokens'] / 1000000 * $prices['output'],
                4
            );
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param array[] $byModel
     * @return array{cost: ?float, complete: bool}
     */
    private function estimateCost(array $byModel): array
    {
        $cost = null;
        $complete = true;
        foreach ($byModel as $row) {
            if ($row['cost'] === null) {
                $complete = false;
                continue;
            }
            $cost = ($cost ?? 0.0) + $row['cost'];
        }
        return ['cost' => $cost === null ? null : round($cost, 2), 'complete' => $complete];
    }

    private function messages(string $from, string $to): Select
    {
        return $this->resource->getConnection()->select()
            ->from(['m' => $this->resource->getTableName(ConversationStore::TABLE_MESSAGE)], [])
            ->join(
                ['c' => $this->resource->getTableName(ConversationStore::TABLE_CONVERSATION)],
                'c.conversation_id = m.conversation_id',
                []
            )
            ->where('m.created_at >= ?', $from)
            ->where('m.created_at <= ?', $to);
    }
}
