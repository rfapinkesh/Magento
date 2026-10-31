<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model;

use IceCube\AiAgent\Model\Provider\ProviderInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Providers registered in di.xml, keyed by the code stored in icecube_aiagent/general/provider.
 */
class ProviderPool
{
    /**
     * @param Config $config
     * @param ProviderInterface[] $providers Injected through di.xml
     */
    public function __construct(
        private Config $config,
        private array $providers = []
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function getActive(): ProviderInterface
    {
        $code = $this->config->getProvider();
        if (!isset($this->providers[$code]) || !$this->providers[$code] instanceof ProviderInterface) {
            throw new LocalizedException(__('The AI provider "%1" is not available.', $code));
        }
        return $this->providers[$code];
    }
}
