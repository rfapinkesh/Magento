<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Controller\Adminhtml\Chat;

use IceCube\AiAgent\Model\ConversationStore;
use IceCube\AiAgent\Model\DataFormatter;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * GET without conversation_id: the admin's recent conversations.
 * GET with conversation_id: that conversation's messages.
 */
class History extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'IceCube_AiAgent::chat';

    public function __construct(
        Context $context,
        private JsonFactory $jsonFactory,
        private ConversationStore $store,
        private DataFormatter $formatter
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $adminUser = $this->_auth->getUser();
        if (!$adminUser || !$adminUser->getId()) {
            return $result->setHttpResponseCode(401)->setData(['success' => false]);
        }
        $adminUserId = (int)$adminUser->getId();
        $conversationId = (int)$this->getRequest()->getParam('conversation_id', 0);

        if (!$conversationId) {
            $conversations = [];
            foreach ($this->store->listConversations($adminUserId) as $row) {
                $conversations[] = [
                    'id' => (int)$row['conversation_id'],
                    'title' => (string)$row['title'],
                    'updated_at' => $this->formatter->toLocalDate($row['updated_at']),
                ];
            }
            return $result->setData(['success' => true, 'conversations' => $conversations]);
        }

        try {
            $conversation = $this->store->getConversation($conversationId, $adminUserId);
        } catch (NoSuchEntityException $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }

        $toolsByReply = $this->store->getToolsUsedByReply($conversationId);
        $messages = [];
        foreach ($this->store->getChatMessages($conversationId) as $row) {
            $id = (int)$row['message_id'];
            $messages[] = [
                'id' => $id,
                'role' => $row['role'],
                'content' => (string)$row['content'],
                'created_at' => $this->formatter->toLocalDate($row['created_at']),
                'tools_used' => $toolsByReply[$id] ?? [],
            ];
        }

        return $result->setData([
            'success' => true,
            'conversation' => [
                'id' => (int)$conversation['conversation_id'],
                'title' => (string)$conversation['title'],
            ],
            'messages' => $messages,
        ]);
    }
}
