<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model;

use IceCube\AiAgent\Model\Tool\ToolInterface;
use Magento\Framework\AuthorizationInterface;

/**
 * Holds every registered tool and decides which ones the current admin may use.
 */
class ToolRegistry
{
    /**
     * @var ToolInterface[]
     */
    private array $tools = [];

    /**
     * @param AuthorizationInterface $authorization
     * @param Config $config
     * @param ToolInterface[] $tools Injected through di.xml
     */
    public function __construct(
        private AuthorizationInterface $authorization,
        private Config $config,
        array $tools = []
    ) {
        foreach ($tools as $tool) {
            if ($tool instanceof ToolInterface) {
                $this->tools[$tool->getName()] = $tool;
            }
        }
    }

    /**
     * @return ToolInterface[] keyed by tool name
     */
    public function getAllTools(): array
    {
        return $this->tools;
    }

    /**
     * Tools that are enabled in config AND allowed for the logged-in admin's role.
     *
     * @return ToolInterface[] keyed by tool name
     */
    public function getAvailableTools(): array
    {
        $enabled = $this->config->getEnabledTools();
        $available = [];
        foreach ($this->tools as $name => $tool) {
            if (!in_array($name, $enabled, true)) {
                continue;
            }
            $resource = $tool->getAclResource();
            if ($resource !== null && !$this->authorization->isAllowed($resource)) {
                continue;
            }
            $available[$name] = $tool;
        }
        return $available;
    }
}
