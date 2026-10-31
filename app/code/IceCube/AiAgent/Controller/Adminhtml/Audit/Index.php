<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Controller\Adminhtml\Audit;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'IceCube_AiAgent::audit';

    public function __construct(
        Context $context,
        private PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('IceCube_AiAgent::audit');
        $page->getConfig()->getTitle()->prepend(__('AI Audit Log'));
        return $page;
    }
}
