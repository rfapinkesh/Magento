<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Block\Adminhtml;

use IceCube\AiAgent\Model\Agent;
use IceCube\AiAgent\Model\Config;
use IceCube\AiAgent\Model\ToolRegistry;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Serialize\Serializer\Json;

class Chat extends Template
{
    public function __construct(
        Context $context,
        private Config $config,
        private ToolRegistry $toolRegistry,
        private Json $json,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isReady(): bool
    {
        return $this->config->isEnabled() && $this->config->hasApiKey();
    }

    /**
     * Setup problems shown above the chat, as plain strings.
     *
     * @return string[]
     */
    public function getWarnings(): array
    {
        $warnings = [];
        if (!$this->config->isEnabled()) {
            $warnings[] = (string)__('The AI Assistant is switched off.');
        }
        if (!$this->config->hasApiKey()) {
            $warnings[] = (string)__('No %1 API key is saved yet.', $this->config->getProviderLabel());
        }
        if ($this->config->isEnabled() && !$this->toolRegistry->getAvailableTools()) {
            $warnings[] = (string)__('No lookup tools are enabled for your admin role, so the assistant cannot read store data.');
        }
        return $warnings;
    }

    public function getSettingsUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'icecube_aiagent']);
    }

    public function canEditSettings(): bool
    {
        return $this->_authorization->isAllowed('IceCube_AiAgent::config');
    }

    /**
     * JSON for the data-mage-init attribute (escape it with escapeHtmlAttr in the template).
     */
    public function getMageInit(): string
    {
        return (string)$this->json->serialize(['IceCube_AiAgent/js/chat' => $this->getJsConfig()]);
    }

    public function getJsConfig(): array
    {
        $tools = [];
        foreach ($this->toolRegistry->getAvailableTools() as $name => $tool) {
            $tools[] = ['name' => $name, 'label' => $tool->getLabel()];
        }

        return [
            'sendUrl' => $this->getUrl('icecube_aiagent/chat/send'),
            'historyUrl' => $this->getUrl('icecube_aiagent/chat/history'),
            'deleteUrl' => $this->getUrl('icecube_aiagent/chat/delete'),
            'ready' => $this->isReady(),
            'maxLength' => Agent::MAX_MESSAGE_LENGTH,
            'tools' => $tools,
            'adminOrigin' => $this->getAdminOrigin(),
            'examples' => [
                (string)__('Show me the last 5 orders'),
                (string)__('How did sales do last week compared to the week before?'),
                (string)__('Top 10 best sellers this month'),
                (string)__('How many carts were abandoned in the last 7 days?'),
                (string)__('Which products are low on stock?'),
            ],
        ];
    }

    private function getAdminOrigin(): string
    {
        $url = (string)$this->getUrl('adminhtml');
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return '';
        }
        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
