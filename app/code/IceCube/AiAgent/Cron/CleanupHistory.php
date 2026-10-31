<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Cron;

use IceCube\AiAgent\Model\Config;
use IceCube\AiAgent\Model\ConversationStore;
use Psr\Log\LoggerInterface;

/**
 * Deletes chats older than the configured retention period.
 */
class CleanupHistory
{
    public function __construct(
        private Config $config,
        private ConversationStore $store,
        private LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $days = $this->config->getRetentionDays();
        if ($days <= 0) {
            return;
        }
        $before = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $deleted = $this->store->deleteConversationsOlderThan($before);
        if ($deleted > 0) {
            $this->logger->info(sprintf('IceCube AI Agent: deleted %d conversations older than %d days.', $deleted, $days));
        }
    }
}
