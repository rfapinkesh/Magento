<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Controller\Adminhtml\Chat;

use IceCube\AiAgent\Model\ConversationStore;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * POST: deletes one of the current admin's own conversations.
 */
class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'IceCube_AiAgent::chat';

    public function __construct(
        Context $context,
        private JsonFactory $jsonFactory,
        private ConversationStore $store
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $adminUser = $this->_auth->getUser();
        $conversationId = (int)$this->getRequest()->getParam('conversation_id', 0);
        if (!$adminUser || !$adminUser->getId() || !$conversationId) {
            return $result->setData(['success' => false]);
        }
        $this->store->deleteConversation($conversationId, (int)$adminUser->getId());
        return $result->setData(['success' => true]);
    }
}
