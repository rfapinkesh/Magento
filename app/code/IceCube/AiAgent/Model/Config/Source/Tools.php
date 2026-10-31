<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model\Config\Source;

use IceCube\AiAgent\Model\ToolRegistry;
use Magento\Framework\Data\OptionSourceInterface;

class Tools implements OptionSourceInterface
{
    public function __construct(
        private ToolRegistry $toolRegistry
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->toolRegistry->getAllTools() as $name => $tool) {
            $options[] = ['value' => $name, 'label' => $tool->getLabel() . ' (' . $name . ')'];
        }
        return $options;
    }
}
