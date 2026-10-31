<?php
declare(strict_types=1);

namespace IceCube\AiAgent\Model\Config\Source;

use IceCube\AiAgent\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

class Provider implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::PROVIDER_OPENAI, 'label' => __('OpenAI (ChatGPT)')],
            ['value' => Config::PROVIDER_CLAUDE, 'label' => __('Claude (Anthropic)')],
            ['value' => Config::PROVIDER_GROQ, 'label' => __('Groq')],
        ];
    }
}
