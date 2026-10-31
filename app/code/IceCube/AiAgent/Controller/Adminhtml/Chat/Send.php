<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Controller\Adminhtml\Chat;

use IceCube\AiAgent\Model\Agent;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * POST: one question in, one answer out. Form key and ACL are checked by the backend action.
 */
class Send extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'IceCube_AiAgent::chat';

    public function __construct(
        Context $context,
        private JsonFactory $jsonFactory,
        private Agent $agent,
        private LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $adminUser = $this->_auth->getUser();
        if (!$adminUser || !$adminUser->getId()) {
            return $result->setHttpResponseCode(401)->setData([
                'success' => false,
                'error' => (string)__('Your admin session has expired. Please reload the page.'),
            ]);
        }

        // PHP may hit max_execution_time on slow multi-step answers.
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $message = (string)$this->getRequest()->getParam('message', '');
        $conversationId = (int)$this->getRequest()->getParam('conversation_id', 0) ?: null;

        try {
            $answer = $this->agent->ask((int)$adminUser->getId(), $conversationId, $message);
            return $result->setData(['success' => true] + $answer);
        } catch (LocalizedException $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->critical('IceCube AI Agent: ' . $e->getMessage(), ['exception' => $e]);
            return $result->setData([
                'success' => false,
                'error' => (string)__('Something went wrong while answering. The error has been logged.'),
            ]);
        }
    }
}
