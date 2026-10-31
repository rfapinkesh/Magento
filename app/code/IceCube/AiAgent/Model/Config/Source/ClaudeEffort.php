<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ClaudeEffort implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'low', 'label' => __('Low (fastest, recommended)')],
            ['value' => 'medium', 'label' => __('Medium')],
            ['value' => 'high', 'label' => __('High')],
            ['value' => 'xhigh', 'label' => __('Extra high')],
            ['value' => 'max', 'label' => __('Max (slowest)')],
        ];
    }
}
