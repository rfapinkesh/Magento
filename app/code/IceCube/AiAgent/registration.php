<?php
/**
 * IceCube AI Agent: read-only AI chat assistant for the Magento admin.
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'IceCube_AiAgent', __DIR__);
